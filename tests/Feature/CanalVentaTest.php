<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** Canal de venta (tienda u online): opción por empresa para medir de dónde vienen las ventas. */
class CanalVentaTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->abrirCaja();
    }

    private function vender(?string $canal, int $cantidad = 1)
    {
        $producto = $this->crearProducto(precio: 10);
        $this->darStock($producto, 20, 4);

        return $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'canal_venta' => $canal,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => $cantidad]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 10 * $cantidad, 'referencia' => null]],
        ]);
    }

    private function activar(bool $activo = true): void
    {
        $this->empresa->update(['canal_venta_activo' => $activo]);
        $this->admin->unsetRelation('empresa');
    }

    private function ultimoCanal(): ?string
    {
        return Comprobante::where('empresa_id', $this->empresa->id)->orderByDesc('correlativo')->value('canal_venta');
    }

    public function test_apagado_no_se_guarda_el_canal_ni_se_ofrece_el_reporte(): void
    {
        $this->vender('online')->assertSessionHas('success');
        $this->assertNull($this->ultimoCanal());

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('auth.user.empresa.canal_venta_activo', false));
        $this->actingAs($this->admin)->get('/comprobantes')->assertInertia(fn (Assert $p) => $p->where('usaCanalVenta', false));

        // pedir el reporte a mano cae en el detalle de ventas
        $this->actingAs($this->admin)->get('/reportes?tipo=canales')->assertInertia(fn (Assert $p) => $p->where('filtros.tipo', 'ventas'));
    }

    public function test_encendido_guarda_el_canal_y_por_defecto_es_tienda(): void
    {
        $this->activar();

        $this->vender('online')->assertSessionHas('success');
        $this->assertSame('online', $this->ultimoCanal());

        $this->vender(null)->assertSessionHas('success');
        $this->assertSame('tienda', $this->ultimoCanal());

        $this->vender('whatsapp')->assertSessionHasErrors('canal_venta');
    }

    public function test_el_reporte_separa_los_canales_y_filtra_comprobantes(): void
    {
        // venta anterior a activarlo: queda sin especificar
        $this->vender(null)->assertSessionHas('success');
        $this->activar();
        $this->vender('tienda', 2)->assertSessionHas('success');
        $this->vender('online', 3)->assertSessionHas('success');

        $this->actingAs($this->admin)->get('/reportes?tipo=canales')->assertInertia(fn (Assert $p) => $p
            ->where('filtros.tipo', 'canales')
            ->has('datos.filas', 3)
            // canal, nº de ventas, venta, ticket, utilidad (costo 4), % del total
            ->where('datos.filas.0', ['Online', 1, '30.00', '30.00', '18.00', '50.0%'])
            ->where('datos.filas.1', ['Tienda', 1, '20.00', '20.00', '12.00', '33.3%'])
            ->where('datos.filas.2', ['Sin especificar', 1, '10.00', '10.00', '6.00', '16.7%'])
            ->where('datos.resumen.1.valor', 'S/ 30.00')
            ->where('datos.resumen.2.valor', '50.0%')
            ->where('datos.nota', fn ($n) => str_contains($n, 'Sin especificar')));

        $this->actingAs($this->admin)->get('/comprobantes?canal=online')->assertInertia(fn (Assert $p) => $p
            ->where('usaCanalVenta', true)
            ->has('comprobantes.data', 1)
            ->where('comprobantes.data.0.canal_venta', 'online'));

        // al apagarlo, el historial sigue visible
        $this->activar(false);
        $this->actingAs($this->admin)->get('/reportes?tipo=canales')->assertInertia(fn (Assert $p) => $p->where('filtros.tipo', 'canales'));
        $this->actingAs($this->admin)->get('/comprobantes')->assertInertia(fn (Assert $p) => $p->where('usaCanalVenta', true));
    }

    public function test_el_interruptor_se_guarda_en_datos_de_la_empresa(): void
    {
        $this->actingAs($this->admin)->put('/empresa', [
            'razon_social' => $this->empresa->razon_social, 'regimen_tributario' => 'MYPE', 'rubro_codigo' => $this->empresa->rubro_codigo, 'canal_venta_activo' => true,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->empresa->fresh()->canal_venta_activo);
    }
}
