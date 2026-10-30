<?php

namespace Tests\Feature;

use App\Models\CapaCosto;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\TipoAfectacionIgv;
use App\Models\UnidadMedida;
use App\Services\ImagenProductoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class ProductoTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    private function payload(array $extra = []): array
    {
        $unidad = UnidadMedida::query()->value('codigo');

        return [
            'codigo_interno' => 'IMG-'.random_int(100000, 999999),
            'nombre' => 'Producto con foto',
            'categoria_id' => null,
            'marca_id' => null,
            'unidad_base_codigo' => $unidad,
            'tipo_afectacion_codigo' => TipoAfectacionIgv::where('afecto', true)->value('codigo'),
            'permite_fraccion' => false,
            'controla_lote' => false,
            'controla_stock' => true,
            'stock_minimo' => 0,
            'activo' => true,
            'presentaciones' => [[
                'id' => null,
                'nombre' => 'Unidad',
                'unidad_codigo' => $unidad,
                'factor_conversion' => 1,
                'precio_venta' => 5,
                'precio_mayorista' => null,
                'cantidad_mayorista' => null,
                'codigo_barras' => null,
                'es_default' => true,
            ]],
            ...$extra,
        ];
    }

    public function test_el_codigo_interno_se_asigna_solo_y_es_correlativo(): void
    {
        // lo que envie el formulario al crear se ignora: el sistema asigna P0001, P0002...
        $this->actingAs($this->admin)->post('/productos', $this->payload(['codigo_interno' => 'LO-QUE-SEA']))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'P0001'));
        $this->actingAs($this->admin)->post('/productos', $this->payload(['codigo_interno' => null, 'nombre' => 'Segundo']))
            ->assertSessionHas('success');

        $codigos = Producto::where('empresa_id', $this->empresa->id)->orderBy('codigo_interno')->pluck('codigo_interno')->all();
        $this->assertContains('P0001', $codigos);
        $this->assertContains('P0002', $codigos);

        // los eliminados no liberan su numero y los codigos manuales antiguos no interfieren
        Producto::where('codigo_interno', 'P0002')->where('empresa_id', $this->empresa->id)->first()->delete();
        $this->assertSame('P0003', Producto::siguienteCodigo($this->empresa->id));

        // la pantalla muestra el proximo codigo
        $this->actingAs($this->admin)->get('/productos')
            ->assertInertia(fn ($p) => $p->where('catalogos.siguienteCodigo', 'P0003'));

        // al editar el codigo sigue siendo editable
        $producto = Producto::where('codigo_interno', 'P0001')->where('empresa_id', $this->empresa->id)->firstOrFail();
        $pres = $producto->presentaciones()->first();
        $payload = $this->payload(['codigo_interno' => 'CABLE-UTP']);
        $payload['presentaciones'][0]['id'] = $pres->id;
        $this->actingAs($this->admin)->put("/productos/{$producto->id}", $payload)->assertSessionHas('success');
        $this->assertSame('CABLE-UTP', $producto->fresh()->codigo_interno);
    }

    public function test_crear_producto_con_imagen_y_luego_quitarla(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post('/productos', $this->payload([
            'imagen' => UploadedFile::fake()->image('foto.png', 300, 300),
        ]))->assertSessionHas('success');

        $producto = Producto::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertStringStartsWith('/storage/productos/', $producto->imagen_url);
        Storage::disk('public')->assertExists(substr($producto->imagen_url, strlen('/storage/')));

        // la miniatura de las tarjetas de la tienda se crea sola: 400x300
        $this->assertNotNull($producto->imagen_miniatura);
        $miniatura = substr($producto->imagen_miniatura, strlen('/storage/'));
        [$ancho, $alto] = getimagesizefromstring(Storage::disk('public')->get($miniatura));
        $this->assertSame([400, 300], [$ancho, $alto]);

        // quitar la imagen: el campo queda vacio y el archivo se borra (también la miniatura)
        $archivo = substr($producto->imagen_url, strlen('/storage/'));
        $this->actingAs($this->admin)->put("/productos/{$producto->id}", $this->payload([
            'codigo_interno' => $producto->codigo_interno,
            'imagen_eliminar' => true,
        ]))->assertSessionHas('success');

        $this->assertNull($producto->fresh()->imagen_url);
        $this->assertNull($producto->fresh()->imagen_miniatura);
        Storage::disk('public')->assertMissing($archivo);
        Storage::disk('public')->assertMissing($miniatura);
    }

    public function test_las_fotos_se_ajustan_a_4_3_sin_recortarse(): void
    {
        Storage::fake('public');

        // una foto muy alta (botella) de 600x2400
        $this->actingAs($this->admin)->post('/productos', $this->payload([
            'imagen' => UploadedFile::fake()->image('botella.jpg', 600, 2400),
        ]))->assertSessionHas('success');

        $producto = Producto::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertStringEndsWith('.webp', $producto->imagen_url);
        [$ancho, $alto] = getimagesizefromstring(Storage::disk('public')->get(substr($producto->imagen_url, strlen('/storage/'))));
        $this->assertSame([800, 600], [$ancho, $alto]);
    }

    public function test_optimizar_respeta_la_proporcion_y_no_agranda_fotos_chicas(): void
    {
        $servicio = app(ImagenProductoService::class);
        $medidas = function (int $w, int $h) use ($servicio): array {
            $img = imagecreatetruecolor($w, $h);
            ob_start();
            imagepng($img);
            [$binario] = $servicio->optimizar(ob_get_clean());

            return array_slice(getimagesizefromstring($binario), 0, 2);
        };

        $this->assertSame([800, 600], $medidas(3000, 300));   // muy ancha
        $this->assertSame([800, 600], $medidas(4000, 3000));  // foto de celular grande
        $this->assertSame([200, 150], $medidas(200, 150));    // chica: no se agranda
        $this->assertSame([400, 300], $medidas(300, 300));    // cuadrada chica: margen a los lados
    }

    public function test_el_comando_optimiza_las_fotos_ya_subidas(): void
    {
        Storage::fake('public');
        $img = imagecreatetruecolor(500, 1500);
        ob_start();
        imagepng($img);
        Storage::disk('public')->put('productos/vieja.png', ob_get_clean());

        $producto = $this->crearProducto();
        $producto->update(['imagen_url' => '/storage/productos/vieja.png']);

        $this->artisan('productos:optimizar-imagenes')->assertSuccessful();

        $nueva = $producto->fresh()->imagen_url;
        $this->assertStringEndsWith('.webp', $nueva);
        Storage::disk('public')->assertMissing('productos/vieja.png');
        $this->assertSame([800, 600], array_slice(getimagesizefromstring(Storage::disk('public')->get(substr($nueva, strlen('/storage/')))), 0, 2));

        // idempotente: la segunda vez no toca nada
        $this->artisan('productos:optimizar-imagenes')->assertSuccessful();
        $this->assertSame($nueva, $producto->fresh()->imagen_url);
    }

    public function test_el_comando_renombra_presentaciones_que_repiten_el_nombre_del_producto(): void
    {
        $producto = $this->crearProducto(); // presentación "Unidad" por defecto
        $producto->presentaciones()->first()->update(['nombre' => $producto->nombre]);
        $this->agregarPresentacion($producto, $producto->nombre.' ', 12, 100.0); // caja x12 mal nombrada
        $this->agregarPresentacion($producto, 'Six pack', 6, 50.0); // nombre propio: se respeta

        $this->artisan('productos:normalizar-presentaciones')->expectsOutputToContain('renombradas: 2')->assertSuccessful();

        $nombres = $producto->presentaciones()->orderBy('factor_conversion')->pluck('nombre')->all();
        $this->assertSame(['Unidad', 'Six pack', 'Unidad x12'], $nombres);

        // idempotente
        $this->artisan('productos:normalizar-presentaciones')->expectsOutputToContain('renombradas: 0')->assertSuccessful();
    }

    public function test_las_unidades_nuevas_sirven_para_nombrar_presentaciones(): void
    {
        $this->assertSame(['BG', 'BO', 'DZN', 'PK'], UnidadMedida::whereIn('codigo', ['PK', 'DZN', 'BG', 'BO'])->orderBy('codigo')->pluck('codigo')->all());
        // catalogo 03 de SUNAT ampliado (SQL 012)
        $this->assertSame(24, UnidadMedida::whereIn('codigo', ['GRM', 'MLT', 'TNE', 'CMT', 'INH', 'FOT', 'MTK', 'MTQ', 'PR', 'CEN', 'MIL', 'BJ', 'CA', 'BLL', 'CY', 'TU', 'RO', 'EV', 'BE', 'SET', 'KT', 'ZZ', 'HUR', 'DAY'])->count());
    }

    public function test_rechaza_archivos_que_no_son_imagen(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post('/productos', $this->payload([
            'imagen' => UploadedFile::fake()->create('archivo.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasErrors('imagen');
    }

    public function test_al_crear_con_stock_inicial_entra_una_capa_con_su_costo(): void
    {
        $this->actingAs($this->admin)->post('/productos', $this->payload(['nombre' => 'Con stock', 'stock_inicial' => 24, 'precio_compra' => 3.5]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'stock inicial de 24.'));

        $producto = Producto::where('empresa_id', $this->empresa->id)->where('nombre', 'Con stock')->firstOrFail();
        $this->assertSame(24.0, $this->stockDe($producto));

        $capa = CapaCosto::where('producto_id', $producto->id)->firstOrFail();
        $this->assertEquals(24, (float) $capa->cantidad_restante);
        $this->assertEquals(3.5, (float) $capa->costo_unitario);
        $this->assertSame($this->sucursal->id, $capa->sucursal_id);

        $movimiento = MovimientoInventario::where('producto_id', $producto->id)->where('tipo', 'ajuste')->firstOrFail();
        $this->assertEquals(24, (float) $movimiento->cantidad);
        $this->assertSame($this->admin->id, $movimiento->usuario_id);

        // sin stock inicial no se abre ninguna capa ni movimiento
        $this->actingAs($this->admin)->post('/productos', $this->payload(['nombre' => 'Sin stock', 'stock_inicial' => null, 'precio_compra' => null]))
            ->assertSessionHasNoErrors();
        $sinStock = Producto::where('empresa_id', $this->empresa->id)->where('nombre', 'Sin stock')->firstOrFail();
        $this->assertSame(0, CapaCosto::where('producto_id', $sinStock->id)->count());
        $this->assertSame(0, MovimientoInventario::where('producto_id', $sinStock->id)->count());
    }

    public function test_el_stock_inicial_exige_precio_de_compra_y_no_aplica_con_lote(): void
    {
        // sin costo quedaria una capa a costo 0 que infla el margen
        $this->actingAs($this->admin)->post('/productos', $this->payload(['stock_inicial' => 5]))
            ->assertSessionHasErrors('precio_compra');

        // con lote hace falta numero y vencimiento: va por ajuste de stock
        $this->actingAs($this->admin)->post('/productos', $this->payload(['controla_lote' => true, 'stock_inicial' => 5, 'precio_compra' => 1]))
            ->assertSessionHasErrors('stock_inicial');

        // fraccion en un producto que no la permite
        $this->actingAs($this->admin)->post('/productos', $this->payload(['stock_inicial' => 2.5, 'precio_compra' => 1]))
            ->assertSessionHasErrors('stock_inicial');

        $this->assertSame(0, Producto::where('empresa_id', $this->empresa->id)->count());

        // en edicion el campo se ignora: no vuelve a cargar stock
        $this->actingAs($this->admin)->post('/productos', $this->payload(['stock_inicial' => 10, 'precio_compra' => 2]))->assertSessionHasNoErrors();
        $producto = Producto::where('empresa_id', $this->empresa->id)->firstOrFail();
        $payload = $this->payload(['codigo_interno' => $producto->codigo_interno, 'stock_inicial' => 99, 'precio_compra' => 2]);
        $payload['presentaciones'][0]['id'] = $producto->presentaciones()->first()->id;
        $this->actingAs($this->admin)->put("/productos/{$producto->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame(10.0, $this->stockDe($producto));
        $this->assertSame(1, CapaCosto::where('producto_id', $producto->id)->count());
    }
}
