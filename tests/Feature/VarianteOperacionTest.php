<?php

namespace Tests\Feature;

use App\Models\CapaCosto;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\ProductoGrupo;
use App\Models\Rubro;
use App\Models\Sucursal;
use App\Models\TipoAfectacionIgv;
use App\Models\Transferencia;
use App\Models\Usuario;
use App\Services\SuscripcionService;
use App\Services\VarianteService;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/**
 * Variantes V2: el POS, compras, transferencias y stock reciben el modelo de cada variante para operar por
 * modelo (rejilla talla × color). Es solo presentación: una empresa sin variantes recibe lo mismo de siempre
 * y la venta sigue siendo por producto.
 */
class VarianteOperacionTest extends TestCase
{
    use CreaEscenarioPos;

    /** Claves que lleva de más un producto que es variante de un modelo. */
    private const CLAVES_VARIANTE = ['grupo_id', 'grupo', 'atributos', 'variante_orden'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->empresa->update(['variantes_activo' => true]);
    }

    /** Polo básico en Talla S/M/L y Color Rojo/Azul (6 variantes a S/ 35), con stock en S Rojo y M Azul. */
    private function crearModelo(array $extra = []): ProductoGrupo
    {
        $this->actingAs($this->admin)->post('/productos/modelos', [
            'nombre' => 'Polo básico',
            'categoria_id' => null,
            'marca_id' => null,
            'unidad_base_codigo' => 'NIU',
            'tipo_afectacion_codigo' => TipoAfectacionIgv::where('afecto', true)->value('codigo'),
            'precio_base' => 35,
            'costo' => 20,
            'atributos' => [
                ['nombre' => 'Talla', 'valores' => ['S', 'M', 'L']],
                ['nombre' => 'Color', 'valores' => ['Rojo', 'Azul']],
            ],
            'variantes' => [
                ['valores' => ['S', 'Rojo'], 'stock_inicial' => 5],
                ['valores' => ['M', 'Azul'], 'stock_inicial' => 3],
            ],
            ...$extra,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return ProductoGrupo::where('empresa_id', $this->empresa->id)->latest('creado_en')->firstOrFail();
    }

    private function variante(ProductoGrupo $grupo, string ...$valores): Producto
    {
        return $grupo->variantes()->with('presentaciones')->get()
            ->first(fn (Producto $p) => VarianteService::valoresDe($p, $grupo->atributos) === $valores);
    }

    /** Otra empresa con el módulo encendido y su propio administrador. */
    private function otraEmpresa(): Usuario
    {
        $otra = Empresa::create([
            'ruc' => '20'.random_int(10000000, 99999999).'3',
            'razon_social' => 'Otra Empresa SAC',
            'regimen_tributario' => 'MYPE',
            'rubro_codigo' => Rubro::query()->value('codigo'),
            'variantes_activo' => true,
            'activo' => true,
        ]);
        $sucursal = Sucursal::create(['empresa_id' => $otra->id, 'codigo_sunat' => '0000', 'nombre' => 'Principal', 'activo' => true]);
        app(SuscripcionService::class)->iniciarPrueba($otra);

        return Usuario::create([
            'empresa_id' => $otra->id,
            'sucursal_id' => $sucursal->id,
            'rol_id' => $this->admin->rol_id,
            'email' => 'otra'.random_int(1000, 9999).'@test.local',
            'password_hash' => 'secreto123',
            'nombre_completo' => 'Otra Admin',
            'activo' => true,
            'email_verificado_en' => now(),
        ]);
    }

    /** Las props de una pantalla Inertia tal como llegan al navegador. */
    private function props(string $url, ?Usuario $usuario = null): array
    {
        $props = $this->actingAs($usuario ?? $this->admin)->get($url)->assertOk()->viewData('page')['props'];

        return json_decode(json_encode($props), true);
    }

    public function test_el_pos_recibe_el_modelo_y_los_atributos_de_cada_variante(): void
    {
        $grupo = $this->crearModelo();
        $suelto = $this->crearProducto(12);
        $this->abrirCaja();

        $props = $this->props('/pos');

        $this->assertCount(1, $props['modelos']);
        $this->assertSame($grupo->id, $props['modelos'][0]['id']);
        $this->assertSame('Polo básico', $props['modelos'][0]['nombre']);
        // la definición conserva el orden en que se escribieron los valores (arma las filas y columnas de la rejilla)
        $this->assertSame(
            [['nombre' => 'Talla', 'valores' => ['S', 'M', 'L']], ['nombre' => 'Color', 'valores' => ['Rojo', 'Azul']]],
            $props['modelos'][0]['atributos'],
        );

        $productos = collect($props['productos'])->keyBy('id');
        $mAzul = $productos[$this->variante($grupo, 'M', 'Azul')->id];
        $this->assertSame($grupo->id, $mAzul['grupo_id']);
        $this->assertSame('Polo básico', $mAzul['grupo']);
        $this->assertEquals(['Talla' => 'M', 'Color' => 'Azul'], $mAzul['atributos']);
        $this->assertSame(3, $mAzul['variante_orden']);
        $this->assertEquals(3, $mAzul['stock']);
        $this->assertSame('Polo básico M Azul', $mAzul['nombre']);
        $this->assertEquals(35, $mAzul['presentaciones'][0]['precio_venta']);

        // las 6 variantes llegan y el producto sin modelo no lleva ninguna clave de variante
        $this->assertCount(6, $productos->where('grupo_id', $grupo->id));
        $this->assertSame([], array_intersect(self::CLAVES_VARIANTE, array_keys($productos[$suelto->id])));
    }

    public function test_sin_variantes_el_pos_compras_transferencias_y_stock_no_cambian_de_forma(): void
    {
        $this->empresa->update(['variantes_activo' => false]);
        $this->admin->unsetRelation('empresa');
        $this->crearProducto(12);
        $this->abrirCaja();

        $pos = $this->props('/pos');
        $this->assertArrayNotHasKey('modelos', $pos);
        $this->assertSame(
            ['id', 'nombre', 'codigo_interno', 'categoria_id', 'controla_stock', 'controla_serie', 'permite_fraccion', 'imagen_url', 'stock', 'presentaciones'],
            array_keys($pos['productos'][0]),
        );

        $compras = $this->props('/compras/crear');
        $this->assertArrayNotHasKey('modelos', $compras);
        $this->assertSame(
            ['id', 'nombre', 'codigo_interno', 'controla_stock', 'controla_lote', 'controla_serie', 'permite_fraccion', 'imagen_url', 'lotes_recientes', 'presentaciones'],
            array_keys($compras['productos'][0]),
        );

        $transferencias = $this->props('/transferencias/crear');
        $this->assertArrayNotHasKey('modelos', $transferencias);
        $this->assertSame(
            ['id', 'nombre', 'codigo_interno', 'permite_fraccion', 'controla_serie', 'imagen_url', 'stock', 'codigos'],
            array_keys($transferencias['productos'][0]),
        );

        $stock = $this->props('/stock');
        $this->assertArrayNotHasKey('modelos', $stock);
        $this->assertArrayNotHasKey('matriz', $stock);
        $this->assertSame(1, $stock['productos']['total']);
    }

    public function test_con_el_modulo_encendido_pero_sin_modelos_tampoco_cambia_la_forma(): void
    {
        $this->crearProducto(12);
        $this->abrirCaja();

        foreach (['/pos', '/compras/crear', '/transferencias/crear', '/stock'] as $url) {
            $props = $this->props($url);
            $this->assertArrayNotHasKey('modelos', $props, $url);
            $this->assertArrayNotHasKey('matriz', $props, $url);
        }
        $this->assertSame([], array_intersect(self::CLAVES_VARIANTE, array_keys($this->props('/pos')['productos'][0])));
    }

    public function test_con_el_modulo_apagado_las_variantes_que_ya_existen_salen_como_productos_sueltos(): void
    {
        $grupo = $this->crearModelo();
        $this->empresa->update(['variantes_activo' => false]);
        $this->admin->unsetRelation('empresa'); // la empresa que el usuario ya tenía cargada en memoria
        $this->abrirCaja();

        $pos = $this->props('/pos');
        $this->assertArrayNotHasKey('modelos', $pos);
        $this->assertCount(6, $pos['productos']);
        $this->assertSame([], array_intersect(self::CLAVES_VARIANTE, array_keys($pos['productos'][0])));

        // y el filtro por modelo de Stock se ignora
        $stock = $this->props('/stock?grupo_id='.$grupo->id);
        $this->assertArrayNotHasKey('matriz', $stock);
        $this->assertArrayNotHasKey('grupo_id', (array) $stock['filtros']);
    }

    public function test_compras_y_transferencias_reciben_el_modelo_de_cada_variante(): void
    {
        $grupo = $this->crearModelo();
        $suelto = $this->crearProducto(12);
        $sRojo = $this->variante($grupo, 'S', 'Rojo');

        $compras = $this->props('/compras/crear');
        $this->assertSame([$grupo->id], array_column($compras['modelos'], 'id'));
        $this->assertSame(['Talla', 'Color'], array_column($compras['modelos'][0]['atributos'], 'nombre'));
        $productos = collect($compras['productos'])->keyBy('id');
        $this->assertSame('Polo básico', $productos[$sRojo->id]['grupo']);
        $this->assertEquals(['Talla' => 'S', 'Color' => 'Rojo'], $productos[$sRojo->id]['atributos']);
        $this->assertSame(0, $productos[$sRojo->id]['variante_orden']);
        // el costo del stock inicial sale de referencia, como en cualquier producto
        $this->assertEquals(20, $productos[$sRojo->id]['presentaciones'][0]['ultimo_costo']);
        $this->assertArrayNotHasKey('grupo_id', $productos[$suelto->id]);

        $transferencias = $this->props('/transferencias/crear');
        $this->assertSame([$grupo->id], array_column($transferencias['modelos'], 'id'));
        $productos = collect($transferencias['productos'])->keyBy('id');
        $this->assertSame($grupo->id, $productos[$sRojo->id]['grupo_id']);
        $this->assertEquals(['Talla' => 'S', 'Color' => 'Rojo'], $productos[$sRojo->id]['atributos']);
        // el tope de cada celda de la matriz es el stock del origen
        $this->assertEquals(5, $productos[$sRojo->id]['stock']);
        $this->assertEquals(0, $productos[$this->variante($grupo, 'L', 'Azul')->id]['stock']);
        $this->assertArrayNotHasKey('grupo_id', $productos[$suelto->id]);
    }

    public function test_vender_una_variante_elegida_desde_el_modelo_es_una_venta_normal(): void
    {
        $grupo = $this->crearModelo();
        $this->abrirCaja();

        // lo que hace el selector del POS: toma la variante de la celda M / Azul y manda su presentación
        $productos = collect($this->props('/pos')['productos']);
        $celda = $productos->first(fn ($p) => ($p['grupo_id'] ?? null) === $grupo->id && $p['atributos'] == ['Talla' => 'M', 'Color' => 'Azul']);
        $presentacionId = $celda['presentaciones'][0]['id'];

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $presentacionId, 'cantidad' => 2]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 70, 'referencia' => null]],
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $mAzul = $this->variante($grupo, 'M', 'Azul');
        $sRojo = $this->variante($grupo, 'S', 'Rojo');
        $this->assertEquals(1, $this->stockDe($mAzul));
        $this->assertEquals(5, $this->stockDe($sRojo));
        $this->assertEquals(1, (float) CapaCosto::where('producto_id', $mAzul->id)->value('cantidad_restante'));
        $this->assertSame(1, MovimientoInventario::where('producto_id', $mAzul->id)->where('tipo', 'venta')->count());

        // el comprobante lleva el nombre completo de la variante, como cualquier producto
        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->latest('creado_en')->firstOrFail();
        $this->assertEquals(70, (float) $comprobante->total);
        $this->assertSame('Polo básico M Azul', $comprobante->detalles()->value('descripcion'));

        // y el POS ya muestra el stock que queda en esa celda
        $despues = collect($this->props('/pos')['productos'])->firstWhere('id', $mAzul->id);
        $this->assertEquals(1, $despues['stock']);
    }

    public function test_no_se_ven_modelos_de_otra_empresa(): void
    {
        $grupo = $this->crearModelo();
        $this->abrirCaja();
        $intruso = $this->otraEmpresa();

        foreach (['/compras/crear', '/transferencias/crear', '/stock', '/stock?grupo_id='.$grupo->id] as $url) {
            $props = $this->props($url, $intruso);
            $this->assertArrayNotHasKey('modelos', $props, $url);
            $this->assertArrayNotHasKey('matriz', $props, $url);
        }

        // pidiendo el modelo ajeno, su lista de stock no se filtra por él ni muestra sus variantes
        $stock = $this->props('/stock?grupo_id='.$grupo->id, $intruso);
        $this->assertArrayNotHasKey('grupo_id', (array) $stock['filtros']);
        $this->assertSame(0, $stock['productos']['total']);

        // la propia sigue viendo el suyo
        $this->assertSame([$grupo->id], array_column($this->props('/pos')['modelos'], 'id'));
    }

    public function test_stock_filtra_por_modelo_y_arma_la_matriz(): void
    {
        $grupo = $this->crearModelo();
        $suelto = $this->crearProducto(12);
        $this->darStock($suelto, 4, 5);
        $this->variante($grupo, 'M', 'Azul')->update(['stock_minimo' => 5]);

        // sin filtrar: aparece el filtro, pero no la matriz
        $todo = $this->props('/stock');
        $this->assertSame([['id' => $grupo->id, 'nombre' => 'Polo básico']], $todo['modelos']);
        $this->assertArrayNotHasKey('matriz', $todo);
        $this->assertSame(7, $todo['productos']['total']);

        $props = $this->props('/stock?grupo_id='.$grupo->id);
        $this->assertSame($grupo->id, $props['filtros']['grupo_id']);

        // la lista de abajo: solo las variantes del modelo, en el orden de sus tallas
        $this->assertSame(6, $props['productos']['total']);
        $this->assertSame(
            ['Polo básico S Rojo', 'Polo básico S Azul', 'Polo básico M Rojo', 'Polo básico M Azul', 'Polo básico L Rojo', 'Polo básico L Azul'],
            array_column($props['productos']['data'], 'nombre'),
        );

        // la matriz: definición del modelo y el stock de cada variante en la sucursal
        $matriz = $props['matriz'];
        $this->assertSame($grupo->id, $matriz['id']);
        $this->assertSame('Polo básico', $matriz['nombre']);
        $this->assertSame(['S', 'M', 'L'], $matriz['atributos'][0]['valores']);
        $this->assertSame(['Rojo', 'Azul'], $matriz['atributos'][1]['valores']);
        $this->assertCount(6, $matriz['variantes']);
        $porNombre = collect($matriz['variantes'])->keyBy('nombre');
        $this->assertEquals(5, $porNombre['Polo básico S Rojo']['stock']);
        $this->assertEquals(3, $porNombre['Polo básico M Azul']['stock']);
        $this->assertEquals(5, $porNombre['Polo básico M Azul']['stock_minimo']);
        $this->assertEquals(0, $porNombre['Polo básico L Azul']['stock']);
        $this->assertEquals(['Talla' => 'M', 'Color' => 'Azul'], $porNombre['Polo básico M Azul']['atributos']);
        // con una sola sucursal no hay detalle por sucursal
        $this->assertArrayNotHasKey('por_sucursal', $porNombre['Polo básico S Rojo']);

        // un id mal formado o inexistente se ignora
        foreach (['no-es-uuid', '019a0000-0000-7000-8000-000000000000'] as $id) {
            $otro = $this->props('/stock?grupo_id='.$id);
            $this->assertArrayNotHasKey('matriz', $otro);
            $this->assertSame(7, $otro['productos']['total']);
        }
    }

    public function test_la_matriz_de_stock_detalla_cada_sucursal_cuando_se_ven_varias(): void
    {
        $grupo = $this->crearModelo();
        $sRojo = $this->variante($grupo, 'S', 'Rojo');
        $segunda = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Segunda', 'activo' => true]);

        // 2 unidades de S Rojo viajan a la segunda sucursal
        $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_origen_id' => $this->sucursal->id,
            'sucursal_destino_id' => $segunda->id,
            'items' => [['producto_id' => $sRojo->id, 'cantidad' => 2]],
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $transferencia = Transferencia::where('empresa_id', $this->empresa->id)->latest('creado_en')->firstOrFail();
        $this->actingAs($this->admin)->post("/transferencias/{$transferencia->id}/recibir")->assertSessionMissing('error');

        // el admin no eligió sucursal en el selector: ve las dos
        $props = $this->props('/stock?grupo_id='.$grupo->id);
        $this->assertCount(2, $props['sucursales']);

        $fila = collect($props['matriz']['variantes'])->firstWhere('id', $sRojo->id);
        $this->assertEquals(5, $fila['stock']);
        $this->assertEquals(3, $fila['por_sucursal'][$this->sucursal->id]);
        $this->assertEquals(2, $fila['por_sucursal'][$segunda->id]);
    }
}
