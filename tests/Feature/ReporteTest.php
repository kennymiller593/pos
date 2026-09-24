<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class ReporteTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    private function venderConCosto(): void
    {
        // costo 2.00, precio 3.50: vender 2 -> venta 7.00, costo 4.00, margen 3.00
        $producto = $this->crearProducto(precio: 3.50);
        $this->darStock($producto, 10, 2.00);
        $this->abrirCaja();
        $this->venderContado($producto->presentaciones->first(), 2);
    }

    public function test_reporte_de_ventas_por_rango(): void
    {
        $this->venderConCosto();

        $this->actingAs($this->admin)->get('/reportes?tipo=ventas')->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Reportes/Index')
            ->has('datos.filas', 1)
            ->where('datos.resumen.0.valor', '1') // comprobantes
            ->where('datos.resumen.1.valor', 'S/ 7.00') // total
        );
    }

    public function test_reporte_de_margen_por_producto(): void
    {
        $this->venderConCosto();

        $this->actingAs($this->admin)->get('/reportes?tipo=margen')->assertInertia(fn (Assert $pagina) => $pagina
            ->has('datos.filas', 1)
            ->where('datos.resumen.0.valor', 'S/ 7.00')  // venta
            ->where('datos.resumen.1.valor', 'S/ 4.00')  // costo FIFO
            ->where('datos.resumen.2.valor', 'S/ 3.00')  // margen
            ->where('datos.resumen.3.valor', '42.9%')
        );
    }

    public function test_kardex_con_saldo_corrido(): void
    {
        $producto = $this->crearProducto(precio: 3.50);
        $unidad = $producto->presentaciones->first();

        // compra 10 y vende 4 -> saldo final 6
        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null, 'tipo_comprobante_codigo' => null, 'serie_numero' => null,
            'fecha' => now()->toDateString(), 'es_credito' => false,
            'items' => [['presentacion_id' => $unidad->id, 'cantidad' => 10, 'costo_unitario' => 2.00]],
        ]);
        $this->abrirCaja();
        $this->venderContado($unidad, 4);

        $this->actingAs($this->admin)
            ->get("/reportes?tipo=kardex&producto_id={$producto->id}")
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->has('datos.filas', 2)
                ->where('datos.contexto', $producto->nombre)
                ->where('datos.resumen.0.valor', '0')   // saldo inicial
                ->where('datos.resumen.1.valor', '10')  // entradas
                ->where('datos.resumen.2.valor', '4')   // salidas
                ->where('datos.resumen.3.valor', '6')   // saldo final
            );
    }

    public function test_exportaciones_csv_y_pdf(): void
    {
        $this->venderConCosto();

        $csv = $this->actingAs($this->admin)->get('/reportes/exportar?tipo=margen&formato=csv');
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('content-type'));
        $this->assertStringContainsString('Margen', $csv->getContent());
        $this->assertStringContainsString('7.00', $csv->getContent());

        $pdf = $this->actingAs($this->admin)->get('/reportes/exportar?tipo=ventas&formato=pdf');
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_solo_admin_accede_a_reportes(): void
    {
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');

        $this->actingAs($cajero)->get('/reportes')->assertForbidden();
        $this->actingAs($cajero)->get('/reportes/exportar?tipo=ventas&formato=csv')->assertForbidden();
    }
}
