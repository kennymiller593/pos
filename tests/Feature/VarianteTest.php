<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\CapaCosto;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\ProductoGrupo;
use App\Models\Rubro;
use App\Models\Stock;
use App\Models\Sucursal;
use App\Models\TipoAfectacionIgv;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Services\SuscripcionService;
use App\Services\VarianteService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class VarianteTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->empresa->update(['variantes_activo' => true]);
    }

    /** Polo básico en Talla S/M/L y Color Rojo/Azul: 6 combinaciones a S/ 35. */
    private function datos(array $extra = []): array
    {
        return [
            'nombre' => 'Polo básico',
            'categoria_id' => null,
            'marca_id' => null,
            'unidad_base_codigo' => 'NIU',
            'tipo_afectacion_codigo' => TipoAfectacionIgv::where('afecto', true)->value('codigo'),
            'precio_base' => 35,
            'costo' => null,
            'atributos' => [
                ['nombre' => 'Talla', 'valores' => ['S', 'M', 'L']],
                ['nombre' => 'Color', 'valores' => ['Rojo', 'Azul']],
            ],
            'variantes' => [],
            ...$extra,
        ];
    }

    private function crearModelo(array $extra = []): ProductoGrupo
    {
        $this->actingAs($this->admin)->post('/productos/modelos', $this->datos($extra))->assertSessionHasNoErrors()->assertRedirect();

        return ProductoGrupo::where('empresa_id', $this->empresa->id)->latest('creado_en')->firstOrFail();
    }

    private function variante(ProductoGrupo $grupo, string ...$valores): ?Producto
    {
        return $grupo->variantes()->with('presentaciones')->get()
            ->first(fn (Producto $p) => VarianteService::valoresDe($p, $grupo->atributos) === $valores);
    }

    public function test_generar_modelo_crea_un_producto_por_combinacion_con_presentacion_y_codigo_de_barras(): void
    {
        $this->actingAs($this->admin)->get('/productos/modelos/crear')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Productos/FormularioVariantes')
            ->where('modelo', null)
            ->has('catalogos.unidades'));

        $grupo = $this->crearModelo(['variantes' => [
            ['valores' => ['M', 'Rojo'], 'precio_venta' => 39.9, 'codigo_barras' => '7750000000017'],
            ['valores' => ['L', 'Azul'], 'omitir' => true],
        ]]);

        $this->assertSame('Polo básico', $grupo->nombre);
        $this->assertSame(['Talla', 'Color'], array_column($grupo->atributos, 'nombre'));

        // 6 combinaciones menos la omitida, en el orden en que se escribieron los valores (no alfabético)
        $variantes = $grupo->variantes()->with('presentaciones')->get();
        $this->assertSame(
            ['Polo básico S Rojo', 'Polo básico S Azul', 'Polo básico M Rojo', 'Polo básico M Azul', 'Polo básico L Rojo'],
            $variantes->pluck('nombre')->all(),
        );
        $this->assertCount(5, $variantes->pluck('codigo_interno')->unique());
        $variantes->each(fn (Producto $p) => $this->assertMatchesRegularExpression('/^P\d{4,}$/', $p->codigo_interno));

        $mRojo = $this->variante($grupo, 'M', 'Rojo');
        $this->assertEquals(['Talla' => 'M', 'Color' => 'Rojo'], $mRojo->atributos);
        $this->assertTrue($mRojo->controla_stock);
        $this->assertTrue($mRojo->activo);
        $this->assertSame($this->empresa->id, $mRojo->empresa_id);
        $this->assertCount(1, $mRojo->presentaciones);
        $presentacion = $mRojo->presentaciones->first();
        $this->assertTrue((bool) $presentacion->es_default);
        $this->assertEquals(1, (float) $presentacion->factor_conversion);
        $this->assertEquals(39.9, (float) $presentacion->precio_venta);
        $this->assertSame('7750000000017', $presentacion->codigo_barras);
        $this->assertSame(UnidadMedida::where('codigo', 'NIU')->value('nombre'), $presentacion->nombre);

        // las demás toman el precio base y no llevan código de barras
        $sAzul = $this->variante($grupo, 'S', 'Azul')->presentaciones->first();
        $this->assertEquals(35, (float) $sAzul->precio_venta);
        $this->assertNull($sAzul->codigo_barras);

        // la lista de productos las muestra con su modelo y se puede filtrar por él
        $suelto = $this->crearProducto();
        $this->actingAs($this->admin)->get('/productos?grupo_id='.$grupo->id)->assertInertia(fn (Assert $p) => $p
            ->where('productos.total', 5)
            ->where('productos.data.0.nombre', 'Polo básico S Rojo')
            ->where('productos.data.0.grupo.nombre', 'Polo básico')
            ->where('filtros.grupo_id', $grupo->id)
            ->has('catalogos.modelos', 1));
        $this->actingAs($this->admin)->get('/productos?buscar='.urlencode($suelto->nombre))->assertInertia(fn (Assert $p) => $p
            ->where('productos.total', 1)
            ->where('productos.data.0.grupo', null));
    }

    public function test_valida_atributos_combinaciones_precio_y_codigos_de_barras(): void
    {
        $post = fn (array $extra) => $this->actingAs($this->admin)->post('/productos/modelos', $this->datos($extra));

        $post(['atributos' => []])->assertSessionHasErrors('atributos');
        $post(['atributos' => [
            ['nombre' => 'A', 'valores' => ['1']], ['nombre' => 'B', 'valores' => ['1']],
            ['nombre' => 'C', 'valores' => ['1']], ['nombre' => 'D', 'valores' => ['1']],
        ]])->assertSessionHasErrors('atributos');
        // nombres y valores repetidos, sin distinguir mayúsculas
        $post(['atributos' => [['nombre' => 'Talla', 'valores' => ['S']], ['nombre' => 'talla', 'valores' => ['M']]]])
            ->assertSessionHasErrors('atributos.1.nombre');
        $post(['atributos' => [['nombre' => 'Talla', 'valores' => ['S', 'M', 's']]]])
            ->assertSessionHasErrors('atributos.0.valores');
        // 15 x 14 = 210 combinaciones
        $post(['atributos' => [
            ['nombre' => 'Talla', 'valores' => array_map(fn ($n) => "T{$n}", range(1, 15))],
            ['nombre' => 'Color', 'valores' => array_map(fn ($n) => "C{$n}", range(1, 14))],
        ]])->assertSessionHasErrors('atributos');
        $this->assertStringContainsString('210 variantes', session('errors')->first('atributos'));

        $post(['precio_base' => 0])->assertSessionHasErrors('precio_base');
        $post(['variantes' => [['valores' => ['S', 'Rojo'], 'precio_venta' => 0]]])->assertSessionHasErrors('variantes.0.precio_venta');

        // código repetido entre filas
        $post(['variantes' => [
            ['valores' => ['S', 'Rojo'], 'codigo_barras' => '111'],
            ['valores' => ['S', 'Azul'], 'codigo_barras' => '111'],
        ]])->assertSessionHasErrors('variantes.1.codigo_barras');

        // código que ya usa otro producto de la empresa
        $otro = $this->crearProducto();
        $otro->presentaciones->first()->update(['codigo_barras' => '222']);
        $post(['variantes' => [['valores' => ['S', 'Rojo'], 'codigo_barras' => '222']]])->assertSessionHasErrors('variantes.0.codigo_barras');

        // stock inicial sin costo
        $post(['variantes' => [['valores' => ['S', 'Rojo'], 'stock_inicial' => 3]]])->assertSessionHasErrors('costo');

        // un arreglo donde va un código, o montos que no caben en la columna: error de validación, no un 500
        $post(['unidad_base_codigo' => ['NIU']])->assertStatus(302)->assertSessionHasErrors('unidad_base_codigo');
        $post(['tipo_afectacion_codigo' => ['10']])->assertStatus(302)->assertSessionHasErrors('tipo_afectacion_codigo');
        $post(['precio_base' => 1e12])->assertStatus(302)->assertSessionHasErrors('precio_base');
        $post(['costo' => 1e12])->assertStatus(302)->assertSessionHasErrors('costo');
        $post(['variantes' => [['valores' => ['S', 'Rojo'], 'precio_venta' => 1e12]]])->assertStatus(302)->assertSessionHasErrors('variantes.0.precio_venta');
        $post(['costo' => 1, 'variantes' => [['valores' => ['S', 'Rojo'], 'stock_inicial' => 10000000]]])->assertStatus(302)->assertSessionHasErrors('variantes.0.stock_inicial');
        // y por JSON responde 422
        $this->actingAs($this->admin)->postJson('/productos/modelos', $this->datos(['unidad_base_codigo' => ['NIU'], 'precio_base' => 1e12]))
            ->assertStatus(422)->assertJsonValidationErrors(['unidad_base_codigo', 'precio_base']);

        $this->assertSame(0, ProductoGrupo::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(1, Producto::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_no_duplica_una_combinacion_existente_al_editar(): void
    {
        $grupo = $this->crearModelo();
        $idsAntes = $grupo->variantes()->pluck('id')->sort()->values()->all();
        $this->assertCount(6, $idsAntes);

        $this->actingAs($this->admin)->get("/productos/modelos/{$grupo->id}/editar")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Productos/FormularioVariantes')
            ->where('modelo.id', $grupo->id)
            ->where('modelo.unidad_base_codigo', 'NIU')
            ->where('modelo.precio_base', 35)
            ->has('modelo.variantes', 6)
            ->where('modelo.variantes.0.valores', ['S', 'Rojo']));

        // se agrega la talla XL (y se manda "rojo" en minúsculas en una fila: sigue siendo la misma combinación)
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos([
            'nombre' => 'Polo clásico',
            'atributos' => [
                ['nombre' => 'Talla', 'valores' => ['S', 'M', 'L', 'XL']],
                ['nombre' => 'Color', 'valores' => ['Rojo', 'Azul']],
            ],
            'variantes' => [['valores' => ['s', 'rojo'], 'precio_venta' => 35, 'codigo_barras' => 'SR-1']],
        ]))->assertSessionHasNoErrors()->assertRedirect("/productos?grupo_id={$grupo->id}");

        $variantes = $grupo->fresh()->variantes()->with('presentaciones')->get();
        $this->assertCount(8, $variantes);
        // las 6 de antes siguen siendo los mismos productos, ahora con el nombre nuevo del modelo
        $this->assertSame($idsAntes, $variantes->pluck('id')->intersect($idsAntes)->sort()->values()->all());
        $this->assertSame('Polo clásico S Rojo', $variantes->first()->nombre);
        $this->assertSame('SR-1', $variantes->first()->presentaciones->first()->codigo_barras);
        $this->assertSame(['Polo clásico XL Rojo', 'Polo clásico XL Azul'], $variantes->slice(6)->pluck('nombre')->values()->all());
        $this->assertSame(8, $variantes->pluck('codigo_interno')->unique()->count());
        $this->assertSame(8, Producto::withTrashed()->where('grupo_id', $grupo->id)->count());

        // guardar otra vez lo mismo no crea nada
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos([
            'nombre' => 'Polo clásico',
            'atributos' => [
                ['nombre' => 'Talla', 'valores' => ['S', 'M', 'L', 'XL']],
                ['nombre' => 'Color', 'valores' => ['Rojo', 'Azul']],
            ],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(8, Producto::withTrashed()->where('grupo_id', $grupo->id)->count());

        // los atributos de un modelo ya creado no se cambian
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos([
            'atributos' => [['nombre' => 'Talla', 'valores' => ['S']], ['nombre' => 'Material', 'valores' => ['Algodón']]],
        ]))->assertSessionHasErrors('atributos');
        $this->assertSame(8, $grupo->variantes()->where('activo', true)->count());
    }

    public function test_las_combinaciones_omitidas_no_reaparecen_al_editar(): void
    {
        // al crear se decide no tener el L Azul
        $grupo = $this->crearModelo(['variantes' => [['valores' => ['L', 'Azul'], 'omitir' => true]]]);
        $this->assertSame([['L', 'Azul']], $grupo->omitidas);
        $this->assertSame(5, Producto::withTrashed()->where('grupo_id', $grupo->id)->count());

        $this->actingAs($this->admin)->get("/productos/modelos/{$grupo->id}/editar")->assertInertia(fn (Assert $p) => $p
            ->where('modelo.omitidas', [['L', 'Azul']])
            ->has('modelo.variantes', 5));

        // guardar otra vez con los mismos atributos, sin filas o repitiendo la omisión: no se crea sola
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos(['nombre' => 'Polo básico 2']))->assertSessionHasNoErrors();
        $this->assertSame(5, Producto::withTrashed()->where('grupo_id', $grupo->id)->count());
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos([
            'variantes' => [['valores' => ['L', 'Azul'], 'omitir' => true], ['valores' => ['S', 'Rojo'], 'precio_venta' => 35]],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(5, Producto::withTrashed()->where('grupo_id', $grupo->id)->count());
        $this->assertSame([['L', 'Azul']], $grupo->fresh()->omitidas);

        // agregar una talla tampoco la trae de vuelta (sí crea las de la talla nueva)
        $conXl = [['nombre' => 'Talla', 'valores' => ['S', 'M', 'L', 'XL']], ['nombre' => 'Color', 'valores' => ['Rojo', 'Azul']]];
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos(['atributos' => $conXl]))->assertSessionHasNoErrors();
        $this->assertSame(7, Producto::withTrashed()->where('grupo_id', $grupo->id)->count());
        $this->assertNull($this->variante($grupo->fresh(), 'L', 'Azul'));

        // al volver a incluirla sí se crea, y deja de figurar como omitida
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos([
            'atributos' => $conXl,
            'variantes' => [['valores' => ['L', 'Azul'], 'omitir' => false]],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(8, Producto::withTrashed()->where('grupo_id', $grupo->id)->count());
        $this->assertNotNull($this->variante($grupo->fresh(), 'L', 'Azul'));
        $this->assertSame([], $grupo->fresh()->omitidas);
    }

    public function test_un_codigo_de_barras_de_otra_variante_del_modelo_no_se_reutiliza(): void
    {
        $grupo = $this->crearModelo([
            'costo' => 10,
            'variantes' => [
                ['valores' => ['S', 'Rojo'], 'codigo_barras' => 'SR-001'],
                ['valores' => ['L', 'Rojo'], 'codigo_barras' => 'LR-001', 'stock_inicial' => 2],
            ],
        ]);

        // otra fila del mismo modelo no puede tomar el código que ya tiene S Rojo (aunque S Rojo no venga en el envío)
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos([
            'variantes' => [['valores' => ['M', 'Azul'], 'codigo_barras' => 'SR-001']],
        ]))->assertSessionHasErrors('variantes.0.codigo_barras');

        // se quita la talla L: L Rojo queda inactiva (tiene movimientos) y conserva su código
        $sinL = [['nombre' => 'Talla', 'valores' => ['S', 'M']], ['nombre' => 'Color', 'valores' => ['Rojo', 'Azul']]];
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos(['atributos' => $sinL]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos([
            'atributos' => $sinL,
            'variantes' => [['valores' => ['M', 'Rojo'], 'codigo_barras' => 'LR-001']],
        ]))->assertSessionHasErrors('variantes.0.codigo_barras');

        // cada una sí conserva el suyo al guardar
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos([
            'atributos' => $sinL,
            'variantes' => [['valores' => ['S', 'Rojo'], 'codigo_barras' => 'SR-001']],
        ]))->assertSessionHasNoErrors();
        $this->assertSame('SR-001', $this->variante($grupo->fresh(), 'S', 'Rojo')->presentaciones->first()->codigo_barras);
    }

    public function test_stock_inicial_por_variante_crea_capa_y_kardex(): void
    {
        $grupo = $this->crearModelo([
            'costo' => 18.5,
            'variantes' => [
                ['valores' => ['S', 'Rojo'], 'stock_inicial' => 4],
                ['valores' => ['M', 'Azul'], 'stock_inicial' => 10],
            ],
        ]);

        $sRojo = $this->variante($grupo, 'S', 'Rojo');
        $mAzul = $this->variante($grupo, 'M', 'Azul');
        $lRojo = $this->variante($grupo, 'L', 'Rojo');

        $this->assertEquals(4, $this->stockDe($sRojo));
        $this->assertEquals(10, $this->stockDe($mAzul));
        $this->assertEquals(0, $this->stockDe($lRojo));

        $capa = CapaCosto::where('producto_id', $sRojo->id)->sole();
        $this->assertSame($this->sucursal->id, $capa->sucursal_id);
        $this->assertEquals(4, (float) $capa->cantidad_inicial);
        $this->assertEquals(4, (float) $capa->cantidad_restante);
        $this->assertEquals(18.5, (float) $capa->costo_unitario);

        $movimiento = MovimientoInventario::where('producto_id', $mAzul->id)->sole();
        $this->assertSame('ajuste', $movimiento->tipo);
        $this->assertEquals(10, (float) $movimiento->cantidad);
        $this->assertEquals(18.5, (float) $movimiento->costo_unitario);
        $this->assertSame($this->admin->id, $movimiento->usuario_id);

        $this->assertSame(0, CapaCosto::where('producto_id', $lRojo->id)->count());
        $this->assertSame(0, MovimientoInventario::where('producto_id', $lRojo->id)->count());

        // al editar, el stock inicial de una variante que ya existe se ignora (para eso está el ajuste de stock)
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos([
            'costo' => 18.5,
            'variantes' => [['valores' => ['S', 'Rojo'], 'stock_inicial' => 50]],
        ]))->assertSessionHasNoErrors();
        $this->assertEquals(4, $this->stockDe($sRojo));
    }

    public function test_vender_una_variante_descuenta_solo_su_stock(): void
    {
        $grupo = $this->crearModelo([
            'costo' => 20,
            'variantes' => [
                ['valores' => ['S', 'Rojo'], 'stock_inicial' => 5],
                ['valores' => ['S', 'Azul'], 'stock_inicial' => 5],
            ],
        ]);
        $sRojo = $this->variante($grupo, 'S', 'Rojo');
        $sAzul = $this->variante($grupo, 'S', 'Azul');
        $this->abrirCaja();

        $this->venderContado($sRojo->presentaciones->first(), 2)->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertEquals(3, $this->stockDe($sRojo));
        $this->assertEquals(5, $this->stockDe($sAzul));
        $this->assertEquals(3, (float) CapaCosto::where('producto_id', $sRojo->id)->value('cantidad_restante'));
        $this->assertEquals(5, (float) CapaCosto::where('producto_id', $sAzul->id)->value('cantidad_restante'));
        $this->assertSame(1, MovimientoInventario::where('producto_id', $sRojo->id)->where('tipo', 'venta')->count());
        $this->assertSame(0, MovimientoInventario::where('producto_id', $sAzul->id)->where('tipo', 'venta')->count());
    }

    public function test_sin_el_modulo_activo_responde_403(): void
    {
        $grupo = $this->crearModelo();
        $this->empresa->update(['variantes_activo' => false]);
        $this->admin->refresh();

        $this->actingAs($this->admin)->get('/productos/modelos/crear')->assertForbidden();
        $this->actingAs($this->admin)->post('/productos/modelos', $this->datos(['nombre' => 'Otro']))->assertForbidden();
        $this->actingAs($this->admin)->get("/productos/modelos/{$grupo->id}/editar")->assertForbidden();
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos())->assertForbidden();
        $this->actingAs($this->admin)->delete("/productos/modelos/{$grupo->id}")->assertForbidden();
        $this->assertSame(1, ProductoGrupo::where('empresa_id', $this->empresa->id)->count());

        // la lista de productos sigue funcionando y no ofrece modelos
        $this->actingAs($this->admin)->get('/productos')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('auth.user.empresa.variantes_activo', false)
            ->where('catalogos.modelos', []));
    }

    public function test_el_interruptor_de_empresa_enciende_y_apaga_el_modulo(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('auth.user.empresa.variantes_activo', true));
        $this->actingAs($this->admin)->get('/empresa')->assertInertia(fn (Assert $p) => $p->where('empresa.variantes_activo', true));

        $datos = ['razon_social' => $this->empresa->razon_social, 'regimen_tributario' => 'MYPE', 'rubro_codigo' => $this->empresa->rubro_codigo];
        $this->actingAs($this->admin)->put('/empresa', $datos)->assertSessionHasNoErrors();
        $this->assertFalse($this->empresa->fresh()->variantes_activo);

        $this->actingAs($this->admin)->put('/empresa', [...$datos, 'variantes_activo' => true])->assertSessionHasNoErrors();
        $this->assertTrue($this->empresa->fresh()->variantes_activo);
    }

    public function test_un_usuario_sin_permiso_de_precios_no_cambia_precios_de_variantes(): void
    {
        $grupo = $this->crearModelo();
        $almacenero = $this->crearUsuario('almacenero', 'almacen'.random_int(1000, 9999).'@test.local');
        $this->assertFalse($almacenero->can('productos.precios'));
        $sRojo = $this->variante($grupo, 'S', 'Rojo');

        // intenta subir un precio: no cambia nada (ni el nombre que venía en el mismo guardado)
        $this->actingAs($almacenero)->put("/productos/modelos/{$grupo->id}", $this->datos([
            'nombre' => 'Polo renombrado',
            'variantes' => [['valores' => ['S', 'Rojo'], 'precio_venta' => 99]],
        ]))->assertSessionHas('error');
        $this->assertEquals(35, (float) $sRojo->presentaciones->first()->fresh()->precio_venta);
        $this->assertSame('Polo básico', $grupo->fresh()->nombre);
        $this->assertSame(0, Auditoria::where('empresa_id', $this->empresa->id)->where('accion', 'producto.precio_actualizado')->count());

        // sin tocar precios sí mantiene el catálogo: código de barras y una talla nueva
        $this->actingAs($almacenero)->put("/productos/modelos/{$grupo->id}", $this->datos([
            'atributos' => [
                ['nombre' => 'Talla', 'valores' => ['S', 'M', 'L', 'XL']],
                ['nombre' => 'Color', 'valores' => ['Rojo', 'Azul']],
            ],
            'variantes' => [['valores' => ['S', 'Rojo'], 'precio_venta' => 35, 'codigo_barras' => 'ALM-1']],
        ]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame('ALM-1', $sRojo->presentaciones->first()->fresh()->codigo_barras);
        $this->assertCount(8, $grupo->variantes()->get());

        // quien tiene el permiso lo cambia y queda en auditoría como en el producto
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos([
            'atributos' => [
                ['nombre' => 'Talla', 'valores' => ['S', 'M', 'L', 'XL']],
                ['nombre' => 'Color', 'valores' => ['Rojo', 'Azul']],
            ],
            'variantes' => [['valores' => ['S', 'Rojo'], 'precio_venta' => 42, 'codigo_barras' => 'ALM-1']],
        ]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertEquals(42, (float) $sRojo->presentaciones->first()->fresh()->precio_venta);
        // las demás no se tocaron
        $this->assertEquals(35, (float) $this->variante($grupo, 'M', 'Azul')->presentaciones->first()->precio_venta);

        $auditoria = Auditoria::where('empresa_id', $this->empresa->id)->where('accion', 'producto.precio_actualizado')->sole();
        $this->assertSame($sRojo->id, $auditoria->entidad_id);
        $this->assertEquals(35, $auditoria->detalle['cambios'][0]['de']);
        $this->assertEquals(42, $auditoria->detalle['cambios'][0]['a']);
    }

    public function test_no_ve_ni_edita_modelos_de_otra_empresa(): void
    {
        $grupo = $this->crearModelo();
        $propias = $grupo->variantes()->count();

        // otra empresa con el módulo encendido
        $otra = Empresa::create([
            'ruc' => '20'.random_int(10000000, 99999999).'3',
            'razon_social' => 'Otra Empresa SAC',
            'regimen_tributario' => 'MYPE',
            'rubro_codigo' => Rubro::query()->value('codigo'),
            'variantes_activo' => true,
            'activo' => true,
        ]);
        $sucursalOtra = Sucursal::create(['empresa_id' => $otra->id, 'codigo_sunat' => '0000', 'nombre' => 'Principal', 'activo' => true]);
        app(SuscripcionService::class)->iniciarPrueba($otra);
        $intruso = Usuario::create([
            'empresa_id' => $otra->id,
            'sucursal_id' => $sucursalOtra->id,
            'rol_id' => $this->admin->rol_id,
            'email' => 'intruso'.random_int(1000, 9999).'@test.local',
            'password_hash' => 'secreto123',
            'nombre_completo' => 'Intruso',
            'activo' => true,
            'email_verificado_en' => now(),
        ]);

        $this->actingAs($intruso)->get("/productos/modelos/{$grupo->id}/editar")->assertForbidden();
        $this->actingAs($intruso)->put("/productos/modelos/{$grupo->id}", $this->datos(['nombre' => 'Hackeado']))->assertForbidden();
        $this->actingAs($intruso)->delete("/productos/modelos/{$grupo->id}")->assertForbidden();

        $this->assertSame('Polo básico', $grupo->fresh()->nombre);
        $this->assertSame($propias, $grupo->variantes()->count());

        // su lista no muestra el modelo ni sus productos, aunque pida el id ajeno
        $this->actingAs($intruso)->get('/productos?grupo_id='.$grupo->id)->assertInertia(fn (Assert $p) => $p
            ->where('productos.total', 0)
            ->where('catalogos.modelos', []));

        // y no puede colgar sus variantes de una categoría ajena
        $categoriaAjena = Categoria::create(['empresa_id' => $otra->id, 'nombre' => 'Ajena']);
        $this->actingAs($this->admin)->post('/productos/modelos', $this->datos(['categoria_id' => $categoriaAjena->id]))
            ->assertSessionHasErrors('categoria_id');
    }

    public function test_quitar_un_valor_desactiva_la_variante_con_movimientos(): void
    {
        $grupo = $this->crearModelo([
            'costo' => 12,
            'variantes' => [['valores' => ['L', 'Rojo'], 'stock_inicial' => 3]],
        ]);
        $lRojo = $this->variante($grupo, 'L', 'Rojo');
        $lAzul = $this->variante($grupo, 'L', 'Azul');

        // se quita la talla L
        $sinL = [
            ['nombre' => 'Talla', 'valores' => ['S', 'M']],
            ['nombre' => 'Color', 'valores' => ['Rojo', 'Azul']],
        ];
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos(['atributos' => $sinL]))
            ->assertSessionHasNoErrors();

        // la que tenía movimientos (su stock inicial) queda desactivada, con su stock y su kardex intactos
        $lRojo->refresh();
        $this->assertFalse($lRojo->trashed());
        $this->assertFalse($lRojo->activo);
        $this->assertSame($grupo->id, $lRojo->grupo_id);
        $this->assertEquals(3, $this->stockDe($lRojo));
        $this->assertSame(1, MovimientoInventario::where('producto_id', $lRojo->id)->count());

        // la que no tenía ninguno se elimina
        $this->assertTrue(Producto::withTrashed()->findOrFail($lAzul->id)->trashed());
        $this->assertSame(4, $grupo->variantes()->where('activo', true)->count());

        // al volver a agregar la talla, la desactivada se reactiva (no se duplica) y la eliminada se crea de nuevo
        $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos())->assertSessionHasNoErrors();
        $this->assertTrue($lRojo->fresh()->activo);
        $this->assertSame(6, $grupo->variantes()->where('activo', true)->count());
        $this->assertSame(1, $grupo->variantes()->get()->filter(fn (Producto $p) => $p->atributos == ['Talla' => 'L', 'Color' => 'Rojo'])->count());
    }

    public function test_eliminar_el_modelo_elimina_sus_variantes_y_pide_el_permiso_de_eliminar(): void
    {
        $grupo = $this->crearModelo();
        $suelto = $this->crearProducto();

        $almacenero = $this->crearUsuario('almacenero', 'almacen'.random_int(1000, 9999).'@test.local');
        $this->actingAs($almacenero)->delete("/productos/modelos/{$grupo->id}")->assertForbidden();
        $this->assertNotNull($grupo->fresh());

        // crear el modelo dejó constancia
        $creado = Auditoria::where('empresa_id', $this->empresa->id)->where('accion', 'producto_grupo.creado')->sole();
        $this->assertSame($grupo->id, $creado->entidad_id);
        $this->assertSame(6, $creado->detalle['variantes']);

        // con stock no se elimina
        $conStock = $this->variante($grupo, 'M', 'Rojo');
        $this->darStock($conStock, 7, 10);
        $this->actingAs($this->admin)->delete("/productos/modelos/{$grupo->id}")->assertSessionHas('error');
        $this->assertStringContainsString('7 unidades en stock', session('error'));
        $this->assertNotNull(ProductoGrupo::find($grupo->id));
        $this->assertSame(6, Producto::where('grupo_id', $grupo->id)->count());
        $this->assertSame(0, Auditoria::where('empresa_id', $this->empresa->id)->where('accion', 'producto_grupo.eliminado')->count());

        // con el stock en cero, sí
        Stock::where('producto_id', $conStock->id)->update(['cantidad' => 0]);
        $this->actingAs($this->admin)->delete("/productos/modelos/{$grupo->id}")->assertRedirect('/productos');
        $eliminado = Auditoria::where('empresa_id', $this->empresa->id)->where('accion', 'producto_grupo.eliminado')->sole();
        $this->assertSame('Polo básico', $eliminado->detalle['modelo']);
        $this->assertNull(ProductoGrupo::find($grupo->id));
        $this->assertSame(0, Producto::where('grupo_id', $grupo->id)->count());
        $this->assertSame(6, Producto::withTrashed()->where('grupo_id', $grupo->id)->count());
        $this->assertNotNull($suelto->fresh());
    }

    public function test_registrarse_con_rubro_ropa_o_celulares_deja_las_variantes_activas(): void
    {
        Mail::fake();
        Cache::flush();

        $registrar = function (string $ruc, string $rubro): Empresa {
            auth()->logout();
            $this->post('/registro', [
                'ruc' => $ruc,
                'razon_social' => 'Negocio '.$rubro,
                'nombre_comercial' => null,
                'rubro_codigo' => $rubro,
                'regimen_tributario' => 'RUS',
                'direccion' => null,
                'ubigeo' => null,
                'nombre_completo' => 'Dueño',
                'email' => "dueno{$rubro}".random_int(10000, 99999).'@test.local',
                'password' => 'clave2026',
                'password_confirmation' => 'clave2026',
            ])->assertSessionHasNoErrors()->assertRedirect('/dashboard');

            return Empresa::where('ruc', $ruc)->firstOrFail();
        };

        $this->assertTrue($registrar($this->rucValido('2060000001'), 'ropa')->variantes_activo);
        $this->assertTrue($registrar($this->rucValido('2060000002'), 'celulares')->variantes_activo);
        $this->assertFalse($registrar($this->rucValido('2060000003'), 'bodega')->variantes_activo);
    }

    /** Completa los 10 primeros dígitos de un RUC con su dígito verificador (módulo 11). */
    private function rucValido(string $base): string
    {
        $suma = 0;
        foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $factor) {
            $suma += (int) $base[$i] * $factor;
        }
        $digito = (11 - ($suma % 11)) % 10;

        return $base.$digito;
    }
}
