<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\CajaService;
use App\Services\ReportesNegocioService;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** Parte 2 del módulo: entregar el pedido desde el POS cobrando solo el saldo, campanita y orden de pedido. */
class PedidoEntregaTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    /** Pedido de 10 unidades a S/ 100 (S/ 1,000) con stock, caja abierta y un adelanto opcional. */
    private function pedidoConAdelanto(float $adelanto = 300, string $medio = 'yape', float $precioEspecial = 0): array
    {
        $producto = $this->crearProducto(precio: 100);
        $this->darStock($producto, 20, 40);
        $presentacion = $producto->presentaciones->first();
        $apertura = $this->abrirCaja(50);

        $item = ['presentacion_id' => $presentacion->id, 'cantidad' => 10];
        if ($precioEspecial > 0) {
            $item['precio_unitario'] = $precioEspecial;
        }

        $this->actingAs($this->admin)->post('/pedidos', [
            'cliente_id' => $this->crearCliente()->id,
            'fecha_entrega' => now()->addDay()->toDateString(),
            'items' => [$item],
        ])->assertSessionHas('success');
        $pedido = Pedido::where('empresa_id', $this->empresa->id)->orderByDesc('numero')->firstOrFail();

        if ($adelanto > 0) {
            $this->actingAs($this->admin)->post("/pedidos/{$pedido->id}/adelantos", [
                'monto' => $adelanto, 'medio_pago_codigo' => $medio, 'referencia' => $medio === 'efectivo' ? null : 'OP-1',
            ])->assertSessionHas('success');
        }

        return [$pedido->fresh(), $presentacion, $apertura];
    }

    private function entregar(Pedido $pedido, array $items, array $pagos, ?Usuario $usuario = null, array $extra = [])
    {
        return $this->actingAs($usuario ?? $this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $pedido->cliente_id,
            'es_credito' => false,
            'pedido_id' => $pedido->id,
            'items' => $items,
            'pagos' => $pagos,
            ...$extra,
        ]);
    }

    public function test_el_pos_carga_el_pedido_con_precios_fijados_adelanto_y_saldo(): void
    {
        [$pedido, $presentacion] = $this->pedidoConAdelanto(300, 'yape', precioEspecial: 80);

        $this->actingAs($this->admin)->get("/pos?pedido={$pedido->id}")->assertInertia(fn ($p) => $p
            ->where('cotizacion', null)
            ->where('pedido.codigo', 'PED-000001')
            ->where('pedido.total', fn ($v) => abs($v - 800) < 0.001)
            ->where('pedido.adelanto', fn ($v) => abs($v - 300) < 0.001)
            ->where('pedido.saldo', fn ($v) => abs($v - 500) < 0.001)
            ->where('pedido.cliente.id', $pedido->cliente_id)
            ->has('pedido.items', 1)
            ->where('pedido.items.0.precio_unitario', fn ($v) => abs($v - 80) < 0.001));

        // un vendedor sin permiso de precio manual entrega al precio fijado (80, no 100)
        $vendedor = $this->crearUsuario('vendedor', 'vendedor'.random_int(10000, 99999).'@test.local');
        $this->abrirCajaPara($vendedor);
        $this->entregar($pedido, [['presentacion_id' => $presentacion->id, 'cantidad' => 10, 'precio_unitario' => 80]], [['medio_pago_codigo' => 'efectivo', 'monto' => 500, 'referencia' => null]], $vendedor)
            ->assertSessionHas('success');
        $this->assertEqualsWithDelta(800, (float) Comprobante::where('empresa_id', $this->empresa->id)->value('total'), 0.001);
    }

    public function test_entregar_cobra_solo_el_saldo_y_la_caja_no_cuenta_el_adelanto_dos_veces(): void
    {
        [$pedido, $presentacion, $apertura] = $this->pedidoConAdelanto(300, 'yape');

        // cobrar los 1,000 completos se rechaza: el adelanto ya está
        $this->entregar($pedido, [['presentacion_id' => $presentacion->id, 'cantidad' => 10]], [['medio_pago_codigo' => 'efectivo', 'monto' => 1000, 'referencia' => null]])
            ->assertSessionHas('error');
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());

        // se cobra el saldo de 700 en efectivo
        $this->entregar($pedido, [['presentacion_id' => $presentacion->id, 'cantidad' => 10]], [['medio_pago_codigo' => 'efectivo', 'monto' => 700, 'referencia' => null]])
            ->assertSessionHas('success')
            ->assertSessionHas('venta', fn ($v) => $v['pedido']['codigo'] === 'PED-000001' && abs($v['pedido']['adelanto'] - 300) < 0.001);
        $this->assertStringContainsString('Pedido PED-000001 entregado', session('success'));

        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertEqualsWithDelta(1000, (float) $comprobante->total, 0.001);

        // dos pagos: el efectivo del turno y el adelanto interno sin apertura
        $pagos = Pago::where('comprobante_id', $comprobante->id)->get()->keyBy('medio_pago_codigo');
        $this->assertCount(2, $pagos);
        $this->assertEqualsWithDelta(700, (float) $pagos['efectivo']->monto, 0.001);
        $this->assertSame($apertura->id, $pagos['efectivo']->apertura_id);
        $this->assertEqualsWithDelta(300, (float) $pagos['adelanto']->monto, 0.001);
        $this->assertNull($pagos['adelanto']->apertura_id);
        $this->assertSame('PED-000001', $pagos['adelanto']->referencia);

        // caja del turno: inicial 50 + adelanto yape 300 (ingreso) + venta efectivo 700; sin fila "adelanto"
        $resumen = app(CajaService::class)->resumen($apertura);
        $this->assertEqualsWithDelta(700, $resumen['ventas_efectivo'], 0.001);
        $this->assertEqualsWithDelta(750, $resumen['esperado'], 0.001);
        $medios = collect(app(CajaService::class)->resumenPorMedio($apertura))->keyBy('codigo');
        $this->assertFalse($medios->has('adelanto'));
        $this->assertEqualsWithDelta(300, $medios['yape']['esperado'], 0.001);

        // reporte de caja por medio del día: yape 300 de ingresos, efectivo 700 de ventas, nada duplicado
        // (filas: [medio, ventas, cobros, otros ingresos, egresos, proveedores, neto] con los montos formateados)
        $filas = collect(app(ReportesNegocioService::class)->cajaPorMedioDePago($this->empresa->id, null, now()->toDateString(), now()->toDateString())['filas'])->keyBy(0);
        $monto = fn ($texto) => (float) str_replace(',', '', (string) $texto);
        $this->assertFalse($filas->has('Adelanto de pedido'));
        $this->assertEqualsWithDelta(300, $monto($filas['Yape'][3]), 0.001);
        $this->assertEqualsWithDelta(700, $monto($filas['Efectivo'][1]), 0.001);

        $pedido = $pedido->fresh();
        $this->assertSame('entregado', $pedido->estado);
        $this->assertSame($comprobante->id, $pedido->comprobante_id);
        $this->assertNotNull($pedido->entregado_en);

        // ya entregado no se vuelve a cargar en el POS
        $this->actingAs($this->admin)->get("/pos?pedido={$pedido->id}")->assertInertia(fn ($p) => $p->where('pedido', null));
    }

    public function test_adelanto_igual_al_total_se_entrega_sin_pagos(): void
    {
        [$pedido, $presentacion] = $this->pedidoConAdelanto(1000, 'efectivo');

        $this->entregar($pedido, [['presentacion_id' => $presentacion->id, 'cantidad' => 10]], [])->assertSessionHas('success');

        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertEqualsWithDelta(1000, (float) $comprobante->total, 0.001);
        $this->assertSame(1, Pago::where('comprobante_id', $comprobante->id)->count());
        $this->assertSame('entregado', $pedido->fresh()->estado);
    }

    public function test_un_pedido_con_adelanto_no_se_entrega_al_credito(): void
    {
        [$pedido, $presentacion] = $this->pedidoConAdelanto(300, 'yape');
        $pedido->cliente->update(['limite_credito' => 5000]);

        $this->entregar($pedido, [['presentacion_id' => $presentacion->id, 'cantidad' => 10]], [], extra: ['es_credito' => true])
            ->assertSessionHas('error', 'Un pedido con adelanto se entrega al contado: el saldo se cobra ahora.');
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame('pendiente', $pedido->fresh()->estado);
    }

    public function test_si_quitan_productos_y_el_adelanto_supera_la_venta_se_rechaza(): void
    {
        [$pedido, $presentacion] = $this->pedidoConAdelanto(300, 'yape');

        // entregan solo 2 unidades (200) con 300 adelantados
        $this->entregar($pedido, [['presentacion_id' => $presentacion->id, 'cantidad' => 2]], [])->assertSessionHas('error');
        $this->assertStringContainsString('supera el total', session('error'));
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_el_pedido_se_entrega_desde_una_caja_de_su_sucursal(): void
    {
        [$pedido, $presentacion] = $this->pedidoConAdelanto(0);
        $alameda = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Alameda', 'activo' => true]);
        $pedido->update(['sucursal_id' => $alameda->id]);

        $this->entregar($pedido, [['presentacion_id' => $presentacion->id, 'cantidad' => 10]], [['medio_pago_codigo' => 'efectivo', 'monto' => 1000, 'referencia' => null]])
            ->assertSessionHas('error');
        $this->assertStringContainsString('Alameda', session('error'));
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_anular_la_venta_devuelve_el_pedido_a_listo_y_conserva_el_adelanto(): void
    {
        [$pedido, $presentacion] = $this->pedidoConAdelanto(300, 'yape');
        $this->entregar($pedido, [['presentacion_id' => $presentacion->id, 'cantidad' => 10]], [['medio_pago_codigo' => 'efectivo', 'monto' => 700, 'referencia' => null]])->assertSessionHas('success');
        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();

        $this->actingAs($this->admin)->post("/comprobantes/{$comprobante->id}/anular", ['motivo' => 'Se equivocaron de producto'])->assertSessionHas('success');

        $pedido = $pedido->fresh();
        $this->assertSame('listo', $pedido->estado);
        $this->assertNull($pedido->comprobante_id);
        $this->assertNull($pedido->entregado_en);
        $this->assertEqualsWithDelta(300, $pedido->totalAdelantos(), 0.001);
        $this->assertSame(0, Pago::where('comprobante_id', $comprobante->id)->count());
        // el ingreso del adelanto sigue en la caja del día que entró
        $this->assertSame(1, MovimientoCaja::where('empresa_id', $this->empresa->id)->where('tipo', 'ingreso')->count());

        // y se puede volver a entregar
        $this->entregar($pedido, [['presentacion_id' => $presentacion->id, 'cantidad' => 10]], [['medio_pago_codigo' => 'efectivo', 'monto' => 700, 'referencia' => null]])->assertSessionHas('success');
        $this->assertSame('entregado', $pedido->fresh()->estado);
    }

    public function test_un_pedido_no_se_entrega_dos_veces_y_el_reintento_no_duplica_la_venta(): void
    {
        [$pedido, $presentacion] = $this->pedidoConAdelanto(0);
        $pagos = [['medio_pago_codigo' => 'efectivo', 'monto' => 1000, 'referencia' => null]];
        $items = [['presentacion_id' => $presentacion->id, 'cantidad' => 10]];
        $clave = (string) \Illuminate\Support\Str::uuid();

        $this->entregar($pedido, $items, $pagos, extra: ['clave' => $clave])->assertSessionHas('success');
        $this->assertSame('entregado', $pedido->fresh()->estado);

        // el mismo envío repetido (doble clic / respuesta que no llegó) devuelve la venta ya hecha, sin repetirla
        $this->entregar($pedido, $items, $pagos, extra: ['clave' => $clave])->assertSessionHas('success');
        $this->assertStringContainsString('ya estaba registrada', session('success'));
        $this->assertSame(1, Comprobante::where('empresa_id', $this->empresa->id)->count());

        // otra caja con el pedido todavía cargado: error claro, no una segunda venta
        $this->entregar($pedido, $items, $pagos)->assertSessionHas('error');
        $this->assertStringContainsString('ya fue entregado o anulado', session('error'));
        $this->assertSame(1, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertEqualsWithDelta(10, $this->stockDe($presentacion->producto), 0.001);
    }

    public function test_la_venta_queda_a_nombre_del_cliente_del_pedido_y_el_pos_no_carga_pedidos_de_otra_sede(): void
    {
        [$pedido, $presentacion] = $this->pedidoConAdelanto(300, 'yape');
        $otroCliente = $this->crearCliente();

        // el cajero cambió el cliente en el POS: manda el del pedido
        $this->entregar($pedido, [['presentacion_id' => $presentacion->id, 'cantidad' => 10]], [['medio_pago_codigo' => 'efectivo', 'monto' => 700, 'referencia' => null]], extra: ['cliente_id' => $otroCliente->id])
            ->assertSessionHas('success');
        $this->assertSame($pedido->cliente_id, Comprobante::where('empresa_id', $this->empresa->id)->value('cliente_id'));

        // pedido de otra sucursal: el POS no lo carga y avisa
        $this->actingAs($this->admin)->post('/pedidos', [
            'cliente_id' => $this->crearCliente()->id,
            'fecha_entrega' => now()->addDay()->toDateString(),
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => 1]],
        ])->assertSessionHas('success');
        $otro = Pedido::where('empresa_id', $this->empresa->id)->orderByDesc('numero')->firstOrFail();
        $alameda = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Alameda', 'activo' => true]);
        $otro->update(['sucursal_id' => $alameda->id]);
        $this->actingAs($this->admin)->get("/pos?pedido={$otro->id}")
            ->assertInertia(fn ($p) => $p->where('pedido', null))
            ->assertSessionHas('error');
        $this->assertStringContainsString('Alameda', session('error'));
    }

    public function test_la_campanita_cuenta_atrasados_hoy_y_manana_y_los_entregados_no_avisan(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $item = [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]];
        $crear = function (string $fecha) use ($item) {
            $this->actingAs($this->admin)->post('/pedidos', ['cliente_id' => $this->crearCliente()->id, 'fecha_entrega' => $fecha, 'items' => $item])->assertSessionHas('success');

            return Pedido::where('empresa_id', $this->empresa->id)->orderByDesc('numero')->firstOrFail();
        };
        $crear(now()->toDateString());
        $crear(now()->toDateString());
        $crear(now()->addDay()->toDateString());
        $atrasado = $crear(now()->addDays(3)->toDateString());
        $atrasado->update(['fecha_pedido' => now()->subDays(5)->toDateString(), 'fecha_entrega' => now()->subDay()->toDateString()]);
        $entregado = $crear(now()->toDateString());
        $entregado->update(['estado' => 'entregado', 'entregado_en' => now()]);

        $items = collect($this->actingAs($this->admin)->getJson('/notificaciones')->assertOk()->json('items'))->keyBy('clave');
        $this->assertSame(1, $items['pedidos_atrasados']['cantidad']);
        $this->assertSame(2, $items['pedidos_hoy']['cantidad']);
        $this->assertSame(1, $items['pedidos_manana']['cantidad']);
        $this->assertSame('/pedidos?pestana=hoy', $items['pedidos_hoy']['url']);
        $this->assertStringContainsString('2 pedidos se entregan hoy', $items['pedidos_hoy']['detalle']);

        // el almacenero (solo pedidos.ver) también los ve; sin pedidos abiertos no aparecen
        $almacenero = $this->crearUsuario('almacenero', 'almacen'.random_int(10000, 99999).'@test.local');
        $this->assertTrue(collect($this->actingAs($almacenero)->getJson('/notificaciones')->json('items'))->pluck('clave')->contains('pedidos_hoy'));
        Pedido::where('empresa_id', $this->empresa->id)->update(['estado' => 'anulado', 'anulado_en' => now()]);
        $this->assertFalse(collect($this->actingAs($this->admin)->getJson('/notificaciones')->json('items'))->pluck('clave')->contains('pedidos_hoy'));
    }

    public function test_la_orden_de_pedido_muestra_productos_adelantos_y_saldo(): void
    {
        [$pedido] = $this->pedidoConAdelanto(300, 'yape');
        $pedido->update(['observaciones' => 'Lechuga variedad X, listos para trasplante']);

        $html = $this->actingAs($this->admin)->get("/pedidos/{$pedido->id}/ticket?formato=html")->assertOk()->getContent();
        $this->assertStringContainsString('ORDEN DE PEDIDO', $html);
        $this->assertStringContainsString('PED-000001', $html);
        $this->assertStringContainsString($pedido->cliente_nombre, $html);
        $this->assertStringContainsString('S/ 1,000.00', $html);
        $this->assertStringContainsString('-S/ 300.00', $html);
        $this->assertStringContainsString('S/ 700.00', $html);
        $this->assertStringContainsString('Lechuga variedad X', $html);
        $this->assertStringContainsString('no es comprobante de pago', $html);

        // otra empresa no lo imprime
        $this->crearEscenarioBase();
        $this->actingAs($this->admin)->get("/pedidos/{$pedido->id}/ticket?formato=html")->assertForbidden();
    }

    /** Abre la caja del escenario para otro usuario (la del admin sigue abierta). */
    private function abrirCajaPara(Usuario $usuario): void
    {
        $caja = \App\Models\Caja::create(['empresa_id' => $this->empresa->id, 'sucursal_id' => $this->sucursal->id, 'nombre' => 'Caja 2', 'activo' => true]);
        \App\Models\AperturaCaja::create(['empresa_id' => $this->empresa->id, 'caja_id' => $caja->id, 'usuario_id' => $usuario->id, 'monto_inicial' => 0]);
    }
}
