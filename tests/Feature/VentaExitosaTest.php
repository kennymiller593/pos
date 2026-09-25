<?php

namespace Tests\Feature;

use App\Http\Controllers\ComprobanteController;
use App\Models\Cliente;
use App\Models\Comprobante;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** Datos y rutas que usa el modal de venta exitosa del POS. */
class VentaExitosaTest extends TestCase
{
    use CreaEscenarioPos;

    private Comprobante $comprobante;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();

        $cliente = Cliente::create([
            'empresa_id' => $this->empresa->id, 'tipo_documento_codigo' => '1', 'numero_documento' => '45678912',
            'nombre' => 'Juan Pérez', 'email' => 'juan@correo.com', 'telefono' => '977 425 905', 'limite_credito' => 0,
        ]);
        $producto = $this->crearProducto(precio: 10.00);
        $this->darStock($producto, 5, 4.00);
        $this->abrirCaja();

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => $cliente->id, 'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 2]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 20, 'referencia' => null]],
        ])->assertSessionHas('success');

        $this->comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
    }

    public function test_la_venta_deja_los_datos_del_modal_en_el_flash(): void
    {
        $venta = session('venta');

        $this->assertSame($this->comprobante->id, $venta['id']);
        $this->assertSame('NV01-000001', $venta['numero']);
        $this->assertSame('Nota de venta', $venta['tipo']);
        $this->assertFalse($venta['electronico']);
        $this->assertEquals(20.0, $venta['total']);
        $this->assertSame('juan@correo.com', $venta['cliente_email']);
        $this->assertSame('977 425 905', $venta['cliente_telefono']);
        $this->assertStringContainsString('/c/'.$this->comprobante->id, $venta['enlace_publico']);
        $this->assertStringContainsString('signature=', $venta['enlace_publico']);
    }

    public function test_a5_y_estado_sunat(): void
    {
        $pdf = $this->actingAs($this->admin)->get("/comprobantes/{$this->comprobante->id}/a5");
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($this->admin)->getJson("/comprobantes/{$this->comprobante->id}/estado-sunat")
            ->assertOk()
            ->assertJson(['electronico' => false, 'estado' => null]);
    }

    public function test_el_enlace_publico_abre_el_pdf_sin_sesion_y_no_se_puede_alterar(): void
    {
        $enlace = ComprobanteController::enlacePublico($this->comprobante);
        auth()->logout();

        $respuesta = $this->get($enlace);
        $respuesta->assertOk();
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());

        // sin firma, o con la firma de otro comprobante, no abre
        $this->get('/c/'.$this->comprobante->id)->assertForbidden();
        $otro = $this->comprobante->replicate()->fill(['correlativo' => 99]);
        $otro->save();
        $this->get(str_replace($this->comprobante->id, $otro->id, $enlace))->assertForbidden();
    }
}
