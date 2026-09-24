<?php

namespace Tests\Feature;

use App\Mail\ComprobanteEmitido;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\Rubro;
use App\Services\SuscripcionService;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class CorreoComprobanteTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->crearEscenarioBase();
    }

    private function vender(?string $clienteId = null): Comprobante
    {
        $producto = $this->crearProducto(precio: 10.00);
        $this->darStock($producto, 5, 4.00);
        $this->abrirCaja();

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $clienteId,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 10, 'referencia' => null]],
        ])->assertSessionHas('success');

        return Comprobante::where('empresa_id', $this->empresa->id)->latest('creado_en')->firstOrFail();
    }

    public function test_el_comprobante_se_envia_al_correo_indicado(): void
    {
        $comprobante = $this->vender();

        $this->actingAs($this->admin)
            ->post("/comprobantes/{$comprobante->id}/correo", ['email' => 'cliente@correo.com'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'cliente@correo.com'));

        Mail::assertSent(ComprobanteEmitido::class, fn (ComprobanteEmitido $correo) => $correo->hasTo('cliente@correo.com')
            && $correo->comprobante->id === $comprobante->id
            && str_contains($correo->envelope()->subject, 'NV01-000001'));
    }

    public function test_el_correo_se_guarda_en_el_cliente_si_no_tenia(): void
    {
        $cliente = $this->crearCliente();
        $this->assertNull($cliente->email);

        $comprobante = $this->vender($cliente->id);

        $this->actingAs($this->admin)
            ->post("/comprobantes/{$comprobante->id}/correo", ['email' => 'nuevo@correo.com', 'guardar_en_cliente' => true])
            ->assertSessionHas('success');

        $this->assertSame('nuevo@correo.com', $cliente->fresh()->email);
    }

    public function test_correo_invalido_o_de_otra_empresa(): void
    {
        $comprobante = $this->vender();

        $this->actingAs($this->admin)
            ->post("/comprobantes/{$comprobante->id}/correo", ['email' => 'no-es-correo'])
            ->assertSessionHasErrors('email');
        Mail::assertNothingSent();

        $otro = $this->crearUsuario('admin', 'otro'.random_int(10000, 99999).'@test.local');
        $otro->forceFill(['empresa_id' => Empresa::create([
            'ruc' => '20'.random_int(100000000, 999999999),
            'razon_social' => 'Otra',
            'regimen_tributario' => 'RUS',
            'rubro_codigo' => Rubro::query()->value('codigo'),
            'activo' => true,
        ])->id])->save();
        app(SuscripcionService::class)->iniciarPrueba($otro->empresa);

        $this->actingAs($otro)
            ->post("/comprobantes/{$comprobante->id}/correo", ['email' => 'x@y.com'])
            ->assertForbidden();
    }
}
