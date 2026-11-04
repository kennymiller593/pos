<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Comprobante;
use App\Models\CuentaPorCobrar;
use App\Models\MovimientoCaja;
use App\Models\Sucursal;
use App\Models\Usuario;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** Movimientos de caja: todo lo que entró y salió, fila por fila y con su medio, como la lista del sistema anterior. */
class MovimientosCajaTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    /** Un turno con de todo: venta en efectivo, venta por Yape, cobro de crédito, ingreso por Plin, egreso, compra pagada con caja. */
    private function turnoCompleto(): array
    {
        $producto = $this->crearProducto(precio: 100);
        $this->darStock($producto, 50, 40);
        $presentacion = $producto->presentaciones->first();
        $apertura = $this->abrirCaja(200);

        $this->venderContado($presentacion, 2)->assertSessionHas('success'); // 200 efectivo
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => null, 'es_credito' => false,
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'yape', 'monto' => 100, 'referencia' => '445566']],
        ])->assertSessionHas('success'); // 100 yape

        $cliente = $this->crearCliente(limiteCredito: 1000);
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => $cliente->id, 'es_credito' => true,
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => 3]],
        ])->assertSessionHas('success'); // 300 al crédito: no entra a caja
        $cuenta = CuentaPorCobrar::where('cliente_id', $cliente->id)->firstOrFail();
        $this->actingAs($this->admin)->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 120, 'medio_pago_codigo' => 'efectivo'])->assertSessionHas('success'); // 120 cobro

        $this->actingAs($this->admin)->post('/caja/movimientos', ['tipo' => 'ingreso', 'concepto' => 'Venta de plantines', 'monto' => 30, 'medio_pago_codigo' => 'plin', 'referencia' => '99'])->assertSessionHas('success');
        $this->actingAs($this->admin)->post('/caja/movimientos', ['tipo' => 'egreso', 'concepto' => 'Pago de taxi', 'monto' => 10])->assertSessionHas('success');
        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null, 'tipo_comprobante_codigo' => '00', 'serie_numero' => 'N-'.random_int(1000, 9999), 'fecha' => now()->toDateString(), 'es_credito' => false,
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => 5, 'costo_unitario' => 40]],
            'pagar_desde_caja' => true, 'medio_pago_codigo' => 'efectivo',
        ])->assertSessionHas('success'); // compra 200 con caja

        return [$apertura, $producto];
    }

    public function test_lista_lo_que_entro_y_salio_con_su_medio_y_el_saldo(): void
    {
        [$apertura] = $this->turnoCompleto();

        $this->actingAs($this->admin)->get('/caja/movimientos')->assertInertia(fn ($p) => $p
            ->component('Caja/Movimientos')
            ->has('entradas', 4)
            ->has('salidas', 2)
            ->where('resumen.entro', fn ($v) => abs($v - 450) < 0.001)   // 200 + 100 + 120 + 30
            ->where('resumen.salio', fn ($v) => abs($v - 210) < 0.001)   // 10 + 200
            ->where('resumen.saldo', fn ($v) => abs($v - 240) < 0.001)
            ->where('resumen.movimientos', 6)
            ->where('entradas', function ($filas) {
                $f = collect($filas);
                $tipos = $f->pluck('tipo')->sort()->values()->all();
                $yape = $f->firstWhere('medio_codigo', 'yape');
                $plin = $f->firstWhere('medio_codigo', 'plin');
                $cobro = $f->firstWhere('tipo', 'cobro');

                return $tipos === ['cobro', 'ingreso', 'venta', 'venta']
                    && $yape['titulo'] === 'Nota de venta' && str_starts_with($yape['detalle'], 'NV') && $yape['referencia'] === '445566'
                    && $plin['titulo'] === 'Ingreso' && $plin['detalle'] === 'Venta de plantines' && $plin['medio'] === 'Plin'
                    && $cobro['titulo'] === 'Cobro de crédito' && abs($cobro['monto'] - 120) < 0.001;
            })
            ->where('salidas', function ($filas) {
                $f = collect($filas);
                $compra = $f->firstWhere('tipo', 'compra');
                $egreso = $f->firstWhere('tipo', 'egreso');

                return $compra['titulo'] === 'Compra' && str_starts_with($compra['detalle'], 'N-') && abs($compra['monto'] - 200) < 0.001
                    && $egreso['detalle'] === 'Pago de taxi' && $egreso['medio'] === 'Efectivo';
            })
            ->where('resumen.entro_por_medio', fn ($m) => collect($m)->pluck('monto', 'codigo')->all() == ['efectivo' => 320.0, 'plin' => 30.0, 'yape' => 100.0])
            ->where('turno', null));

        // por turno: lo mismo, más el cuadre del cajón (abrió con 200, efectivo 200 + 120 − 10 − 200 = 310)
        $this->actingAs($this->admin)->get("/caja/movimientos?turno={$apertura->id}")->assertInertia(fn ($p) => $p
            ->has('entradas', 4)
            ->where('turno.id', $apertura->id)
            ->where('turno.monto_inicial', fn ($v) => abs($v - 200) < 0.001)
            ->where('turno.resumen.esperado', fn ($v) => abs($v - 310) < 0.001)
            ->where('turno.monto_cierre', null)
            ->where('cajaAbierta.id', $apertura->id));

        // otro rango de fechas: nada
        $this->actingAs($this->admin)->get('/caja/movimientos?desde=2026-01-01&hasta=2026-01-31')->assertInertia(fn ($p) => $p
            ->has('entradas', 0)->has('salidas', 0)->where('resumen.saldo', fn ($v) => abs($v) < 0.001));
        $this->actingAs($this->admin)->get('/caja/movimientos?desde=2026-02-01&hasta=2026-01-01')->assertSessionHasErrors('hasta');
    }

    public function test_el_ojo_muestra_la_venta_con_sus_productos_la_compra_y_el_movimiento(): void
    {
        [$apertura, $producto] = $this->turnoCompleto();
        $venta = Comprobante::where('empresa_id', $this->empresa->id)->where('es_credito', false)->orderBy('correlativo')->firstOrFail();
        $compra = Compra::where('empresa_id', $this->empresa->id)->firstOrFail();
        $plin = MovimientoCaja::where('apertura_id', $apertura->id)->where('medio_pago_codigo', 'plin')->firstOrFail();

        $v = $this->actingAs($this->admin)->getJson("/caja/movimientos/detalle?tipo=venta&id={$venta->id}")->assertOk()->json();
        $this->assertSame('venta', $v['tipo']);
        $this->assertStringStartsWith('Nota de venta NV', $v['titulo']);
        $this->assertCount(1, $v['items']);
        $this->assertSame($producto->presentaciones->first()->descripcionConProducto($producto->nombre), $v['items'][0]['descripcion']);
        $this->assertEqualsWithDelta(2, $v['items'][0]['cantidad'], 0.001);
        $this->assertNotSame('NIU', $v['items'][0]['unidad']); // nombre de la unidad, no el código SUNAT
        $this->assertEqualsWithDelta(200, $v['total'], 0.001);
        $this->assertSame('Efectivo', $v['pagos'][0]['medio']);
        $this->assertStringContainsString("/comprobantes/{$venta->id}/ticket", $v['ticket']);

        $c = $this->actingAs($this->admin)->getJson("/caja/movimientos/detalle?tipo=compra&id={$compra->id}")->assertOk()->json();
        $this->assertSame('compra', $c['tipo']);
        $this->assertCount(1, $c['items']);
        $this->assertEqualsWithDelta(5, $c['items'][0]['cantidad'], 0.001);
        $this->assertEqualsWithDelta(200, $c['total'], 0.001);
        $this->assertSame('Efectivo', $c['pagos'][0]['medio']);

        $m = $this->actingAs($this->admin)->getJson("/caja/movimientos/detalle?tipo=movimiento&id={$plin->id}")->assertOk()->json();
        $this->assertSame(['Ingreso de caja', 'Venta de plantines', 'Plin', '99'], [$m['titulo'], $m['concepto'], $m['medio'], $m['referencia']]);
        $this->assertNull($m['pedido']);

        $cobro = \App\Models\Cobro::where('empresa_id', $this->empresa->id)->firstOrFail();
        $co2 = $this->actingAs($this->admin)->getJson("/caja/movimientos/detalle?tipo=cobro&id={$cobro->id}")->assertOk()->json();
        $this->assertSame('Cobro de crédito', $co2['titulo']);
        $this->assertEqualsWithDelta(120, $co2['monto'], 0.001);
        $this->assertEqualsWithDelta(300, $co2['deuda_total'], 0.001);
        $this->assertStringStartsWith('NV', $co2['venta']);

        $this->actingAs($this->admin)->getJson('/caja/movimientos/detalle?tipo=venta&id='.$compra->id)->assertNotFound();
        $this->actingAs($this->admin)->getJson('/caja/movimientos/detalle?tipo=otro&id='.$venta->id)->assertStatus(422);
    }

    public function test_sin_ver_todas_solo_se_ven_los_movimientos_de_los_turnos_propios_y_otra_empresa_nada(): void
    {
        [$apertura] = $this->turnoCompleto();
        $vendedor = $this->crearUsuario('vendedor', 'vendedor'.random_int(10000, 99999).'@test.local');

        $this->actingAs($vendedor)->get('/caja/movimientos')->assertInertia(fn ($p) => $p->has('entradas', 0)->has('salidas', 0));
        $this->actingAs($vendedor)->get("/caja/movimientos?turno={$apertura->id}")->assertNotFound();
        $venta = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->actingAs($vendedor)->getJson("/caja/movimientos/detalle?tipo=venta&id={$venta->id}")->assertNotFound();

        $this->crearEscenarioBase();
        $this->actingAs($this->admin)->get('/caja/movimientos')->assertInertia(fn ($p) => $p->has('entradas', 0));
        $this->actingAs($this->admin)->get("/caja/movimientos?turno={$apertura->id}")->assertNotFound();
    }

    public function test_un_usuario_limitado_a_una_sucursal_no_ve_turnos_de_otra_ni_el_pdf_de_compras_sin_permiso(): void
    {
        [$apertura] = $this->turnoCompleto();
        $compra = Compra::where('empresa_id', $this->empresa->id)->firstOrFail();

        // admin que ve todas las cajas pero solo puede entrar a la sede Alameda: el turno es de Principal
        $alameda = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Alameda', 'activo' => true]);
        $restringido = $this->crearUsuario('admin', 'adm'.random_int(10000, 99999).'@test.local');
        $restringido->sucursales()->sync([$alameda->id]);
        $this->actingAs($restringido)->get('/caja/movimientos')->assertInertia(fn ($p) => $p->has('entradas', 0));
        $this->actingAs($restringido)->get("/caja/movimientos?turno={$apertura->id}")->assertNotFound();
        $this->actingAs($restringido)->getJson("/caja/movimientos/detalle?tipo=compra&id={$compra->id}")->assertNotFound();

        // el PDF de la compra solo se ofrece a quien tiene compras.ver (el admin sí)
        $this->assertStringContainsString("/compras/{$compra->id}/pdf", $this->actingAs($this->admin)->getJson("/caja/movimientos/detalle?tipo=compra&id={$compra->id}")->json('pdf'));

        // rango demasiado largo y medio de pago mal formado
        $this->actingAs($this->admin)->get('/caja/movimientos?desde=2026-01-01&hasta=2026-06-30')->assertSessionHasErrors('hasta');
        $this->actingAs($this->admin)->post('/caja/movimientos', ['tipo' => 'ingreso', 'concepto' => 'x', 'monto' => 5, 'medio_pago_codigo' => ['efectivo', 'yape']])->assertSessionHasErrors('medio_pago_codigo');
    }

    public function test_el_historial_de_turnos_trae_entro_salio_y_saldo_por_todos_los_medios(): void
    {
        [$apertura] = $this->turnoCompleto();
        $this->actingAs($this->admin)->post('/caja/cerrar', ['monto_cierre' => null])->assertSessionHas('success');

        $this->actingAs($this->admin)->get('/caja/historial')->assertInertia(fn ($p) => $p
            ->where('historial.data.0.id', $apertura->id)
            ->where('historial.data.0.entro', fn ($v) => abs($v - 450) < 0.001)
            ->where('historial.data.0.salio', fn ($v) => abs($v - 210) < 0.001)
            ->where('historial.data.0.saldo', fn ($v) => abs($v - 240) < 0.001)
            ->where('suma.entro', fn ($v) => abs($v - 450) < 0.001)
            ->where('suma.salio', fn ($v) => abs($v - 210) < 0.001)
            ->where('suma.saldo', fn ($v) => abs($v - 240) < 0.001));
    }
}
