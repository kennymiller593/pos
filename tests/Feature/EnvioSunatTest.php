<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\ComprobanteSunat;
use App\Models\SerieCorrelativo;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use App\Services\SunatService;
use Greenter\Model\Sale\Invoice;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioPos;
use Tests\Fakes\EnviadorSunatFalso;
use Tests\TestCase;

class EnvioSunatTest extends TestCase
{
    use CreaEscenarioPos;

    private EnviadorSunatFalso $enviador;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->crearEscenarioBase();

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

        $this->enviador = new EnviadorSunatFalso;
        $this->app->instance(EnviadorSunat::class, $this->enviador);
    }

    private function venderBoleta(): Comprobante
    {
        $producto = $this->crearProducto(precio: 10.00);
        $this->darStock($producto, 10, 4.00);
        $this->abrirCaja();

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '03',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 2]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 20.00, 'referencia' => null]],
        ])->assertSessionHas('success');

        return Comprobante::where('empresa_id', $this->empresa->id)
            ->where('tipo_comprobante_codigo', '03')
            ->latest('creado_en')
            ->firstOrFail();
    }

    public function test_la_boleta_se_envia_tras_la_venta_y_queda_aceptada(): void
    {
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: true,
            codigo: '0',
            mensaje: 'La Boleta ha sido aceptada',
            xml: '<xml-firmado/>',
            hash: 'HASH-CPE-123',
            cdrZip: 'zip-binario',
        );

        $comprobante = $this->venderBoleta();

        $registro = ComprobanteSunat::find($comprobante->id);
        $this->assertSame('aceptado', $registro->estado);
        $this->assertSame(1, (int) $registro->intentos);
        $this->assertSame('HASH-CPE-123', $registro->hash_cpe);

        $comprobante->refresh();
        $this->assertSame('aceptado', $comprobante->estado_sunat);
        $this->assertSame('HASH-CPE-123', $comprobante->hash_cpe);

        $nombre = "{$this->empresa->ruc}-03-B001-1";
        Storage::assertExists("sunat/{$this->empresa->id}/beta/{$nombre}.xml");
        Storage::assertExists("sunat/{$this->empresa->id}/beta/R-{$nombre}.zip");
    }

    public function test_el_rechazo_de_sunat_queda_registrado(): void
    {
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: false,
            codigo: '2335',
            mensaje: 'El documento ya existe',
            xml: '<xml-firmado/>',
            hash: 'HASH-X',
            errorComunicacion: false,
        );

        $comprobante = $this->venderBoleta();

        $this->assertSame('rechazado', ComprobanteSunat::find($comprobante->id)->estado);
        $this->assertSame('rechazado', $comprobante->fresh()->estado_sunat);
        $this->assertStringContainsString('2335', ComprobanteSunat::find($comprobante->id)->mensaje_sunat);
    }

    public function test_error_de_comunicacion_queda_pendiente_y_el_reenvio_lo_resuelve(): void
    {
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: false,
            codigo: '',
            mensaje: 'No se pudo conectar con SUNAT',
            errorComunicacion: true,
        );

        $comprobante = $this->venderBoleta();

        $registro = ComprobanteSunat::find($comprobante->id);
        $this->assertSame('pendiente', $registro->estado);
        $this->assertSame(1, (int) $registro->intentos);

        // el reenvio manual desde Comprobantes lo deja aceptado
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: true,
            codigo: '0',
            mensaje: 'Aceptada',
            xml: '<xml/>',
            hash: 'HASH-2',
        );

        $this->actingAs($this->admin)
            ->post("/comprobantes/{$comprobante->id}/sunat")
            ->assertSessionHas('success');

        $registro->refresh();
        $this->assertSame('aceptado', $registro->estado);
        $this->assertSame(2, (int) $registro->intentos);
    }

    public function test_el_xml_y_el_cdr_se_pueden_descargar(): void
    {
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: true,
            codigo: '0',
            mensaje: 'ok',
            xml: '<xml-firmado/>',
            hash: 'H',
            cdrZip: 'zip-binario',
        );

        $comprobante = $this->venderBoleta();
        $nombre = "{$this->empresa->ruc}-03-B001-1";

        $this->actingAs($this->admin)
            ->get("/comprobantes/{$comprobante->id}/xml")
            ->assertOk()
            ->assertDownload("{$nombre}.xml");

        $this->actingAs($this->admin)
            ->get("/comprobantes/{$comprobante->id}/cdr")
            ->assertOk()
            ->assertDownload("R-{$nombre}.zip");
    }

    public function test_sin_archivo_guardado_la_descarga_da_404(): void
    {
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: false,
            codigo: '',
            mensaje: 'sin conexión',
            errorComunicacion: true,
        );

        $comprobante = $this->venderBoleta();

        $this->actingAs($this->admin)->get("/comprobantes/{$comprobante->id}/xml")->assertNotFound();
        $this->actingAs($this->admin)->get("/comprobantes/{$comprobante->id}/cdr")->assertNotFound();
    }

    public function test_el_comando_programado_reenvia_los_pendientes_con_espera_entre_intentos(): void
    {
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: false, codigo: '', mensaje: 'SUNAT caído', errorComunicacion: true,
        );

        $comprobante = $this->venderBoleta();
        $this->assertSame(1, (int) ComprobanteSunat::find($comprobante->id)->intentos);

        // recien enviado: el comando aun no lo reintenta (espera 5 min tras el primer intento)
        // (se mira el registro propio porque la BD puede tener otros pendientes de desarrollo)
        $this->artisan('sunat:sincronizar')->assertSuccessful();
        $this->assertSame(1, (int) ComprobanteSunat::find($comprobante->id)->intentos);
        $this->assertSame('pendiente', ComprobanteSunat::find($comprobante->id)->estado);

        // pasada la espera, lo reenvia y queda aceptado
        ComprobanteSunat::where('comprobante_id', $comprobante->id)->update(['enviado_en' => now()->subMinutes(6)]);
        $this->enviador->respuesta = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'Aceptada', xml: '<x/>', hash: 'H');

        $this->artisan('sunat:sincronizar')->assertSuccessful();

        $registro = ComprobanteSunat::find($comprobante->id);
        $this->assertSame(2, (int) $registro->intentos);
        $this->assertSame('aceptado', $registro->estado);
    }

    public function test_el_reenvio_no_exige_tener_la_facturacion_activa(): void
    {
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: false, codigo: '', mensaje: 'sin conexión', errorComunicacion: true,
        );
        $comprobante = $this->venderBoleta();

        // la empresa desactiva la facturacion, pero lo ya emitido debe llegar a SUNAT igual
        $this->empresa->update(['facturacion_electronica' => false]);
        $this->enviador->respuesta = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'Aceptada', xml: '<x/>', hash: 'H');

        $this->actingAs($this->admin)
            ->post("/comprobantes/{$comprobante->id}/sunat")
            ->assertSessionHas('success');

        $this->assertSame('aceptado', ComprobanteSunat::find($comprobante->id)->estado);
    }

    public function test_un_comprobante_aceptado_no_se_reenvia(): void
    {
        $this->enviador->respuesta = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'ok', xml: '<x/>', hash: 'H');

        $comprobante = $this->venderBoleta();
        $this->assertSame(1, $this->enviador->llamadas);

        // aunque se pida de nuevo, no vuelve a llamar a SUNAT
        app(SunatService::class)->emitir($comprobante->fresh());
        $this->assertSame(1, $this->enviador->llamadas);
    }

    public function test_la_falla_al_firmar_deja_el_envio_pendiente_con_el_motivo(): void
    {
        $this->enviador->excepcion = new \RuntimeException('certificado ilegible');

        $comprobante = $this->venderBoleta();

        $registro = ComprobanteSunat::find($comprobante->id);
        $this->assertSame('pendiente', $registro->estado);
        $this->assertStringContainsString('certificado ilegible', $registro->mensaje_sunat);
    }

    public function test_el_xml_se_construye_con_los_datos_del_comprobante(): void
    {
        $this->enviador->respuesta = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'ok');
        $this->sucursal->update(['ubigeo' => '150101', 'direccion' => 'Av. Test 123']);

        $comprobante = $this->venderBoleta();

        /** @var Invoice $invoice */
        $invoice = $this->enviador->ultimoInvoice;

        $this->assertSame('03', $invoice->getTipoDoc());
        $this->assertSame('B001', $invoice->getSerie());
        $this->assertSame('1', $invoice->getCorrelativo());
        $this->assertSame($this->empresa->ruc, $invoice->getCompany()->getRuc());

        // domicilio del emisor completo para evitar las observaciones 4096-4098
        $direccion = $invoice->getCompany()->getAddress();
        $this->assertSame('150101', $direccion->getUbigueo());
        $this->assertSame('LIMA', $direccion->getDepartamento());
        $this->assertSame('LIMA', $direccion->getProvincia());
        $this->assertSame('LIMA', $direccion->getDistrito());
        $this->assertSame('Av. Test 123', $direccion->getDireccion());

        // venta sin cliente: boleta a nombre del cliente generico
        $this->assertSame('0', $invoice->getClient()->getTipoDoc());
        $this->assertSame('CLIENTES VARIOS', $invoice->getClient()->getRznSocial());

        // 20.00 con IGV -> base 16.95, IGV 3.05
        $this->assertEqualsWithDelta(16.95, $invoice->getMtoOperGravadas(), 0.001);
        $this->assertEqualsWithDelta(3.05, $invoice->getMtoIGV(), 0.001);
        $this->assertEqualsWithDelta(20.00, $invoice->getMtoImpVenta(), 0.001);

        $this->assertCount(1, $invoice->getDetails());
        $detalle = $invoice->getDetails()[0];
        $this->assertEqualsWithDelta(2.0, $detalle->getCantidad(), 0.001);
        $this->assertSame('10', $detalle->getTipAfeIgv());

        $leyenda = $invoice->getLegends()[0];
        $this->assertSame('1000', $leyenda->getCode());
        $this->assertSame('SON VEINTE CON 00/100 SOLES', $leyenda->getValue());
    }
}
