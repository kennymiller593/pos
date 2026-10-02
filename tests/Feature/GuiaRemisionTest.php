<?php

namespace Tests\Feature;

use App\Jobs\EnviarGuiaSunat;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\GuiaRemision;
use App\Models\MovimientoInventario;
use App\Models\SerieCorrelativo;
use App\Models\Sucursal;
use App\Models\Transferencia;
use App\Models\Ubigeo;
use App\Services\GuiaRemisionService;
use App\Services\Sunat\EnviadorGuia;
use App\Services\Sunat\RespuestaSunat;
use Greenter\Xml\Builder\DespatchBuilder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreaEscenarioPos;
use Tests\Fakes\EnviadorGuiaFalso;
use Tests\TestCase;

class GuiaRemisionTest extends TestCase
{
    use CreaEscenarioPos;

    private EnviadorGuiaFalso $enviador;

    private string $ubigeo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->crearEscenarioBase();
        $this->ubigeo = trim(Ubigeo::query()->value('codigo'));

        $this->empresa->update([
            'certificado_digital' => 'CERTIFICADO-DE-PRUEBA',
            'clave_certificado' => 'clave123',
            'usuario_sol' => 'MODDATOS',
            'clave_sol' => 'moddatos',
            'entorno_sunat' => 'beta',
            'facturacion_electronica' => true,
        ]);
        $this->sucursal->update(['direccion' => 'Av. Los Agricultores 245', 'ubigeo' => $this->ubigeo]);

        $this->enviador = new EnviadorGuiaFalso;
        $this->app->instance(EnviadorGuia::class, $this->enviador);
    }

    private function payload(array $extra = []): array
    {
        $producto = $this->crearProducto(precio: 10);
        $cliente = $this->crearCliente();

        return [
            'motivo_codigo' => '01',
            'fecha_traslado' => now()->toDateString(),
            'peso_bruto' => 12.5,
            'bultos' => 2,
            'cliente_id' => $cliente->id,
            'llegada_ubigeo' => $this->ubigeo,
            'llegada_direccion' => 'Jr. Lamas 123',
            'modalidad' => '02',
            'vehiculo_menor' => false,
            'vehiculo_placa' => 'abc-123',
            'conductor_tipo_doc' => '1',
            'conductor_numero_doc' => '44556677',
            'conductor_nombres' => 'Juan',
            'conductor_apellidos' => 'Pérez Soto',
            'conductor_licencia' => 'Q44556677',
            'observaciones' => 'Frágil',
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 3]],
            ...$extra,
        ];
    }

    private function emitir(array $extra = []): GuiaRemision
    {
        Bus::fake();

        $this->actingAs($this->admin)->post('/guias', $this->payload($extra))
            ->assertRedirect(route('guias.index'))
            ->assertSessionHas('success');

        return GuiaRemision::where('empresa_id', $this->empresa->id)->orderByDesc('correlativo')->firstOrFail();
    }

    private function enProceso(): RespuestaSunat
    {
        return new RespuestaSunat(aceptado: false, codigo: '98', mensaje: 'En proceso', xml: '<DespatchAdvice/>', hash: 'HASH123', ticket: 'TICKET-1', enProceso: true);
    }

    public function test_emitir_registra_la_guia_con_serie_t_y_no_toca_stock(): void
    {
        $guia = $this->emitir();

        $this->assertSame('T001-000001', $guia->numero());
        $this->assertSame('emitida', $guia->estado);
        $this->assertSame('pendiente', $guia->estado_sunat);
        $this->assertSame('ABC123', $guia->vehiculo_placa); // sin guion
        $this->assertSame($this->ubigeo, trim($guia->partida_ubigeo));
        $this->assertSame('Av. Los Agricultores 245', $guia->partida_direccion);
        $this->assertSame(1, $guia->detalles()->count());
        $this->assertEqualsWithDelta(12.5, (float) $guia->peso_bruto, 0.001);

        Bus::assertDispatchedAfterResponse(EnviarGuiaSunat::class, fn ($job) => $job->guiaId === $guia->id);
        $this->assertSame(0, MovimientoInventario::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());

        $this->assertSame(2, $this->emitir()->correlativo);
    }

    public function test_sunat_acepta_tras_consultar_el_ticket_y_el_xml_es_valido(): void
    {
        $guia = $this->emitir();

        $this->enviador->respuesta = $this->enProceso();
        $this->enviador->respuestaTicket = new RespuestaSunat(
            aceptado: true, codigo: '0', mensaje: 'La guía ha sido aceptada', cdrZip: 'ZIP', ticket: 'TICKET-1',
            referencia: 'https://e-factura.sunat.gob.pe/v1/contribuyente/gre/comprobantes/descargaqr?hashqr=abc',
        );

        app(GuiaRemisionService::class)->enviar($guia);
        $guia->refresh();

        $this->assertSame('aceptado', $guia->estado_sunat);
        $this->assertSame('TICKET-1', $guia->sunat_ticket);
        $this->assertSame('HASH123', $guia->hash_cpe);
        $this->assertStringContainsString('descargaqr', (string) $guia->qr_url);
        Storage::assertExists($guia->xml_url);
        Storage::assertExists($guia->cdr_url);
        $this->assertSame(1, $this->enviador->envios);
        $this->assertSame(1, $this->enviador->consultas);

        // el documento que se firma: guia 09, version 2022, transporte privado con placa y conductor
        $doc = $this->enviador->ultimaGuia;
        $this->assertSame('09', $doc->getTipoDoc());
        $this->assertSame('T001', $doc->getSerie());
        $this->assertSame('02', $doc->getEnvio()->getModTraslado());
        $this->assertSame('ABC123', $doc->getEnvio()->getVehiculo()->getPlaca());
        $this->assertSame('Q44556677', $doc->getEnvio()->getChoferes()[0]->getLicencia());

        $xml = (new DespatchBuilder(['autoescape' => false]))->build($doc);
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml));
        $this->assertStringContainsString('<cbc:ID>T001-1</cbc:ID>', $xml);
        $this->assertStringContainsString('unitCode="KGM">12.500<', $xml);
        $this->assertStringContainsString($this->ubigeo, $xml);

        // sin nube configurada, nada se copia (el disco de respaldo no existe en el test)
        $this->assertFalse(\App\Console\Commands\RespaldarEnNube::configurado());

        // una guia ya aceptada no se reenvia
        app(GuiaRemisionService::class)->enviar($guia);
        $this->assertSame(1, $this->enviador->envios);
    }

    public function test_rechazo_y_error_de_comunicacion(): void
    {
        $guia = $this->emitir();

        // SUNAT caido: queda pendiente y se puede reintentar
        $this->enviador->respuesta = new RespuestaSunat(aceptado: false, codigo: 'API', mensaje: 'Connection timed out', errorComunicacion: true);
        app(GuiaRemisionService::class)->enviar($guia);
        $this->assertSame('pendiente', $guia->fresh()->estado_sunat);

        // el reintento llega y SUNAT la rechaza al procesar el ticket
        $this->enviador->respuesta = $this->enProceso();
        $this->enviador->respuestaTicket = new RespuestaSunat(aceptado: false, codigo: '2560', mensaje: 'El peso bruto no cumple el formato', ticket: 'TICKET-1');

        $this->actingAs($this->admin)->post("/guias/{$guia->id}/sunat")->assertSessionHas('error');

        $guia->refresh();
        $this->assertSame('rechazado', $guia->estado_sunat);
        $this->assertStringContainsString('2560', $guia->sunat_mensaje);
        $this->assertSame(2, $guia->intentos);
    }

    public function test_el_cron_resuelve_las_guias_en_proceso(): void
    {
        $guia = $this->emitir();
        $guia->update(['estado_sunat' => 'en_proceso', 'sunat_ticket' => 'TICKET-9']);

        $this->enviador->respuestaTicket = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'Aceptada', ticket: 'TICKET-9');

        $this->artisan('sunat:sincronizar')->assertSuccessful();

        $this->assertSame('aceptado', $guia->fresh()->estado_sunat);
    }

    public function test_sin_facturacion_electronica_la_guia_se_guarda_pero_no_se_envia(): void
    {
        $this->empresa->update(['facturacion_electronica' => false]);
        $this->admin->unsetRelation('empresa');
        Bus::fake();

        $this->actingAs($this->admin)->post('/guias', $this->payload())->assertSessionHas('success');

        Bus::assertNotDispatchedAfterResponse(EnviarGuiaSunat::class);
        $this->assertSame('pendiente', GuiaRemision::where('empresa_id', $this->empresa->id)->firstOrFail()->estado_sunat);
    }

    public function test_transporte_privado_exige_placa_y_conductor_salvo_vehiculo_menor(): void
    {
        $this->actingAs($this->admin)
            ->post('/guias', $this->payload(['vehiculo_placa' => '', 'conductor_numero_doc' => '', 'conductor_licencia' => '']))
            ->assertSessionHasErrors(['vehiculo_placa', 'conductor_numero_doc']);

        $this->actingAs($this->admin)
            ->post('/guias', $this->payload(['conductor_licencia' => '123']))
            ->assertSessionHasErrors('conductor_licencia');

        $guia = $this->emitir(['vehiculo_menor' => true, 'vehiculo_placa' => '', 'conductor_numero_doc' => '', 'conductor_nombres' => '', 'conductor_apellidos' => '', 'conductor_licencia' => '']);

        $this->assertTrue($guia->vehiculo_menor);
        $this->assertNull($guia->vehiculo_placa);
        $this->assertNull($guia->conductor_numero_doc);

        $doc = app(GuiaRemisionService::class)->construirDespatch($guia);
        $this->assertSame(['SUNAT_Envio_IndicadorTrasladoVehiculoM1L'], $doc->getEnvio()->getIndicadores());
        $this->assertNull($doc->getEnvio()->getVehiculo());
    }

    public function test_transporte_publico_exige_transportista_distinto_a_la_empresa(): void
    {
        $this->actingAs($this->admin)
            ->post('/guias', $this->payload(['modalidad' => '01']))
            ->assertSessionHasErrors(['transportista_ruc', 'transportista_nombre']);

        $this->actingAs($this->admin)
            ->post('/guias', $this->payload(['modalidad' => '01', 'transportista_ruc' => $this->empresa->ruc, 'transportista_nombre' => 'Yo mismo']))
            ->assertSessionHasErrors('transportista_ruc');

        $guia = $this->emitir(['modalidad' => '01', 'transportista_ruc' => '20100070970', 'transportista_nombre' => 'TRANSPORTES RAPIDO S.A.C.', 'transportista_mtc' => '15123456']);

        $this->assertSame('01', trim($guia->modalidad));
        $this->assertNull($guia->vehiculo_placa); // lo del vehiculo privado no se guarda
        $this->assertNull($guia->conductor_numero_doc);

        $doc = app(GuiaRemisionService::class)->construirDespatch($guia);
        $this->assertSame('20100070970', $doc->getEnvio()->getTransportista()->getNumDoc());
        $this->assertNull($doc->getEnvio()->getChoferes());
    }

    public function test_el_destinatario_necesita_documento_y_no_puede_ser_la_propia_empresa(): void
    {
        $sinDoc = Cliente::create(['empresa_id' => $this->empresa->id, 'tipo_documento_codigo' => '0', 'numero_documento' => null, 'nombre' => 'Varios', 'limite_credito' => 0]);
        $this->actingAs($this->admin)->post('/guias', $this->payload(['cliente_id' => $sinDoc->id]))->assertSessionHas('error');

        $propio = Cliente::create(['empresa_id' => $this->empresa->id, 'tipo_documento_codigo' => '6', 'numero_documento' => $this->empresa->ruc, 'nombre' => 'Mi empresa', 'limite_credito' => 0]);
        $this->actingAs($this->admin)->post('/guias', $this->payload(['cliente_id' => $propio->id]))->assertSessionHas('error');

        $this->actingAs($this->admin)->post('/guias', $this->payload(['fecha_traslado' => now()->subDay()->toDateString()]))->assertSessionHasErrors('fecha_traslado');
        $this->actingAs($this->admin)->post('/guias', $this->payload(['peso_bruto' => 0]))->assertSessionHasErrors('peso_bruto');

        $this->assertSame(0, GuiaRemision::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_traslado_entre_sucursales_tiene_a_la_empresa_como_destinatario(): void
    {
        $destino = Sucursal::create([
            'empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Almacén', 'direccion' => 'Calle Depósito 9', 'ubigeo' => $this->ubigeo, 'activo' => true,
        ]);

        // sin destino no pasa
        $this->actingAs($this->admin)->post('/guias', $this->payload(['motivo_codigo' => '04', 'cliente_id' => null]))->assertSessionHasErrors('sucursal_destino_id');

        $guia = $this->emitir(['motivo_codigo' => '04', 'cliente_id' => null, 'llegada_ubigeo' => null, 'llegada_direccion' => null, 'sucursal_destino_id' => $destino->id]);

        $this->assertSame($this->empresa->ruc, $guia->destinatario_numero_doc);
        $this->assertSame('Calle Depósito 9', $guia->llegada_direccion);
        $this->assertSame('0000', $guia->partida_cod_local);
        $this->assertSame('0001', $guia->llegada_cod_local);

        $doc = app(GuiaRemisionService::class)->construirDespatch($guia);
        $this->assertSame('0001', $doc->getEnvio()->getLlegada()->getCodLocal());
        $this->assertSame($this->empresa->ruc, $doc->getEnvio()->getLlegada()->getRuc());
    }

    public function test_generar_guia_desde_una_venta_y_desde_una_transferencia(): void
    {
        $producto = $this->crearProducto(precio: 10);
        $presentacion = $producto->presentaciones->first();
        $this->darStock($producto, 10, 4);
        $cliente = $this->crearCliente();
        $cliente->update(['direccion' => 'Av. Cliente 500']);
        $this->abrirCaja();

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => $cliente->id, 'es_credito' => false,
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => 4]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 40, 'referencia' => null]],
        ])->assertSessionHas('success');
        $venta = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();

        $this->actingAs($this->admin)->get("/guias/crear?comprobante={$venta->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Guias/Formulario')
                ->where('inicial.motivo_codigo', '01')
                ->where('inicial.comprobante_id', $venta->id)
                ->where('inicial.cliente.id', $cliente->id)
                ->where('inicial.llegada_direccion', 'Av. Cliente 500')
                ->where('inicial.items.0.presentacion_id', $presentacion->id)
                ->where('inicial.items.0.cantidad', 4));

        $destino = Sucursal::create([
            'empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Almacén', 'direccion' => 'Calle Depósito 9', 'ubigeo' => $this->ubigeo, 'activo' => true,
        ]);
        $transferencia = Transferencia::create([
            'empresa_id' => $this->empresa->id, 'sucursal_origen_id' => $this->sucursal->id, 'sucursal_destino_id' => $destino->id,
            'usuario_id' => $this->admin->id, 'estado' => 'en_transito',
        ]);
        $transferencia->detalles()->create(['producto_id' => $producto->id, 'cantidad' => 5]);

        $this->actingAs($this->admin)->get("/guias/crear?transferencia={$transferencia->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('inicial.motivo_codigo', '04')
                ->where('inicial.sucursal_destino_id', $destino->id)
                ->where('partida.id', $this->sucursal->id)
                ->where('inicial.items.0.presentacion_id', $presentacion->id)
                ->where('inicial.items.0.cantidad', 5));

        // la guia guardada queda enlazada a la venta y la lleva como documento relacionado
        $guia = $this->emitir(['comprobante_id' => $venta->id, 'cliente_id' => $cliente->id]);
        $this->assertSame($venta->id, $guia->comprobante_id);
    }

    public function test_anular_y_permisos(): void
    {
        $guia = $this->emitir();
        $vendedor = $this->crearUsuario('vendedor', 'vend'.random_int(10000, 99999).'@test.local');

        $this->actingAs($vendedor)->get('/guias')->assertOk();
        $this->actingAs($vendedor)->post("/guias/{$guia->id}/anular", ['motivo' => 'Error'])->assertForbidden();

        $this->actingAs($this->admin)->post("/guias/{$guia->id}/anular", [])->assertSessionHasErrors('motivo');
        $this->actingAs($this->admin)->post("/guias/{$guia->id}/anular", ['motivo' => 'Datos equivocados'])->assertSessionHas('success');
        $this->assertSame('anulada', $guia->fresh()->estado);

        // anulada ya no se envia
        $this->actingAs($this->admin)->post("/guias/{$guia->id}/sunat")->assertSessionHas('error');
        $this->assertSame(0, $this->enviador->envios);
    }

    public function test_otra_empresa_no_ve_ni_toca_la_guia(): void
    {
        $guia = $this->emitir();

        $this->crearEscenarioBase(); // otra empresa con su admin

        $this->actingAs($this->admin)->get("/guias/{$guia->id}/pdf")->assertForbidden();
        $this->actingAs($this->admin)->post("/guias/{$guia->id}/sunat")->assertForbidden();
        $this->actingAs($this->admin)->post("/guias/{$guia->id}/anular", ['motivo' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->get('/guias')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('guias.data', 0));
    }

    public function test_la_sucursal_sin_direccion_no_puede_emitir_y_el_pdf_se_arma(): void
    {
        $guia = $this->emitir();

        $html = view('pdf.guia-remision', [
            'guia' => $guia->load(['empresa', 'detalles', 'sucursal', 'usuario', 'comprobante']),
            'empresa' => $guia->empresa,
            'logo' => null,
            'partidaLugar' => 'Huaral, Huaral, Lima',
            'llegadaLugar' => 'Lima, Lima, Lima',
            'qr' => null,
        ])->render();
        $this->assertStringContainsString('T001-000001', $html);
        $this->assertStringContainsString('ABC123', $html);
        $this->assertStringContainsString('AÚN NO ACEPTADA POR SUNAT', $html);

        $this->sucursal->update(['direccion' => null]);
        $this->actingAs($this->admin)->post('/guias', $this->payload())->assertSessionHas('error');
    }

    public function test_la_serie_de_guias_se_configura_en_sucursales_con_prefijo_t(): void
    {
        $this->actingAs($this->admin)->post("/sucursales/{$this->sucursal->id}/series", [
            'tipo_comprobante_codigo' => '09', 'serie' => 'G001', 'caja_id' => null, 'correlativo' => 0,
        ])->assertSessionHas('error');

        $this->actingAs($this->admin)->post("/sucursales/{$this->sucursal->id}/series", [
            'tipo_comprobante_codigo' => '09', 'serie' => 'T005', 'caja_id' => null, 'correlativo' => 40,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(SerieCorrelativo::where('empresa_id', $this->empresa->id)->where('serie', 'T005')->exists());
        $this->assertSame('T005-000041', $this->emitir()->numero());
    }
}
