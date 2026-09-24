<?php

namespace Tests\Feature;

use App\Models\AperturaCaja;
use App\Models\Producto;
use App\Models\Usuario;
use App\Support\Permisos;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/**
 * Cada rol solo puede hacer lo que la matriz de App\Support\Permisos le
 * concede; el backend lo aplica con can: en rutas y comprobaciones puntuales.
 */
class PermisosTest extends TestCase
{
    use CreaEscenarioPos;

    private Usuario $cajero;

    private Usuario $vendedor;

    private Usuario $almacenero;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();

        $sufijo = random_int(10000, 99999);
        $this->cajero = $this->crearUsuario('cajero', "cajero{$sufijo}@test.local");
        $this->vendedor = $this->crearUsuario('vendedor', "vendedor{$sufijo}@test.local");
        $this->almacenero = $this->crearUsuario('almacenero', "almacenero{$sufijo}@test.local");

        $this->producto = $this->crearProducto(precio: 10.00);
        $this->darStock($this->producto, 20, 4.00);
    }

    /** Abre la caja del escenario para el usuario, cerrando antes el turno que hubiera (una apertura por caja). */
    private function abrirCajaDe(Usuario $usuario): AperturaCaja
    {
        AperturaCaja::where('caja_id', $this->caja->id)->whereNull('cerrada_en')->update(['cerrada_en' => now()]);

        return AperturaCaja::create([
            'empresa_id' => $this->empresa->id,
            'caja_id' => $this->caja->id,
            'usuario_id' => $usuario->id,
            'monto_inicial' => 100,
        ]);
    }

    private function payloadProducto(array $extra = []): array
    {
        $presentacion = $this->producto->presentaciones->first();

        return [
            'codigo_interno' => $this->producto->codigo_interno,
            'nombre' => $this->producto->nombre,
            'categoria_id' => null,
            'marca_id' => null,
            'unidad_base_codigo' => $this->producto->unidad_base_codigo,
            'tipo_afectacion_codigo' => $this->producto->tipo_afectacion_codigo,
            'permite_fraccion' => false,
            'controla_lote' => false,
            'controla_stock' => true,
            'stock_minimo' => 0,
            'activo' => true,
            'presentaciones' => [[
                'id' => $presentacion->id,
                'nombre' => 'Unidad',
                'unidad_codigo' => $presentacion->unidad_codigo,
                'factor_conversion' => 1,
                'precio_venta' => 10.00,
                'precio_mayorista' => null,
                'cantidad_mayorista' => null,
                'codigo_barras' => null,
                'es_default' => true,
            ]],
            ...$extra,
        ];
    }

    public function test_el_admin_tiene_todos_los_permisos_y_los_demas_solo_los_suyos(): void
    {
        $this->assertSame(array_keys(Permisos::DESCRIPCIONES), $this->admin->permisos());
        $this->assertTrue($this->cajero->can('pos.vender'));
        $this->assertFalse($this->cajero->can('stock.ajustar'));
        $this->assertTrue($this->almacenero->can('stock.ajustar'));
        $this->assertFalse($this->almacenero->can('pos.vender'));
        $this->assertFalse($this->vendedor->can('pos.precio_manual'));
    }

    public function test_los_permisos_viajan_al_frontend(): void
    {
        $this->actingAs($this->cajero)->get('/')
            ->assertInertia(fn ($pagina) => $pagina
                ->where('auth.user.rol', 'cajero')
                ->where('auth.user.permisos', fn ($permisos) => in_array('pos.vender', $permisos->all(), true)
                    && ! in_array('reportes.ver', $permisos->all(), true)));
    }

    public function test_un_vendedor_no_puede_tocar_precios_stock_compras_ni_dinero_de_proveedores(): void
    {
        $vendedor = $this->actingAs($this->vendedor);

        $vendedor->put("/productos/{$this->producto->id}", $this->payloadProducto())->assertForbidden();
        $vendedor->delete("/productos/{$this->producto->id}")->assertForbidden();
        $vendedor->post("/stock/{$this->producto->id}/ajustar", ['direccion' => 'salida', 'cantidad' => 1])->assertForbidden();
        $vendedor->post('/compras', [])->assertForbidden();
        $vendedor->get('/compras')->assertForbidden();
        $vendedor->get('/reportes')->assertForbidden();
        $vendedor->get('/usuarios')->assertForbidden();
        $vendedor->post("/pos/productos/{$this->producto->id}/historial")->assertStatus(405); // GET; el GET tampoco:
        $vendedor->get("/pos/productos/{$this->producto->id}/historial")->assertForbidden();

        // lo que si le corresponde
        $vendedor->get('/pos')->assertOk();
        $vendedor->get('/clientes')->assertOk();
    }

    public function test_un_vendedor_no_puede_vender_con_precio_manual_pero_un_cajero_si(): void
    {
        $presentacion = $this->producto->presentaciones->first();
        $venta = fn (float $precio) => [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => 1, 'precio_unitario' => $precio]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => $precio, 'referencia' => null]],
        ];

        $this->abrirCajaDe($this->vendedor);
        $this->actingAs($this->vendedor)->post('/pos/ventas', $venta(7.00))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'precio'));
        $this->assertSame(20.0, $this->stockDe($this->producto));

        $this->abrirCajaDe($this->cajero);
        $this->actingAs($this->cajero)->post('/pos/ventas', $venta(7.00))->assertSessionHas('success');
        $this->assertSame(19.0, $this->stockDe($this->producto));
    }

    public function test_los_egresos_de_caja_exigen_permiso_y_quedan_auditados(): void
    {
        $this->abrirCajaDe($this->vendedor);
        $this->actingAs($this->vendedor)
            ->post('/caja/movimientos', ['tipo' => 'egreso', 'concepto' => 'taxi', 'monto' => 10])
            ->assertSessionHas('error');
        $this->assertDatabaseMissing('auditoria', ['empresa_id' => $this->empresa->id, 'accion' => 'caja.egreso']);

        $this->abrirCajaDe($this->cajero);
        $this->actingAs($this->cajero)
            ->post('/caja/movimientos', ['tipo' => 'egreso', 'concepto' => 'taxi', 'monto' => 10])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('auditoria', ['empresa_id' => $this->empresa->id, 'usuario_id' => $this->cajero->id, 'accion' => 'caja.egreso']);
    }

    public function test_solo_quien_administra_el_credito_cambia_el_limite_y_queda_auditado(): void
    {
        $cliente = $this->crearCliente(limiteCredito: 0);
        $datos = [
            'tipo_documento_codigo' => $cliente->tipo_documento_codigo,
            'numero_documento' => $cliente->numero_documento,
            'nombre' => $cliente->nombre,
            'direccion' => null,
            'telefono' => null,
            'email' => null,
            'limite_credito' => 500,
        ];

        // el cajero puede editar al cliente, pero el limite no cambia
        $this->actingAs($this->cajero)->put("/clientes/{$cliente->id}", $datos)->assertSessionHas('success');
        $this->assertSame(0.0, (float) $cliente->fresh()->limite_credito);
        $this->assertDatabaseMissing('auditoria', ['entidad_id' => $cliente->id, 'accion' => 'cliente.limite_credito']);

        $this->actingAs($this->admin)->put("/clientes/{$cliente->id}", $datos)->assertSessionHas('success');
        $this->assertSame(500.0, (float) $cliente->fresh()->limite_credito);
        $this->assertDatabaseHas('auditoria', ['entidad_id' => $cliente->id, 'accion' => 'cliente.limite_credito']);
    }

    public function test_el_almacenero_maneja_stock_y_productos_pero_no_precios_ni_ventas(): void
    {
        $almacenero = $this->actingAs($this->almacenero);

        $almacenero->get('/pos')->assertForbidden();
        $almacenero->get('/caja')->assertForbidden();
        $almacenero->get('/compras')->assertOk();

        // ajuste de stock permitido y auditado
        $almacenero->post("/stock/{$this->producto->id}/ajustar", ['direccion' => 'salida', 'cantidad' => 2])
            ->assertSessionHas('success');
        $this->assertSame(18.0, $this->stockDe($this->producto));
        $this->assertDatabaseHas('auditoria', ['entidad_id' => $this->producto->id, 'accion' => 'stock.ajustado']);

        // puede renombrar el producto...
        $almacenero->put("/productos/{$this->producto->id}", $this->payloadProducto(['nombre' => 'Nombre corregido']))
            ->assertSessionHas('success');
        $this->assertSame('Nombre corregido', $this->producto->fresh()->nombre);

        // ...pero no cambiar el precio
        $payload = $this->payloadProducto();
        $payload['presentaciones'][0]['precio_venta'] = 12.00;
        $almacenero->put("/productos/{$this->producto->id}", $payload)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'precios'));
        $this->assertSame(10.0, (float) $this->producto->presentaciones()->first()->precio_venta);

        // el admin si
        $this->actingAs($this->admin)->put("/productos/{$this->producto->id}", $payload)->assertSessionHas('success');
        $this->assertSame(12.0, (float) $this->producto->presentaciones()->first()->precio_venta);
    }

    public function test_los_costos_se_ocultan_a_quien_no_los_maneja(): void
    {
        $this->actingAs($this->cajero)->get('/stock')
            ->assertInertia(fn ($pagina) => $pagina
                ->where('resumen.valor_total', null)
                ->where('productos.data.0.valor_inventario', null));

        $this->actingAs($this->almacenero)->get('/stock')
            ->assertInertia(fn ($pagina) => $pagina
                ->where('resumen.valor_total', fn ($v) => (float) $v === 80.0)
                ->where('productos.data.0.valor_inventario', fn ($v) => (float) $v === 80.0));

        $this->actingAs($this->cajero)->get('/')
            ->assertInertia(fn ($pagina) => $pagina
                ->where('hoy.margen', null)
                ->where('mes', null)
                ->where('pendientes.por_pagar', null));

        $this->actingAs($this->admin)->get('/')
            ->assertInertia(fn ($pagina) => $pagina->where('mes.total', fn ($v) => (float) $v === 0.0));
    }
}
