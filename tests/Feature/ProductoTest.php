<?php

namespace Tests\Feature;

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

        // quitar la imagen: el campo queda vacio y el archivo se borra
        $archivo = substr($producto->imagen_url, strlen('/storage/'));
        $this->actingAs($this->admin)->put("/productos/{$producto->id}", $this->payload([
            'codigo_interno' => $producto->codigo_interno,
            'imagen_eliminar' => true,
        ]))->assertSessionHas('success');

        $this->assertNull($producto->fresh()->imagen_url);
        Storage::disk('public')->assertMissing($archivo);
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

    public function test_rechaza_archivos_que_no_son_imagen(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post('/productos', $this->payload([
            'imagen' => UploadedFile::fake()->create('archivo.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasErrors('imagen');
    }
}
