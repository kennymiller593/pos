<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\SerieCorrelativo;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\Fakes\EnviadorSunatFalso;
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

    public function test_registro_de_ventas_para_el_contador(): void
    {
        $this->empresa->update(['facturacion_electronica' => true]);
        SerieCorrelativo::create([
            'empresa_id' => $this->empresa->id, 'sucursal_id' => $this->sucursal->id,
            'tipo_comprobante_codigo' => '03', 'serie' => 'B001', 'correlativo' => 0,
        ]);
        $enviador = new EnviadorSunatFalso;
        $enviador->respuesta = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'ok', xml: '<x/>', hash: 'H');
        $this->app->instance(EnviadorSunat::class, $enviador);
        Storage::fake('local');

        $producto = $this->crearProducto(precio: 11.80);
        $this->darStock($producto, 10, 5.00);
        $this->abrirCaja();

        $vender = fn () => $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '03', 'cliente_id' => null, 'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 11.80, 'referencia' => null]],
        ])->assertSessionHas('success');
        $vender();
        $vender();

        // una nota de venta no entra al registro; una boleta anulada entra con importe cero
        $this->venderContado($producto->presentaciones->first(), 1);
        $segunda = Comprobante::where('empresa_id', $this->empresa->id)->where('serie', 'B001')->where('correlativo', 2)->firstOrFail();
        $enviador->respuestaBaja = new RespuestaSunat(aceptado: false, codigo: '98', mensaje: 'En proceso', ticket: 'T', enProceso: true);
        $enviador->respuestaTicket = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'Baja aceptada', ticket: 'T');
        $this->actingAs($this->admin)->post("/comprobantes/{$segunda->id}/anular", ['motivo' => 'error'])->assertSessionHas('success');

        $this->actingAs($this->admin)->get('/reportes?tipo=libro')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('datos.titulo', 'Registro de ventas')
            ->has('datos.filas', 2)
            ->where('datos.filas.0.1', '03')
            ->where('datos.filas.0.2', 'B001')
            ->where('datos.filas.0.3', '00000001')
            ->where('datos.filas.0.7', '10.00')  // base gravada
            ->where('datos.filas.0.10', '1.80')  // IGV
            ->where('datos.filas.0.11', '11.80')
            ->where('datos.filas.0.13', 'Aceptado')
            ->where('datos.filas.1.6', 'ANULADO')
            ->where('datos.filas.1.11', '0.00')
            ->where('datos.resumen.4.valor', 'S/ 11.80'));

        $csv = $this->actingAs($this->admin)->get('/reportes/exportar?tipo=libro&formato=csv');
        $csv->assertOk();
        $this->assertStringContainsString('Base gravada', $csv->getContent());
    }

    public function test_fechas_invalidas_y_formulas_en_csv(): void
    {
        $this->actingAs($this->admin)->from('/reportes')->get('/reportes?tipo=ventas&desde=xx')->assertSessionHasErrors('desde');
        $this->actingAs($this->admin)->from('/reportes')->get('/reportes?tipo=ventas&desde=2026-01-01&hasta=2025-01-01')->assertSessionHasErrors('hasta');

        // un cliente con nombre malicioso no se convierte en formula al abrir el CSV
        $cliente = $this->crearCliente();
        $cliente->update(['nombre' => '=HYPERLINK("http://malo")']);
        $producto = $this->crearProducto(precio: 5.00);
        $this->darStock($producto, 5, 1.00);
        $this->abrirCaja();
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => $cliente->id, 'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 5, 'referencia' => null]],
        ])->assertSessionHas('success');

        $csv = $this->actingAs($this->admin)->get('/reportes/exportar?tipo=ventas&formato=csv')->getContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_solo_admin_accede_a_reportes(): void
    {
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');

        $this->actingAs($cajero)->get('/reportes')->assertForbidden();
        $this->actingAs($cajero)->get('/reportes/exportar?tipo=ventas&formato=csv')->assertForbidden();
    }
}
