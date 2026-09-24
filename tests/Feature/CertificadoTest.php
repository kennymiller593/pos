<?php

namespace Tests\Feature;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Comprobante;
use App\Models\SerieCorrelativo;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use App\Support\CertificadoDigital;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscenarioPos;
use Tests\Fakes\EnviadorSunatFalso;
use Tests\TestCase;

class CertificadoTest extends TestCase
{
    use CreaEscenarioPos;

    private string $pem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->pem = file_get_contents(base_path('storage/certificado_beta_sunat.pem'));
    }

    private function datosEmpresa(array $extra = []): array
    {
        return [
            'razon_social' => $this->empresa->razon_social,
            'nombre_comercial' => null,
            'regimen_tributario' => $this->empresa->regimen_tributario,
            'rubro_codigo' => $this->empresa->rubro_codigo,
            'usuario_sol' => 'MODDATOS',
            'clave_sol' => null,
            'clave_certificado' => null,
            'certificado_digital' => null,
            'entorno_sunat' => null,
            ...$extra,
        ];
    }

    public function test_el_certificado_se_valida_se_cifra_y_registra_su_vencimiento(): void
    {
        $this->actingAs($this->admin)->put('/empresa', $this->datosEmpresa(['certificado_digital' => $this->pem]))
            ->assertSessionHas('success');

        $empresa = $this->empresa->fresh();
        $this->assertSame('2036-07-17', $empresa->certificado_vence_en->toDateString());
        $this->assertStringStartsWith('-----BEGIN', $empresa->certificado_digital); // descifrado por el cast
        $this->assertStringNotContainsString('BEGIN', $empresa->getRawOriginal('certificado_digital')); // cifrado en la BD
        $this->assertDatabaseHas('auditoria', ['entidad_id' => $empresa->id, 'accion' => 'empresa.certificado_cargado']);

        // y el firmador lo puede usar
        $this->assertStringContainsString('BEGIN CERTIFICATE', CertificadoDigital::pem($empresa->certificado_digital, null));
    }

    public function test_un_certificado_ilegible_o_vencido_se_rechaza(): void
    {
        $this->actingAs($this->admin)->put('/empresa', $this->datosEmpresa(['certificado_digital' => 'CERTIFICADO-DE-PRUEBA']))
            ->assertSessionHasErrors('certificado_digital');

        $this->assertNull($this->empresa->fresh()->certificado_vence_en);

        $this->expectException(ErrorDeNegocio::class);
        CertificadoDigital::analizar('-----BEGIN CERTIFICATE-----\nbasura\n-----END CERTIFICATE-----', null);
    }

    public function test_no_se_cambia_de_entorno_con_la_facturacion_activa(): void
    {
        $this->empresa->update(['facturacion_electronica' => true, 'entorno_sunat' => 'beta']);

        $this->actingAs($this->admin)->put('/empresa', $this->datosEmpresa(['entorno_sunat' => 'produccion']))
            ->assertSessionHasErrors('entorno_sunat');
        $this->assertSame('beta', $this->empresa->fresh()->entorno_sunat);

        $this->empresa->update(['facturacion_electronica' => false]);
        $this->actingAs($this->admin->fresh())->put('/empresa', $this->datosEmpresa(['entorno_sunat' => 'produccion']))
            ->assertSessionHas('success');
        $this->assertSame('produccion', $this->empresa->fresh()->entorno_sunat);
        $this->assertDatabaseHas('auditoria', ['entidad_id' => $this->empresa->id, 'accion' => 'empresa.entorno_sunat']);
    }

    public function test_el_comando_cifra_los_certificados_guardados_en_claro(): void
    {
        DB::table('empresas')->where('id', $this->empresa->id)->update(['certificado_digital' => $this->pem]);

        $this->artisan('empresa:cifrar-certificados')->assertSuccessful();

        $crudo = DB::table('empresas')->where('id', $this->empresa->id)->value('certificado_digital');
        $this->assertStringNotContainsString('BEGIN', $crudo);
        $this->assertSame($this->pem, Crypt::decryptString($crudo));
        $this->assertSame('2036-07-17', $this->empresa->fresh()->certificado_vence_en->toDateString());

        // volver a correrlo no lo cifra dos veces
        $this->artisan('empresa:cifrar-certificados')->assertSuccessful();
        $this->assertSame($this->pem, Crypt::decryptString(DB::table('empresas')->where('id', $this->empresa->id)->value('certificado_digital')));
    }

    public function test_el_ticket_en_beta_avisa_que_no_tiene_valor_tributario(): void
    {
        $this->empresa->update(['facturacion_electronica' => true, 'entorno_sunat' => 'beta', 'certificado_digital' => $this->pem]);
        SerieCorrelativo::create([
            'empresa_id' => $this->empresa->id, 'sucursal_id' => $this->sucursal->id,
            'tipo_comprobante_codigo' => '03', 'serie' => 'B001', 'correlativo' => 0,
        ]);
        $enviador = new EnviadorSunatFalso;
        $enviador->respuesta = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'ok');
        $this->app->instance(EnviadorSunat::class, $enviador);

        $producto = $this->crearProducto(precio: 10.00);
        $this->darStock($producto, 5, 4.00);
        $this->abrirCaja();
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '03', 'cliente_id' => null, 'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 10, 'referencia' => null]],
        ])->assertSessionHas('success');
        $boleta = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();

        $html = $this->actingAs($this->admin)->get("/comprobantes/{$boleta->id}/ticket?formato=html")->getContent();
        $this->assertStringContainsString('AMBIENTE DE PRUEBAS', $html);
    }
}
