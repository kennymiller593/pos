<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\MovimientoPuntos;
use App\Models\Producto;
use App\Models\SerieCorrelativo;
use App\Services\PuntosService;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\Fakes\EnviadorSunatFalso;
use Tests\TestCase;

class PuntosClientesTest extends TestCase
{
    use CreaEscenarioPos;

    private Producto $producto;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();

        // 1 punto por cada S/ 10 de compra; cada punto vale S/ 0.50
        $this->empresa->update(['puntos_activo' => true, 'puntos_soles_por_punto' => 10, 'puntos_valor' => 0.50, 'puntos_minimo_canje' => 0]);

        $this->producto = $this->crearProducto(precio: 10);
        $this->darStock($this->producto, 500, 4);
        $this->abrirCaja();
        $this->cliente = $this->crearCliente(limiteCredito: 500);
    }

    /** Venta al contado en efectivo; $descuento va en la unica linea y $canje son los puntos usados. */
    private function vender(?Cliente $cliente, float $cantidad, float $descuento = 0, int $canje = 0, string $tipo = '00'): TestResponse
    {
        return $this->actingAs($this->admin->fresh())->post('/pos/ventas', [
            'tipo_comprobante_codigo' => $tipo,
            'cliente_id' => $cliente?->id,
            'es_credito' => false,
            'puntos_canjeados' => $canje,
            'items' => [['presentacion_id' => $this->producto->presentaciones->first()->id, 'cantidad' => $cantidad, 'descuento' => $descuento]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => round($cantidad * 10 - $descuento, 2), 'referencia' => null]],
        ]);
    }

    private function saldo(): int
    {
        return (int) $this->cliente->fresh()->puntos;
    }

    private function darPuntos(int $puntos): void
    {
        app(PuntosService::class)->ajustar($this->cliente, $puntos, 'Saldo inicial de prueba', $this->admin);
    }

    private function ultimaVenta(): Comprobante
    {
        return Comprobante::where('empresa_id', $this->empresa->id)->where('tipo_comprobante_codigo', '!=', '07')->latest('creado_en')->firstOrFail();
    }

    public function test_el_cliente_gana_puntos_al_comprar(): void
    {
        $respuesta = $this->vender($this->cliente, 5)->assertSessionHas('success'); // S/ 50 -> 5 puntos

        $this->assertSame(5, $this->saldo());
        $movimiento = MovimientoPuntos::where('cliente_id', $this->cliente->id)->firstOrFail();
        $this->assertSame(['ganado', 5, 5], [$movimiento->tipo, $movimiento->puntos, $movimiento->saldo]);
        $this->assertSame($this->ultimaVenta()->id, $movimiento->comprobante_id);
        // el POS avisa lo que gano para decirselo al cliente
        $respuesta->assertSessionHas('venta', fn ($venta) => $venta['puntos'] === ['ganados' => 5, 'canjeados' => 0, 'saldo' => 5]);

        // S/ 39 -> 3 puntos: no se redondea hacia arriba
        $this->vender($this->cliente, 4, descuento: 1)->assertSessionHas('success');
        $this->assertSame(8, $this->saldo());
    }

    public function test_sin_cliente_o_con_el_programa_apagado_no_hay_puntos(): void
    {
        $this->vender(null, 5)->assertSessionHas('success')->assertSessionHas('venta', fn ($venta) => $venta['puntos'] === null);

        $this->empresa->update(['puntos_activo' => false]);
        $this->vender($this->cliente, 5)->assertSessionHas('success');

        $this->assertSame(0, $this->saldo());
        $this->assertSame(0, MovimientoPuntos::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_canjear_puntos_los_descuenta_y_la_venta_gana_sobre_lo_pagado(): void
    {
        $this->darPuntos(20);

        // 10 puntos = S/ 5.00 de descuento: paga 45 y gana 4 puntos
        $this->vender($this->cliente, 5, descuento: 5, canje: 10)
            ->assertSessionHas('success')
            ->assertSessionHas('venta', fn ($venta) => $venta['puntos'] === ['ganados' => 4, 'canjeados' => 10, 'saldo' => 14]);

        $this->assertSame(14, $this->saldo());
        $this->assertEqualsWithDelta(45.0, (float) $this->ultimaVenta()->total, 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $this->ultimaVenta()->total_descuentos, 0.001);

        $tipos = MovimientoPuntos::where('comprobante_id', $this->ultimaVenta()->id)->orderBy('saldo')->pluck('puntos', 'tipo')->all();
        $this->assertSame(['canje' => -10, 'ganado' => 4], $tipos);
    }

    public function test_un_canje_invalido_no_registra_la_venta(): void
    {
        $this->darPuntos(20);
        $ventasAntes = Comprobante::where('empresa_id', $this->empresa->id)->count();

        // mas puntos de los que tiene
        $this->vender($this->cliente, 5, descuento: 15, canje: 30)->assertSessionHas('error', "{$this->cliente->nombre} solo tiene 20 puntos.");
        // el descuento de la venta no cubre el canje: no se queman puntos sin descuento
        $this->vender($this->cliente, 5, descuento: 2, canje: 10)->assertSessionHas('error');
        // sin cliente no hay de donde canjear
        $this->vender(null, 5, descuento: 5, canje: 10)->assertSessionHas('error', 'Para canjear puntos elige al cliente.');
        // por debajo del minimo
        $this->empresa->update(['puntos_minimo_canje' => 15]);
        $this->vender($this->cliente, 5, descuento: 5, canje: 10)->assertSessionHas('error', 'Se canjea desde 15 puntos.');
        // con el programa apagado
        $this->empresa->update(['puntos_activo' => false, 'puntos_minimo_canje' => 0]);
        $this->vender($this->cliente, 5, descuento: 5, canje: 10)->assertSessionHas('error', 'El programa de puntos no está activo.');

        $this->assertSame($ventasAntes, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(20, $this->saldo());
    }

    public function test_anular_la_venta_devuelve_lo_canjeado_y_quita_lo_ganado(): void
    {
        $this->darPuntos(20);
        $this->vender($this->cliente, 5, descuento: 5, canje: 10)->assertSessionHas('success');
        $this->assertSame(14, $this->saldo());

        $this->actingAs($this->admin)->post("/comprobantes/{$this->ultimaVenta()->id}/anular", ['motivo' => 'Error de digitación']);

        $this->assertSame('anulado', $this->ultimaVenta()->estado);
        $this->assertSame(20, $this->saldo());
        $anulacion = MovimientoPuntos::where('cliente_id', $this->cliente->id)->where('tipo', 'anulacion')->firstOrFail();
        $this->assertSame(6, $anulacion->puntos); // +10 devueltos -4 ganados
    }

    public function test_si_ya_gasto_los_puntos_la_anulacion_no_deja_saldo_negativo(): void
    {
        $this->vender($this->cliente, 5)->assertSessionHas('success'); // gana 5
        app(PuntosService::class)->ajustar($this->cliente, -4, 'Premio entregado', $this->admin); // le queda 1

        $this->actingAs($this->admin)->post("/comprobantes/{$this->ultimaVenta()->id}/anular", ['motivo' => 'Devolvió todo']);

        $this->assertSame('anulado', $this->ultimaVenta()->estado);
        $this->assertSame(0, $this->saldo());
        $this->assertSame(-1, MovimientoPuntos::where('cliente_id', $this->cliente->id)->where('tipo', 'anulacion')->value('puntos'));
    }

    public function test_una_nota_de_credito_parcial_quita_los_puntos_de_lo_devuelto(): void
    {
        Storage::fake('local');
        $this->empresa->update([
            'certificado_digital' => 'CERTIFICADO-DE-PRUEBA', 'clave_certificado' => 'clave123', 'usuario_sol' => 'MODDATOS',
            'clave_sol' => 'moddatos', 'entorno_sunat' => 'beta', 'facturacion_electronica' => true,
        ]);
        SerieCorrelativo::create([
            'empresa_id' => $this->empresa->id, 'sucursal_id' => $this->sucursal->id,
            'tipo_comprobante_codigo' => '03', 'serie' => 'B001', 'correlativo' => 0,
        ]);
        $enviador = new EnviadorSunatFalso;
        $enviador->respuesta = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'Aceptada', xml: '<xml/>', hash: 'HASH');
        $this->app->instance(EnviadorSunat::class, $enviador);

        $this->vender($this->cliente, 5, tipo: '03')->assertSessionHas('success'); // S/ 50 -> 5 puntos
        $boleta = $this->ultimaVenta();
        $this->assertSame(5, $this->saldo());

        // devuelve 2 de 5 unidades (S/ 20 de 50): pierde 2 de los 5 puntos
        $this->actingAs($this->admin->fresh())->post("/comprobantes/{$boleta->id}/nota-credito", [
            'motivo' => '07',
            'items' => [['detalle_id' => $boleta->detalles()->first()->id, 'cantidad' => 2]],
        ])->assertSessionHas('success');

        $this->assertSame(3, $this->saldo());
        $devolucion = MovimientoPuntos::where('cliente_id', $this->cliente->id)->where('tipo', 'devolucion')->firstOrFail();
        $this->assertSame(-2, $devolucion->puntos);
        $this->assertSame($boleta->id, $devolucion->comprobante_id);
    }

    public function test_ajuste_manual_de_puntos_con_motivo(): void
    {
        $ajustar = fn (int $puntos, string $concepto = 'Regalo por su cumpleaños') => $this->actingAs($this->admin)
            ->post("/clientes/{$this->cliente->id}/puntos", ['puntos' => $puntos, 'concepto' => $concepto]);

        $ajustar(30)->assertSessionHas('success', "Se sumaron 30 puntos a {$this->cliente->nombre}.");
        $this->assertSame(30, $this->saldo());

        $ajustar(-10, 'Canjeó una gorra')->assertSessionHas('success');
        $this->assertSame(20, $this->saldo());

        // no se puede restar mas de lo que tiene
        $ajustar(-50)->assertSessionHas('error');
        $this->assertSame(20, $this->saldo());

        // el motivo es obligatorio y cero no es un ajuste
        $ajustar(5, '')->assertSessionHasErrors('concepto');
        $ajustar(0)->assertSessionHasErrors('puntos');

        $this->assertSame(
            ['Canjeó una gorra', 'Regalo por su cumpleaños'],
            MovimientoPuntos::where('cliente_id', $this->cliente->id)->where('tipo', 'ajuste')->orderBy('concepto')->pluck('concepto')->all(),
        );
        $this->assertDatabaseHas('auditoria', ['empresa_id' => $this->empresa->id, 'accion' => 'puntos.ajuste']);
    }

    public function test_solo_el_administrador_configura_el_programa_y_ajusta_puntos(): void
    {
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');

        $this->actingAs($cajero)->put('/programa-puntos', ['activo' => true, 'soles_por_punto' => 1, 'valor' => 5])->assertForbidden();
        $this->actingAs($cajero)->post("/clientes/{$this->cliente->id}/puntos", ['puntos' => 100, 'concepto' => 'x'])->assertForbidden();
        $this->assertSame(0, $this->saldo());

        $this->actingAs($this->admin)->put('/programa-puntos', ['activo' => true, 'soles_por_punto' => 20, 'valor' => 1.5, 'minimo_canje' => 50])
            ->assertSessionHas('success');

        $empresa = $this->empresa->fresh();
        $this->assertTrue($empresa->puntos_activo);
        $this->assertSame(['20.00', '1.50', 50], [$empresa->puntos_soles_por_punto, $empresa->puntos_valor, $empresa->puntos_minimo_canje]);

        // valores sin sentido no pasan
        $this->actingAs($this->admin)->put('/programa-puntos', ['activo' => true, 'soles_por_punto' => 0, 'valor' => 0])
            ->assertSessionHasErrors(['soles_por_punto', 'valor']);

        // apagarlo conserva los puntos y las reglas
        $this->darPuntos(12);
        $this->actingAs($this->admin)->put('/programa-puntos', ['activo' => false, 'soles_por_punto' => 20, 'valor' => 1.5, 'minimo_canje' => 50])
            ->assertSessionHas('success');
        $this->assertFalse($this->empresa->fresh()->puntos_activo);
        $this->assertSame(12, $this->saldo());
    }

    public function test_no_se_tocan_los_puntos_de_un_cliente_de_otra_empresa(): void
    {
        $ajeno = $this->cliente;
        $this->crearEscenarioBase(); // otra empresa, otro admin

        $this->actingAs($this->admin)->post("/clientes/{$ajeno->id}/puntos", ['puntos' => 100, 'concepto' => 'Intento'])->assertForbidden();
        $this->actingAs($this->admin)->get("/clientes/{$ajeno->id}")->assertForbidden();
        $this->assertSame(0, (int) $ajeno->fresh()->puntos);
    }

    public function test_el_pos_recibe_las_reglas_y_los_puntos_del_cliente(): void
    {
        $this->darPuntos(40);

        $this->actingAs($this->admin)->get('/pos')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('puntos.soles_por_punto', fn ($v) => (float) $v === 10.0)
            ->where('puntos.valor', fn ($v) => (float) $v === 0.5)
            ->where('puntos.minimo_canje', 0));

        $this->actingAs($this->admin)->getJson('/pos/clientes?buscar='.urlencode($this->cliente->nombre))
            ->assertOk()->assertJsonPath('0.puntos', 40);

        $this->empresa->update(['puntos_activo' => false]);
        $this->actingAs($this->admin->fresh())->get('/pos')->assertInertia(fn (Assert $pagina) => $pagina->where('puntos', null));
    }
}
