<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/**
 * Una venta no se registra dos veces. Pasó en producción: la campanita (/notificaciones) se coló entre el POST
 * de la venta y la recarga del POS, gastó el aviso de la sesión, el POS no vio la confirmación y se cobró otra vez.
 */
class VentaDuplicadaTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    private function venta(array $extra = []): array
    {
        $producto = $this->crearProducto(precio: 85.00);
        $this->darStock($producto, 10, 50.00);

        return [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 2]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 170.00, 'referencia' => null]],
            ...$extra,
        ];
    }

    public function test_el_aviso_de_la_venta_sobrevive_a_las_consultas_de_fondo(): void
    {
        $this->abrirCaja();
        $this->actingAs($this->admin)->post('/pos/ventas', $this->venta())->assertRedirect()->assertSessionHas('success');

        // la campanita y el estado SUNAT se consultan en segundo plano: no gastan el aviso
        $this->actingAs($this->admin)->get('/notificaciones')->assertOk();
        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->actingAs($this->admin)->get("/comprobantes/{$comprobante->id}/estado-sunat")->assertOk();

        $this->actingAs($this->admin)->get('/pos')->assertInertia(fn (Assert $p) => $p
            ->where('flash.success', fn ($s) => str_contains($s, 'NV01-000001 registrada'))
            ->where('flash.venta.id', $comprobante->id));

        // y una vez mostrado, ya no vuelve a aparecer
        $this->actingAs($this->admin)->get('/pos')->assertInertia(fn (Assert $p) => $p->where('flash.success', null));
    }

    public function test_la_misma_venta_enviada_dos_veces_se_registra_una_sola_vez(): void
    {
        $this->abrirCaja();
        $venta = $this->venta(['clave' => Str::uuid()->toString()]);

        $this->actingAs($this->admin)->post('/pos/ventas', $venta)->assertSessionHas('success', fn ($s) => str_contains($s, 'NV01-000001 registrada'));
        $this->actingAs($this->admin)->post('/pos/ventas', $venta)
            ->assertRedirect()
            ->assertSessionHas('success', 'La venta NV01-000001 por S/ 170.00 ya estaba registrada; no se repitió.')
            ->assertSessionHas('venta.numero', 'NV01-000001');

        $this->assertSame(1, Comprobante::where('empresa_id', $this->empresa->id)->count());

        // con la misma clave pero otro carrito sí es una venta nueva
        $this->actingAs($this->admin)->post('/pos/ventas', [...$venta, 'items' => [['presentacion_id' => $venta['items'][0]['presentacion_id'], 'cantidad' => 1]], 'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 85.00, 'referencia' => null]]])
            ->assertSessionHas('success', fn ($s) => str_contains($s, 'NV01-000002 registrada'));
        $this->assertSame(2, Comprobante::where('empresa_id', $this->empresa->id)->count());

        // una venta rechazada no bloquea la clave: corregida, se registra
        $otra = $this->venta(['clave' => Str::uuid()->toString(), 'pagos' => []]);
        $this->actingAs($this->admin)->post('/pos/ventas', $otra)->assertSessionHasErrors('pagos');
        $this->actingAs($this->admin)->post('/pos/ventas', [...$otra, 'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 170.00, 'referencia' => null]]])
            ->assertSessionHas('success', fn ($s) => str_contains($s, 'NV01-000003 registrada'));
    }
}
