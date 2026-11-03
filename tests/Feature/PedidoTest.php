<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Comprobante;
use App\Models\MovimientoCaja;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\PedidoAdelanto;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\CajaService;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class PedidoTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    private function payload(array $items, array $extra = []): array
    {
        return [
            'cliente_id' => $extra['cliente_id'] ?? $this->crearCliente()->id,
            'fecha_entrega' => now()->addDays(2)->toDateString(),
            'hora_entrega' => '09:30',
            'observaciones' => 'Plantines listos para trasplante',
            'items' => $items,
            ...$extra,
        ];
    }

    private function pedir(array $items, array $extra = [], ?Usuario $usuario = null): Pedido
    {
        $this->actingAs($usuario ?? $this->admin)
            ->post('/pedidos', $this->payload($items, $extra))
            ->assertRedirect()
            ->assertSessionHas('success');

        return Pedido::where('empresa_id', $this->empresa->id)->orderByDesc('numero')->firstOrFail();
    }

    private function adelantar(Pedido $pedido, float $monto, string $medio = 'efectivo', ?Usuario $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->admin)->post("/pedidos/{$pedido->id}/adelantos", [
            'monto' => $monto,
            'medio_pago_codigo' => $medio,
            'referencia' => $medio === 'efectivo' ? null : 'OP-123',
        ]);
    }

    // ---------- registrar ----------

    public function test_registrar_pedido_calcula_como_venta_fija_precios_y_no_toca_stock_ni_caja(): void
    {
        $producto = $this->crearProducto(precio: 118.00);
        $this->darStock($producto, 10, 50);
        $cliente = $this->crearCliente();
        $cliente->update(['telefono' => '977425905']);
        $this->abrirCaja(100);

        $pedido = $this->pedir(
            [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 2, 'descuento' => 36]],
            ['cliente_id' => $cliente->id],
        );

        $this->assertSame(1, $pedido->numero);
        $this->assertSame('PED-000001', $pedido->codigo());
        $this->assertSame('pendiente', $pedido->estado);
        $this->assertSame($cliente->nombre, $pedido->cliente_nombre);
        $this->assertSame('977425905', $pedido->cliente_telefono);
        $this->assertSame('09:30', $pedido->horaEntrega());
        $this->assertSame(now()->toDateString(), $pedido->fecha_pedido->toDateString());
        // 2 x 118 = 236, menos 36 = 200 con IGV incluido
        $this->assertEqualsWithDelta(200.00, (float) $pedido->total, 0.001);
        $this->assertEqualsWithDelta(30.51, (float) $pedido->total_igv, 0.001);
        $this->assertSame(1, $pedido->detalles()->count());
        $this->assertEqualsWithDelta(118.00, (float) $pedido->detalles->first()->precio_unitario, 0.001);

        // ni stock, ni comprobantes, ni caja
        $this->assertEqualsWithDelta(10, $this->stockDe($producto), 0.001);
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(0, MovimientoInventario::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(0, MovimientoCaja::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_el_pedido_exige_cliente_y_fecha_de_entrega_no_pasada(): void
    {
        $producto = $this->crearProducto();
        $item = [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]];

        $this->actingAs($this->admin)
            ->post('/pedidos', ['cliente_id' => null, 'fecha_entrega' => now()->toDateString(), 'items' => $item])
            ->assertSessionHasErrors('cliente_id');

        $this->actingAs($this->admin)
            ->post('/pedidos', ['cliente_id' => $this->crearCliente()->id, 'fecha_entrega' => now()->subDay()->toDateString(), 'items' => $item])
            ->assertSessionHasErrors('fecha_entrega');

        // hoy mismo sí se puede
        $this->actingAs($this->admin)
            ->post('/pedidos', ['cliente_id' => $this->crearCliente()->id, 'fecha_entrega' => now()->toDateString(), 'items' => $item])
            ->assertSessionHas('success');
    }

    public function test_sin_permiso_de_precio_manual_se_pide_al_precio_de_lista(): void
    {
        $producto = $this->crearProducto(precio: 50);
        $vendedor = $this->crearUsuario('vendedor', 'vendedor'.random_int(10000, 99999).'@test.local');

        $pedido = $this->pedir(
            [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1, 'precio_unitario' => 1]],
            [],
            $vendedor,
        );

        $this->assertEqualsWithDelta(50.00, (float) $pedido->total, 0.001);
    }

    public function test_editar_solo_en_pendiente_y_no_por_debajo_del_adelanto(): void
    {
        $producto = $this->crearProducto(precio: 100);
        $presentacion = $producto->presentaciones->first()->id;
        $pedido = $this->pedir([['presentacion_id' => $presentacion, 'cantidad' => 10]]);
        $this->abrirCaja(0);
        $this->adelantar($pedido, 300)->assertSessionHas('success');

        // bajar a 2 unidades (200) con 300 de adelanto: rechazado
        $this->actingAs($this->admin)
            ->put("/pedidos/{$pedido->id}", $this->payload([['presentacion_id' => $presentacion, 'cantidad' => 2]], ['cliente_id' => $pedido->cliente_id]))
            ->assertSessionHas('error');
        $this->assertEqualsWithDelta(1000, (float) $pedido->fresh()->total, 0.001);

        // bajar a 5 (500) sí: queda constancia en auditoría porque hay dinero recibido
        $this->actingAs($this->admin)
            ->put("/pedidos/{$pedido->id}", $this->payload([['presentacion_id' => $presentacion, 'cantidad' => 5]], ['cliente_id' => $pedido->cliente_id]))
            ->assertSessionHas('success');
        $this->assertEqualsWithDelta(500, (float) $pedido->fresh()->total, 0.001);
        $this->assertEqualsWithDelta(300, $pedido->fresh()->totalAdelantos(), 0.001);
        $this->assertTrue(Auditoria::where('accion', 'pedido.editado')->where('entidad_id', $pedido->id)->exists());

        // marcado como listo ya no se edita
        $this->actingAs($this->admin)->post("/pedidos/{$pedido->id}/listo")->assertSessionHas('success');
        $this->actingAs($this->admin)->get("/pedidos/{$pedido->id}/editar")->assertRedirect(route('pedidos.index'));
        $this->actingAs($this->admin)
            ->put("/pedidos/{$pedido->id}", $this->payload([['presentacion_id' => $presentacion, 'cantidad' => 6]], ['cliente_id' => $pedido->cliente_id]))
            ->assertSessionHas('error');
    }

    public function test_marcar_listo_solo_desde_pendiente(): void
    {
        $producto = $this->crearProducto();
        $pedido = $this->pedir([['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]]);

        $this->actingAs($this->admin)->post("/pedidos/{$pedido->id}/listo")->assertSessionHas('success');
        $this->assertSame('listo', $pedido->fresh()->estado);
        $this->assertNotNull($pedido->fresh()->listo_en);

        $this->actingAs($this->admin)->post("/pedidos/{$pedido->id}/listo")->assertSessionHas('error');
    }

    // ---------- adelantos ----------

    public function test_el_adelanto_entra_a_la_caja_abierta_con_su_medio_y_no_genera_comprobante(): void
    {
        $producto = $this->crearProducto(precio: 100);
        $pedido = $this->pedir([['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 10]]);
        $apertura = $this->abrirCaja(50);

        $this->adelantar($pedido, 300, 'yape')->assertSessionHas('success');

        $adelanto = PedidoAdelanto::where('pedido_id', $pedido->id)->firstOrFail();
        $this->assertEqualsWithDelta(300, (float) $adelanto->monto, 0.001);
        $this->assertSame('yape', $adelanto->medio_pago_codigo);
        $this->assertSame($apertura->id, $adelanto->apertura_id);

        $movimiento = MovimientoCaja::find($adelanto->movimiento_caja_id);
        $this->assertSame('ingreso', $movimiento->tipo);
        $this->assertSame('yape', $movimiento->medio_pago_codigo);
        $this->assertStringContainsString('Adelanto pedido PED-000001', $movimiento->concepto);
        $this->assertStringContainsString($pedido->cliente_nombre, $movimiento->concepto);

        // el cuadre por medio del turno ya lo cuenta en Yape; el efectivo sigue intacto
        $medios = collect(app(CajaService::class)->resumenPorMedio($apertura))->keyBy('codigo');
        $this->assertEqualsWithDelta(300, $medios['yape']['ingresos'], 0.001);
        $this->assertEqualsWithDelta(300, $medios['yape']['esperado'], 0.001);
        $this->assertEqualsWithDelta(50, $medios['efectivo']['esperado'], 0.001);

        $this->assertEqualsWithDelta(700, $pedido->fresh()->saldo(), 0.001);
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertTrue(Auditoria::where('accion', 'pedido.adelanto')->where('entidad_id', $pedido->id)->exists());
    }

    public function test_sin_caja_abierta_no_se_recibe_adelanto(): void
    {
        $producto = $this->crearProducto(precio: 100);
        $pedido = $this->pedir([['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]]);

        $this->adelantar($pedido, 50)->assertSessionHas('error', 'Necesitas abrir caja para recibir el adelanto.');
        $this->assertSame(0, PedidoAdelanto::where('pedido_id', $pedido->id)->count());
    }

    public function test_el_adelanto_no_supera_el_saldo_y_varios_adelantos_suman_hasta_cubrir_el_total(): void
    {
        $producto = $this->crearProducto(precio: 100);
        $pedido = $this->pedir([['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 5]]);
        $this->abrirCaja(0);

        $this->adelantar($pedido, 600)->assertSessionHas('error');
        $this->adelantar($pedido, 200)->assertSessionHas('success');
        $this->adelantar($pedido, 300.01)->assertSessionHas('error');
        $this->adelantar($pedido, 300, 'plin')->assertSessionHas('success');

        $pedido = $pedido->fresh();
        $this->assertEqualsWithDelta(500, $pedido->totalAdelantos(), 0.001);
        $this->assertEqualsWithDelta(0, $pedido->saldo(), 0.001);
        $this->assertSame(2, MovimientoCaja::where('empresa_id', $this->empresa->id)->where('tipo', 'ingreso')->count());

        // ya no cabe ni un sol más
        $this->adelantar($pedido, 1)->assertSessionHas('error');
    }

    public function test_el_adelanto_se_recibe_en_una_caja_de_la_sucursal_del_pedido(): void
    {
        $producto = $this->crearProducto(precio: 100);
        $pedido = $this->pedir([['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]]);

        // la caja abierta es de otra sucursal
        $otra = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Alameda', 'activo' => true]);
        $pedido->update(['sucursal_id' => $otra->id]);
        $this->abrirCaja(0);

        $this->adelantar($pedido, 50)->assertSessionHas('error');
        $this->assertStringContainsString('Alameda', session('error'));
        $this->assertSame(0, PedidoAdelanto::where('pedido_id', $pedido->id)->count());
    }

    public function test_el_medio_interno_adelanto_no_se_ofrece_ni_se_acepta_como_pago(): void
    {
        $producto = $this->crearProducto(precio: 100);
        $pedido = $this->pedir([['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]]);
        $this->abrirCaja(0);

        $this->actingAs($this->admin)->get('/pedidos')->assertInertia(fn ($p) => $p
            ->where('mediosPago', fn ($medios) => ! collect($medios)->pluck('codigo')->contains('adelanto')));
        $this->actingAs($this->admin)->get('/pos')->assertInertia(fn ($p) => $p
            ->where('mediosPago', fn ($medios) => ! collect($medios)->pluck('codigo')->contains('adelanto')));

        $this->adelantar($pedido, 50, 'adelanto')->assertSessionHasErrors('medio_pago_codigo');

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'adelanto', 'monto' => 100, 'referencia' => null]],
        ])->assertSessionHasErrors('pagos.0.medio_pago_codigo');
    }

    // ---------- anular ----------

    public function test_anular_devolviendo_el_adelanto_genera_un_egreso_y_sin_devolver_no(): void
    {
        $producto = $this->crearProducto(precio: 100);
        $presentacion = $producto->presentaciones->first()->id;
        $this->abrirCaja(0);

        // con devolución: sale de caja por el medio en que entró
        $pedido = $this->pedir([['presentacion_id' => $presentacion, 'cantidad' => 5]]);
        $this->adelantar($pedido, 200, 'yape')->assertSessionHas('success');
        $this->actingAs($this->admin)
            ->post("/pedidos/{$pedido->id}/anular", ['motivo' => 'El cliente ya no quiere', 'devolver_adelanto' => true])
            ->assertSessionHas('success');
        $pedido = $pedido->fresh();
        $this->assertSame('anulado', $pedido->estado);
        $this->assertEqualsWithDelta(200, (float) $pedido->devolucion_monto, 0.001);
        $egreso = MovimientoCaja::find($pedido->devolucion_movimiento_id);
        $this->assertSame('egreso', $egreso->tipo);
        $this->assertSame('yape', $egreso->medio_pago_codigo);
        $this->assertEqualsWithDelta(200, (float) $egreso->monto, 0.001);
        $this->assertStringContainsString('Se devolvieron S/ 200.00', session('success'));

        // sin devolución: queda registrado como no devuelto
        $otro = $this->pedir([['presentacion_id' => $presentacion, 'cantidad' => 1]]);
        $this->adelantar($otro, 30)->assertSessionHas('success');
        $this->actingAs($this->admin)
            ->post("/pedidos/{$otro->id}/anular", ['motivo' => 'No vino a recoger', 'devolver_adelanto' => false])
            ->assertSessionHas('success');
        $this->assertNull($otro->fresh()->devolucion_monto);
        $this->assertSame(1, MovimientoCaja::where('empresa_id', $this->empresa->id)->where('tipo', 'egreso')->count());
        $this->assertStringContainsString('no devuelto', session('success'));

        $this->assertSame(2, Auditoria::where('accion', 'pedido.anulado')->whereIn('entidad_id', [$pedido->id, $otro->id])->count());

        // un pedido anulado no se vuelve a anular ni recibe adelantos
        $this->actingAs($this->admin)->post("/pedidos/{$otro->id}/anular", ['motivo' => 'x', 'devolver_adelanto' => false])->assertSessionHas('error');
        $this->adelantar($otro, 10)->assertSessionHas('error');
    }

    public function test_devolver_el_adelanto_exige_caja_abierta(): void
    {
        $producto = $this->crearProducto(precio: 100);
        $pedido = $this->pedir([['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]]);
        $apertura = $this->abrirCaja(0);
        $this->adelantar($pedido, 40)->assertSessionHas('success');
        $apertura->update(['cerrada_en' => now(), 'monto_sistema' => 40, 'monto_cierre' => 40]);

        $this->actingAs($this->admin)
            ->post("/pedidos/{$pedido->id}/anular", ['motivo' => 'Se arrepintió', 'devolver_adelanto' => true])
            ->assertSessionHas('error', 'Necesitas abrir caja para devolver el adelanto.');
        $this->assertSame('pendiente', $pedido->fresh()->estado);
    }

    // ---------- lista, permisos y aislamiento ----------

    public function test_la_lista_separa_atrasados_hoy_manana_y_proximos_con_sus_contadores(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $presentacion = $producto->presentaciones->first()->id;
        $hoy = $this->pedir([['presentacion_id' => $presentacion, 'cantidad' => 1]], ['fecha_entrega' => now()->toDateString()]);
        $manana = $this->pedir([['presentacion_id' => $presentacion, 'cantidad' => 1]], ['fecha_entrega' => now()->addDay()->toDateString()]);
        $proximo = $this->pedir([['presentacion_id' => $presentacion, 'cantidad' => 1]], ['fecha_entrega' => now()->addDays(5)->toDateString()]);
        $atrasado = $this->pedir([['presentacion_id' => $presentacion, 'cantidad' => 1]]);
        $atrasado->update(['fecha_pedido' => now()->subDays(5)->toDateString(), 'fecha_entrega' => now()->subDays(2)->toDateString()]);
        $entregado = $this->pedir([['presentacion_id' => $presentacion, 'cantidad' => 1]]);
        $entregado->update(['estado' => 'anulado', 'anulado_en' => now(), 'motivo_anulacion' => 'x']);

        $this->actingAs($this->admin)->get('/pedidos')->assertInertia(fn ($p) => $p
            ->where('filtros.pestana', 'hoy')
            ->has('pedidos.data', 1)
            ->where('pedidos.data.0.id', $hoy->id)
            ->where('pedidos.data.0.atrasado', false)
            ->where('conteos.atrasados', 1)
            ->where('conteos.hoy', 1)
            ->where('conteos.manana', 1)
            ->where('conteos.proximos', 1)
            ->where('conteos.historial', 1));

        $this->actingAs($this->admin)->get('/pedidos?pestana=atrasados')->assertInertia(fn ($p) => $p
            ->has('pedidos.data', 1)
            ->where('pedidos.data.0.id', $atrasado->id)
            ->where('pedidos.data.0.atrasado', true));

        $this->actingAs($this->admin)->get('/pedidos?pestana=historial')->assertInertia(fn ($p) => $p
            ->has('pedidos.data', 1)
            ->where('pedidos.data.0.estado', 'anulado'));

        // el buscador por número encuentra en la pestaña que sea
        $this->actingAs($this->admin)->get('/pedidos?pestana=proximos&buscar='.$proximo->codigo())->assertInertia(fn ($p) => $p
            ->has('pedidos.data', 1)
            ->where('pedidos.data.0.codigo', $proximo->codigo()));
        $this->actingAs($this->admin)->get('/pedidos?pestana=manana&buscar='.$manana->cliente_nombre)->assertInertia(fn ($p) => $p
            ->has('pedidos.data', 1));
    }

    public function test_roles_vendedor_registra_pero_no_anula_y_almacenero_solo_ve(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $item = [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]];
        $vendedor = $this->crearUsuario('vendedor', 'vendedor'.random_int(10000, 99999).'@test.local');
        $almacenero = $this->crearUsuario('almacenero', 'almacen'.random_int(10000, 99999).'@test.local');

        $pedido = $this->pedir($item, [], $vendedor);
        $this->actingAs($vendedor)->post("/pedidos/{$pedido->id}/anular", ['motivo' => 'x', 'devolver_adelanto' => false])->assertForbidden();

        $this->actingAs($almacenero)->get('/pedidos')->assertOk();
        $this->actingAs($almacenero)->post('/pedidos', $this->payload($item))->assertForbidden();
        $this->actingAs($almacenero)->post("/pedidos/{$pedido->id}/listo")->assertForbidden();
    }

    public function test_un_usuario_con_sucursal_restringida_no_toca_pedidos_de_otra_sede(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $item = [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]];
        $pedido = $this->pedir($item);

        // cajero que solo puede entrar a la sede Alameda; el pedido es de Principal
        $alameda = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Alameda', 'activo' => true]);
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');
        $cajero->sucursales()->sync([$alameda->id]);

        $this->actingAs($cajero)->get('/pedidos?pestana=proximos')->assertInertia(fn ($p) => $p->has('pedidos.data', 0));
        $this->actingAs($cajero)->get("/pedidos/{$pedido->id}/editar")->assertForbidden();
        $this->actingAs($cajero)->put("/pedidos/{$pedido->id}", $this->payload($item, ['cliente_id' => $pedido->cliente_id]))->assertForbidden();
        $this->actingAs($cajero)->post("/pedidos/{$pedido->id}/listo")->assertForbidden();
        $this->actingAs($cajero)->post("/pedidos/{$pedido->id}/anular", ['motivo' => 'x', 'devolver_adelanto' => false])->assertForbidden();
        $this->assertSame('pendiente', $pedido->fresh()->estado);
    }

    public function test_editar_sin_permiso_de_precio_conserva_el_precio_fijado_del_pedido(): void
    {
        $producto = $this->crearProducto(precio: 100);
        $otro = $this->crearProducto(precio: 50);
        $presentacion = $producto->presentaciones->first()->id;
        // el admin pide a precio especial de 80
        $pedido = $this->pedir([['presentacion_id' => $presentacion, 'cantidad' => 1, 'precio_unitario' => 80]]);
        $this->assertEqualsWithDelta(80, (float) $pedido->total, 0.001);

        // un vendedor (sin pos.precio_manual) solo cambia la hora y agrega otro producto
        $vendedor = $this->crearUsuario('vendedor', 'vendedor'.random_int(10000, 99999).'@test.local');
        $this->actingAs($vendedor)
            ->put("/pedidos/{$pedido->id}", $this->payload([
                ['presentacion_id' => $presentacion, 'cantidad' => 1, 'precio_unitario' => 1],
                ['presentacion_id' => $otro->presentaciones->first()->id, 'cantidad' => 1, 'precio_unitario' => 1],
            ], ['cliente_id' => $pedido->cliente_id, 'hora_entrega' => '15:00']))
            ->assertSessionHas('success');

        // 80 (fijado) + 50 (lista): ni respeta el "1" ni vuelve el primero a 100
        $this->assertEqualsWithDelta(130, (float) $pedido->fresh()->total, 0.001);
        $this->assertSame('15:00', $pedido->fresh()->horaEntrega());
    }

    public function test_con_el_selector_en_todas_el_pedido_nace_en_la_sucursal_de_la_caja_abierta(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $item = [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]];

        // la caja abierta del admin está en Alameda, no en su sucursal por defecto (Principal)
        $alameda = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Alameda', 'activo' => true]);
        $cajaAlameda = \App\Models\Caja::create(['empresa_id' => $this->empresa->id, 'sucursal_id' => $alameda->id, 'nombre' => 'Caja Alameda', 'activo' => true]);
        \App\Models\AperturaCaja::create(['empresa_id' => $this->empresa->id, 'caja_id' => $cajaAlameda->id, 'usuario_id' => $this->admin->id, 'monto_inicial' => 0]);

        $this->actingAs($this->admin)->get('/pedidos/crear')->assertInertia(fn ($p) => $p->where('sucursalNombre', 'Alameda'));
        $pedido = $this->pedir($item);
        $this->assertSame($alameda->id, $pedido->sucursal_id);

        // y así el adelanto entra de inmediato
        $this->adelantar($pedido, 5)->assertSessionHas('success');
    }

    public function test_el_cobro_de_una_cuenta_por_cobrar_tampoco_acepta_el_medio_interno(): void
    {
        $producto = $this->crearProducto(precio: 100);
        $this->darStock($producto, 5, 10);
        $cliente = $this->crearCliente(limiteCredito: 1000);
        $this->abrirCaja(0);
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $cliente->id,
            'es_credito' => true,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
        ])->assertSessionHasNoErrors();
        $cuenta = \App\Models\CuentaPorCobrar::where('cliente_id', $cliente->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 50, 'medio_pago_codigo' => 'adelanto'])
            ->assertSessionHasErrors('medio_pago_codigo');
    }

    public function test_otra_empresa_no_ve_ni_toca_el_pedido(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $pedido = $this->pedir([['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]]);

        $this->crearEscenarioBase();
        $this->actingAs($this->admin)->get('/pedidos?pestana=proximos')->assertInertia(fn ($p) => $p->has('pedidos.data', 0));
        $this->actingAs($this->admin)->get("/pedidos/{$pedido->id}/editar")->assertForbidden();
        $this->actingAs($this->admin)->post("/pedidos/{$pedido->id}/listo")->assertForbidden();
        $this->actingAs($this->admin)->post("/pedidos/{$pedido->id}/anular", ['motivo' => 'x', 'devolver_adelanto' => false])->assertForbidden();
    }
}
