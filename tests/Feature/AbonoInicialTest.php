<?php

namespace Tests\Feature;

use App\Models\AperturaCaja;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\CuentaPorCobrar;
use App\Models\Producto;
use App\Models\SerieCorrelativo;
use App\Services\CajaService;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use Greenter\Model\Sale\Invoice;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreaEscenarioPos;
use Tests\Fakes\EnviadorSunatFalso;
use Tests\TestCase;

/**
 * "Paga ahora (a cuenta)": al fiar una venta el cliente puede dejar un abono en el momento.
 * El abono entra como cobro del turno, la deuda nace por el saldo y la anulación de la venta lo revierte.
 */
class AbonoInicialTest extends TestCase
{
    use CreaEscenarioPos;

    private Producto $producto;

    private AperturaCaja $apertura;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();

        $this->producto = $this->crearProducto(precio: 30.00);
        $this->darStock($this->producto, 10, 20.00);
        $this->apertura = $this->abrirCaja(100);
        $this->cliente = $this->crearCliente(limiteCredito: 100);
    }

    /** Venta fiada de $cantidad unidades (2 = S/ 60) con el abono indicado (null = sin abono). */
    private function venderFiado(?array $abono, float $cantidad = 2, array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $this->cliente->id,
            'es_credito' => true,
            'items' => [['presentacion_id' => $this->producto->presentaciones->first()->id, 'cantidad' => $cantidad]],
            'pagos' => [],
            'abono' => $abono,
            ...$extra,
        ]);
    }

    private function abono(float $monto, string $medio = 'yape', ?string $referencia = 'YP-1'): array
    {
        return ['medio_pago_codigo' => $medio, 'monto' => $monto, 'referencia' => $referencia];
    }

    private function cuentaDelCliente(): CuentaPorCobrar
    {
        return CuentaPorCobrar::where('cliente_id', $this->cliente->id)->firstOrFail();
    }

    public function test_el_abono_queda_como_cobro_del_turno_y_la_deuda_nace_por_el_saldo(): void
    {
        $this->venderFiado($this->abono(20))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($s) => str_contains($s, 'a cuenta S/ 20.00') && str_contains($s, 'saldo S/ 40.00'))
            ->assertSessionHas('venta.abono', fn ($v) => abs($v - 20) < 0.001)
            ->assertSessionHas('venta.abono_medio', 'Yape')
            ->assertSessionHas('venta.saldo', fn ($v) => abs($v - 40) < 0.001);

        $cuenta = $this->cuentaDelCliente();
        $this->assertEqualsWithDelta(60, (float) $cuenta->monto_total, 0.001);
        $this->assertEqualsWithDelta(20, (float) $cuenta->monto_pagado, 0.001);
        $this->assertSame('parcial', $cuenta->estado);

        $this->assertSame(1, $cuenta->cobros()->count());
        $cobro = $cuenta->cobros()->first();
        $this->assertTrue($cobro->abono_inicial);
        $this->assertSame($this->apertura->id, $cobro->apertura_id);
        $this->assertSame('yape', $cobro->medio_pago_codigo);
        $this->assertSame('YP-1', $cobro->referencia);
        $this->assertEqualsWithDelta(20, (float) $cobro->monto, 0.001);

        // el abono no es un pago de la venta: la venta sigue siendo al credito
        $comprobante = $cuenta->comprobante;
        $this->assertTrue($comprobante->es_credito);
        $this->assertSame(0, $comprobante->pagos()->count());
    }

    public function test_sin_abono_la_venta_fiada_sigue_igual_que_antes(): void
    {
        $this->venderFiado(null)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('venta.abono', fn ($v) => abs($v) < 0.001)
            ->assertSessionHas('venta', fn ($v) => $v['abono_medio'] === null)
            ->assertSessionHas('venta.saldo', fn ($v) => abs($v - 60) < 0.001);

        $cuenta = $this->cuentaDelCliente();
        $this->assertSame('pendiente', $cuenta->estado);
        $this->assertEqualsWithDelta(0, (float) $cuenta->monto_pagado, 0.001);
        $this->assertSame(0, $cuenta->cobros()->count());
    }

    public function test_un_abono_que_cubre_el_total_se_rechaza_porque_es_una_venta_al_contado(): void
    {
        foreach ([60, 70] as $monto) {
            $this->venderFiado($this->abono($monto))
                ->assertSessionHas('error', fn ($e) => str_contains($e, 'contado'));
        }

        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(0, Cobro::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_solo_el_saldo_fiado_consume_la_linea_de_credito(): void
    {
        $this->cliente->update(['limite_credito' => 50]);

        // 60 de venta con 20 a cuenta: quedan 40 fiados, dentro del limite de 50
        $this->venderFiado($this->abono(20))->assertSessionHasNoErrors()->assertSessionHas('success');

        // otro cliente con el mismo limite: 60 con 5 a cuenta deja 55 fiados, excede
        $this->cliente = $this->crearCliente(limiteCredito: 50);
        $this->venderFiado($this->abono(5))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'excede') && str_contains($e, 'S/ 55.00'));

        $this->assertSame(0, CuentaPorCobrar::where('cliente_id', $this->cliente->id)->count());
    }

    public function test_el_abono_se_valida_en_la_entrada(): void
    {
        $this->venderFiado($this->abono(0))->assertSessionHasErrors('abono.monto');
        $this->venderFiado($this->abono(20, medio: 'bitcoin'))->assertSessionHasErrors('abono.medio_pago_codigo');

        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_el_abono_en_efectivo_entra_al_cuadre_de_caja_como_cobro(): void
    {
        $this->venderFiado($this->abono(20, medio: 'efectivo', referencia: null))->assertSessionHasNoErrors();

        $medios = collect(app(CajaService::class)->resumenPorMedio($this->apertura->fresh()))->keyBy('codigo');

        $this->assertEqualsWithDelta(20, $medios['efectivo']['cobros'], 0.001);
        $this->assertEqualsWithDelta(0, $medios['efectivo']['ventas'], 0.001);
        $this->assertEqualsWithDelta(120, $medios['efectivo']['esperado'], 0.001);
        $this->assertEqualsWithDelta(120, app(CajaService::class)->resumen($this->apertura->fresh())['esperado'], 0.001);
    }

    public function test_el_cobro_posterior_solo_puede_llegar_hasta_el_saldo(): void
    {
        $this->venderFiado($this->abono(20))->assertSessionHasNoErrors();
        $cuenta = $this->cuentaDelCliente();

        $this->actingAs($this->admin)
            ->from('/cuentas-por-cobrar')
            ->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 41, 'medio_pago_codigo' => 'efectivo'])
            ->assertSessionHasErrors('monto');

        $this->actingAs($this->admin)
            ->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 40, 'medio_pago_codigo' => 'efectivo'])
            ->assertSessionHas('success');

        $cuenta->refresh();
        $this->assertSame('pagado', $cuenta->estado);
        $this->assertEqualsWithDelta(60, (float) $cuenta->monto_pagado, 0.001);
        $this->assertSame(2, $cuenta->cobros()->count());
        $this->assertSame(1, $cuenta->cobros()->where('abono_inicial', true)->count());
    }

    public function test_anular_la_venta_revierte_el_abono_pero_no_si_ya_hubo_cobros_posteriores(): void
    {
        // solo con el abono inicial: la anulacion deshace todo
        $this->venderFiado($this->abono(20))->assertSessionHasNoErrors();
        $cuenta = $this->cuentaDelCliente();

        $this->actingAs($this->admin)
            ->post("/comprobantes/{$cuenta->comprobante_id}/anular", ['motivo' => 'Cliente se arrepintió'])
            ->assertSessionHas('success');

        $this->assertSame('anulado', Comprobante::find($cuenta->comprobante_id)->estado);
        $this->assertSame(0, Cobro::where('cuenta_id', $cuenta->id)->count());
        $this->assertNull(CuentaPorCobrar::find($cuenta->id));
        $this->assertEqualsWithDelta(10, $this->stockDe($this->producto), 0.001);

        // con un cobro posterior desde Cuentas por cobrar ya no se puede anular
        $this->venderFiado($this->abono(20))->assertSessionHasNoErrors();
        $cuenta = $this->cuentaDelCliente();
        $this->actingAs($this->admin)
            ->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 10, 'medio_pago_codigo' => 'efectivo'])
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->post("/comprobantes/{$cuenta->comprobante_id}/anular", ['motivo' => 'Intento'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'cobros registrados'));

        $this->assertSame('emitido', Comprobante::find($cuenta->comprobante_id)->estado);
        $this->assertSame(2, Cobro::where('cuenta_id', $cuenta->id)->count());
        $this->assertEqualsWithDelta(8, $this->stockDe($this->producto), 0.001);
    }

    public function test_el_ticket_y_el_a4_muestran_lo_pagado_a_cuenta_y_el_saldo(): void
    {
        $this->venderFiado($this->abono(20))->assertSessionHasNoErrors();
        $comprobanteId = $this->cuentaDelCliente()->comprobante_id;

        $this->actingAs($this->admin)->get("/comprobantes/{$comprobanteId}/ticket?formato=html")
            ->assertOk()
            ->assertSee('VENTA AL CRÉDITO')
            ->assertSee('A CUENTA (Yape)')
            ->assertSee('SALDO')
            ->assertSee('20.00')
            ->assertSee('40.00');

        // el A4 se genera con el abono sin romperse (el contenido es PDF, no se puede leer)
        $pdf = $this->actingAs($this->admin)->get("/comprobantes/{$comprobanteId}/a4")->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    /** Empresa con facturación electrónica y serie de boletas; SUNAT simulada acepta todo lo que se envía. */
    private function activarBoletasElectronicas(): EnviadorSunatFalso
    {
        Storage::fake('local');
        $this->empresa->update([
            'certificado_digital' => 'CERTIFICADO-DE-PRUEBA',
            'clave_certificado' => 'clave123',
            'usuario_sol' => 'MODDATOS',
            'clave_sol' => 'moddatos',
            'entorno_sunat' => 'beta',
            'facturacion_electronica' => true,
        ]);
        SerieCorrelativo::create([
            'empresa_id' => $this->empresa->id,
            'sucursal_id' => $this->sucursal->id,
            'tipo_comprobante_codigo' => '03',
            'serie' => 'B001',
            'correlativo' => 0,
        ]);
        $enviador = new EnviadorSunatFalso;
        $enviador->respuesta = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'ok', xml: '<xml/>', hash: 'HASH');
        $this->app->instance(EnviadorSunat::class, $enviador);

        return $enviador;
    }

    public function test_el_xml_a_sunat_lleva_como_credito_solo_el_saldo_pendiente(): void
    {
        $enviador = $this->activarBoletasElectronicas();

        $this->venderFiado($this->abono(20), extra: ['tipo_comprobante_codigo' => '03'])->assertSessionHasNoErrors();

        /** @var Invoice $invoice */
        $invoice = $enviador->ultimoInvoice;
        $this->assertSame('03', $invoice->getTipoDoc());
        $this->assertEqualsWithDelta(60, $invoice->getMtoImpVenta(), 0.001);
        $this->assertSame('Credito', $invoice->getFormaPago()->getTipo());
        $this->assertEqualsWithDelta(40, $invoice->getFormaPago()->getMonto(), 0.001);
        $this->assertCount(1, $invoice->getCuotas());
        $this->assertEqualsWithDelta(40, $invoice->getCuotas()[0]->getMonto(), 0.001);
    }

    public function test_con_la_baja_en_proceso_en_sunat_no_se_cobra_y_al_confirmarse_solo_se_revierte_el_abono(): void
    {
        $enviador = $this->activarBoletasElectronicas();
        $this->venderFiado($this->abono(20), extra: ['tipo_comprobante_codigo' => '03'])->assertSessionHasNoErrors();
        $cuenta = $this->cuentaDelCliente();

        // SUNAT recibe la baja pero la deja en proceso: la boleta sigue emitida y la deuda viva
        $enviador->respuestaBaja = new RespuestaSunat(aceptado: false, codigo: '98', mensaje: 'En proceso', ticket: 'T-1', enProceso: true);
        $enviador->respuestaTicket = new RespuestaSunat(aceptado: false, codigo: '98', mensaje: 'Procesando', ticket: 'T-1', enProceso: true);
        $this->actingAs($this->admin)
            ->post("/comprobantes/{$cuenta->comprobante_id}/anular", ['motivo' => 'Error'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'procesando'));
        $this->assertSame('emitido', Comprobante::find($cuenta->comprobante_id)->estado);

        // mientras tanto nadie puede cobrar esa deuda: ese dinero quedaría sin respaldo al confirmarse la baja
        $this->actingAs($this->admin)
            ->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 10, 'medio_pago_codigo' => 'efectivo'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'anulación'));
        $this->assertSame(1, Cobro::where('cuenta_id', $cuenta->id)->count());

        // SUNAT confirma: se anula, el abono se revierte y la deuda desaparece
        $enviador->respuestaTicket = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'Baja aceptada', ticket: 'T-1');
        $this->actingAs($this->admin)->post("/comprobantes/{$cuenta->comprobante_id}/sunat")->assertSessionHas('success');

        $this->assertSame('anulado', Comprobante::find($cuenta->comprobante_id)->estado);
        $this->assertSame(0, Cobro::where('cuenta_id', $cuenta->id)->count());
        $this->assertNull(CuentaPorCobrar::find($cuenta->id));
        $this->assertEqualsWithDelta(10, $this->stockDe($this->producto), 0.001);
    }

    public function test_la_venta_repetida_con_la_misma_clave_no_duplica_el_abono(): void
    {
        $clave = Str::uuid()->toString();

        $this->venderFiado($this->abono(20), extra: ['clave' => $clave])->assertSessionHas('success', fn ($s) => str_contains($s, 'a cuenta'));
        $this->venderFiado($this->abono(20), extra: ['clave' => $clave])
            ->assertSessionHas('success', fn ($s) => str_contains($s, 'ya estaba registrada'))
            ->assertSessionHas('venta.abono', fn ($v) => abs($v - 20) < 0.001)
            ->assertSessionHas('venta.saldo', fn ($v) => abs($v - 40) < 0.001);

        $this->assertSame(1, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(1, Cobro::where('empresa_id', $this->empresa->id)->count());

        // la misma clave con otro abono es otra venta
        $this->venderFiado($this->abono(25), extra: ['clave' => $clave])->assertSessionHas('success', fn ($s) => str_contains($s, 'a cuenta S/ 25.00'));
        $this->assertSame(2, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(2, Cobro::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_en_una_venta_al_contado_el_abono_se_ignora(): void
    {
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $this->cliente->id,
            'es_credito' => false,
            'items' => [['presentacion_id' => $this->producto->presentaciones->first()->id, 'cantidad' => 2]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 60, 'referencia' => null]],
            'abono' => $this->abono(20),
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('venta.abono', fn ($v) => abs($v) < 0.001)
            ->assertSessionHas('venta', fn ($v) => $v['saldo'] === null && $v['abono_medio'] === null);

        $this->assertSame(0, Cobro::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(0, CuentaPorCobrar::where('empresa_id', $this->empresa->id)->count());
    }
}
