<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\CuentaPorCobrar;
use App\Models\Producto;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\Concerns\LeeExcel;
use Tests\TestCase;

class ReportesNegocioTest extends TestCase
{
    use CreaEscenarioPos;
    use LeeExcel;

    private Producto $gaseosa;

    private Producto $pan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();

        $bebidas = Categoria::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Bebidas']);
        // gaseosa: precio 10, costo 4 · pan (sin categoria): precio 5, costo 2
        $this->gaseosa = $this->crearProducto(precio: 10, atributos: ['nombre' => 'Gaseosa', 'categoria_id' => $bebidas->id]);
        $this->pan = $this->crearProducto(precio: 5, atributos: ['nombre' => 'Pan']);
        $this->darStock($this->gaseosa, 200, 4);
        $this->darStock($this->pan, 200, 2);
        $this->abrirCaja();
    }

    /** 3 gaseosas (30) y 2 panes (10): venta 40, costo 16, utilidad 24. */
    private function venderLoBasico(): void
    {
        $this->venderContado($this->gaseosa->presentaciones->first(), 3)->assertSessionHas('success');
        $this->venderContado($this->pan->presentaciones->first(), 2)->assertSessionHas('success');
    }

    private function ventaDe(Producto $producto): Comprobante
    {
        return Comprobante::where('empresa_id', $this->empresa->id)
            ->whereHas('detalles', fn ($d) => $d->where('producto_id', $producto->id))
            ->latest('creado_en')->firstOrFail();
    }

    private function venderACredito(Cliente $cliente, float $cantidad): CuentaPorCobrar
    {
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => $cliente->id, 'es_credito' => true,
            'items' => [['presentacion_id' => $this->gaseosa->presentaciones->first()->id, 'cantidad' => $cantidad]],
            'pagos' => [],
        ])->assertSessionHas('success');

        return CuentaPorCobrar::where('cliente_id', $cliente->id)->latest('id')->firstOrFail();
    }

    private function reporte(string $consulta)
    {
        return $this->actingAs($this->admin)->get("/reportes?{$consulta}");
    }

    public function test_el_catalogo_agrupa_los_reportes_y_un_tipo_desconocido_cae_en_ventas(): void
    {
        $this->reporte('tipo=inventado')->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Reportes/Index')
            ->where('filtros.tipo', 'ventas')
            ->has('catalogo', 6)
            ->where('catalogo.0.grupo', 'Ventas')
            ->has('catalogo.0.reportes', 6));
    }

    public function test_ventas_por_producto_suma_en_unidad_base_y_no_cuenta_lo_anulado(): void
    {
        $this->venderLoBasico();
        // una caja x12 son 12 gaseosas mas
        $caja = $this->agregarPresentacion($this->gaseosa, 'Caja x12', 12, 100);
        $this->venderContado($caja, 1)->assertSessionHas('success');

        $this->reporte('tipo=productos')->assertInertia(fn (Assert $pagina) => $pagina
            ->has('datos.filas', 2)
            ->where('datos.filas.0', ['Gaseosa', $this->gaseosa->codigo_interno, 'Bebidas', '15', 2, '130.00', '92.9%'])
            ->where('datos.filas.1', ['Pan', $this->pan->codigo_interno, 'Sin categoría', '2', 1, '10.00', '7.1%'])
            ->where('datos.resumen.1.valor', 'S/ 140.00')
            ->where('datos.resumen.2.valor', 'Gaseosa')
            ->where('datos.grafico.etiquetas', ['Gaseosa', 'Pan'])
            ->where('datos.grafico.horizontal', true));

        $this->actingAs($this->admin)->post("/comprobantes/{$this->ventaDe($this->pan)->id}/anular", ['motivo' => 'Error de digitación']);

        $this->reporte('tipo=productos')->assertInertia(fn (Assert $pagina) => $pagina
            ->has('datos.filas', 1)
            ->where('datos.resumen.1.valor', 'S/ 130.00'));
    }

    public function test_ventas_por_categoria_con_costo_y_utilidad(): void
    {
        $this->venderLoBasico();

        $this->reporte('tipo=categorias')->assertInertia(fn (Assert $pagina) => $pagina
            ->has('datos.filas', 2)
            ->where('datos.filas.0', ['Bebidas', 1, '30.00', '12.00', '18.00', '60.0%', '75.0%'])
            ->where('datos.filas.1', ['Sin categoría', 1, '10.00', '4.00', '6.00', '60.0%', '25.0%'])
            ->where('datos.resumen.2.valor', 'S/ 24.00')
            ->where('datos.resumen.3.valor', 'Bebidas'));
    }

    public function test_ventas_por_vendedor(): void
    {
        $this->venderLoBasico();

        $this->reporte('tipo=vendedores')->assertInertia(fn (Assert $pagina) => $pagina
            ->has('datos.filas', 1)
            ->where('datos.filas.0', ['Admin Test', 2, '40.00', '20.00', '24.00', '100.0%'])
            ->where('datos.resumen.2.valor', 'S/ 20.00'));
    }

    public function test_ventas_por_hora_rellena_las_horas_sin_venta(): void
    {
        $this->venderLoBasico();
        $this->ventaDe($this->gaseosa)->update(['hora_emision' => '09:15:00']);
        $this->ventaDe($this->pan)->update(['hora_emision' => '11:40:00']);

        $this->reporte('tipo=horas')->assertInertia(fn (Assert $pagina) => $pagina
            ->has('datos.filas', 3)
            ->where('datos.filas.0', ['09:00 a 09:59', 1, '30.00', '30.00', '75.0%'])
            ->where('datos.filas.1', ['10:00 a 10:59', 0, '0.00', '0.00', '0.0%'])
            ->where('datos.filas.2', ['11:00 a 11:59', 1, '10.00', '10.00', '25.0%'])
            ->where('datos.resumen.0.valor', '09:00 a 09:59')
            ->where('datos.grafico.etiquetas', ['09 h', '10 h', '11 h']));
    }

    public function test_ventas_por_dia_de_la_semana(): void
    {
        $this->venderLoBasico();
        $lunes = now()->startOfWeek()->toDateString();
        Comprobante::where('empresa_id', $this->empresa->id)->update(['fecha_emision' => $lunes]);

        $this->reporte("tipo=dias&desde={$lunes}&hasta=".now()->toDateString())->assertInertia(fn (Assert $pagina) => $pagina
            ->has('datos.filas', 7)
            ->where('datos.filas.0', ['Lunes', 1, 2, '40.00', '40.00', '100.0%'])
            ->where('datos.filas.6', ['Domingo', 0, 0, '0.00', '0.00', '0.0%'])
            ->where('datos.resumen.0.valor', 'Lunes'));

        // sin ventas no se listan siete filas en cero
        $ayer = now()->subYear()->toDateString();
        $this->reporte("tipo=dias&desde={$ayer}&hasta={$ayer}")->assertInertia(fn (Assert $pagina) => $pagina
            ->has('datos.filas', 0)
            ->where('datos.grafico', null));
    }

    public function test_utilidad_por_periodo_agrupa_por_dia_semana_o_mes(): void
    {
        $this->venderLoBasico();

        $this->reporte('tipo=utilidad')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('filtros.agrupar', 'dia')
            ->has('datos.filas', 1)
            ->where('datos.filas.0', [now()->format('d/m/Y'), 2, '40.00', '16.00', '24.00', '60.0%'])
            ->where('datos.resumen.2.valor', 'S/ 24.00')
            ->where('datos.resumen.3.valor', '60.0%'));

        $this->reporte('tipo=utilidad&agrupar=mes')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('filtros.agrupar', 'mes')
            ->where('datos.contexto', 'Por mes')
            ->where('datos.columnas.0', 'Mes')
            ->has('datos.filas', 1)
            ->where('datos.filas.0.4', '24.00'));

        // un rango largo se agrupa solo por mes; uno mediano, por semana
        $hoy = now()->toDateString();
        $this->reporte('tipo=utilidad&desde='.now()->subDays(200)->toDateString()."&hasta={$hoy}")
            ->assertInertia(fn (Assert $pagina) => $pagina->where('filtros.agrupar', 'mes'));
        $this->reporte('tipo=utilidad&desde='.now()->subDays(60)->toDateString()."&hasta={$hoy}")
            ->assertInertia(fn (Assert $pagina) => $pagina->where('filtros.agrupar', 'semana')->has('datos.filas', 1));
    }

    public function test_clientes_que_mas_compran_y_el_dni_se_exporta_como_texto(): void
    {
        $this->venderLoBasico();
        $cliente = $this->crearCliente(limiteCredito: 500);
        $cliente->update(['numero_documento' => '01234567', 'nombre' => 'Rosa Quispe']);
        $this->venderACredito($cliente, 2);

        $this->reporte('tipo=clientes')->assertInertia(fn (Assert $pagina) => $pagina
            ->has('datos.filas', 2)
            ->where('datos.filas.0.0', 'Público general (sin identificar)')
            ->where('datos.filas.0.3', '40.00')
            ->where('datos.filas.1', ['Rosa Quispe', '01234567', 1, '20.00', '20.00', now()->format('d/m/Y'), '33.3%'])
            ->where('datos.resumen.0.valor', '1')
            ->where('datos.resumen.1.valor', 'S/ 20.00')
            ->where('datos.resumen.2.valor', 'S/ 40.00')
            ->where('datos.resumen.3.valor', 'Rosa Quispe')
            // el publico general no compite en la grafica
            ->where('datos.grafico.etiquetas', ['Rosa Quispe']));

        $filas = $this->filasDe($this->abrirExcel($this->actingAs($this->admin)->get('/reportes/exportar?tipo=clientes&formato=xlsx')));
        $this->assertSame('Clientes que más compran', $filas[0][0]);
        $this->assertSame(['Rosa Quispe', '01234567', 1, 20.0], array_slice($filas[4], 0, 4)); // el cero inicial del DNI se conserva
    }

    public function test_cuentas_por_cobrar_por_antiguedad(): void
    {
        $cliente = $this->crearCliente(limiteCredito: 500);
        $cliente->update(['nombre' => 'Rosa Quispe']);
        $cuenta = $this->venderACredito($cliente, 2); // debe 20
        $cuenta->comprobante->update(['fecha_emision' => now()->subDays(45)->toDateString()]);

        $this->reporte('tipo=cobranza')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('datos.periodo', 'Al '.now()->format('d/m/Y'))
            ->has('datos.filas', 1)
            ->where('datos.filas.0.0', 'Rosa Quispe')
            ->where('datos.filas.0.5', 45)
            ->where('datos.filas.0.6', '31 a 60 días')
            ->where('datos.filas.0.9', '20.00')
            ->where('datos.resumen.0', ['etiqueta' => 'Total por cobrar', 'valor' => 'S/ 20.00'])
            ->where('datos.resumen.1', ['etiqueta' => '0 a 30 días', 'valor' => 'S/ 0.00'])
            ->where('datos.resumen.2', ['etiqueta' => '31 a 60 días', 'valor' => 'S/ 20.00'])
            ->where('datos.grafico.valores', [0, 20, 0, 0]));

        // un cobro parcial baja el saldo; al terminar de pagar, la deuda sale del reporte
        $cobrar = fn (float $monto) => $this->actingAs($this->admin)
            ->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => $monto, 'medio_pago_codigo' => 'efectivo'])
            ->assertSessionHas('success');

        $cobrar(5);
        $this->reporte('tipo=cobranza')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('datos.filas.0.8', '5.00')
            ->where('datos.filas.0.9', '15.00'));

        $cobrar(15);
        $this->reporte('tipo=cobranza')->assertInertia(fn (Assert $pagina) => $pagina
            ->has('datos.filas', 0)
            ->has('datos.resumen', 0));

        // el Excel de una foto de hoy no lleva rango de fechas
        $cuenta2 = $this->venderACredito($cliente, 1);
        $filas = $this->filasDe($this->abrirExcel($this->actingAs($this->admin)->get('/reportes/exportar?tipo=cobranza&formato=xlsx')));
        $this->assertSame('Al '.now()->format('d/m/Y'), $filas[1][0]);
        $this->assertSame(10.0, $filas[3][9]);
        $this->assertNotNull($cuenta2);
    }

    public function test_caja_por_medio_de_pago(): void
    {
        $this->venderLoBasico(); // 40 en efectivo
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => null, 'es_credito' => false,
            'items' => [['presentacion_id' => $this->gaseosa->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'yape', 'monto' => 10, 'referencia' => 'OP-1']],
        ])->assertSessionHas('success');

        $cliente = $this->crearCliente(limiteCredito: 500);
        $cuenta = $this->venderACredito($cliente, 2);
        $this->actingAs($this->admin)->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 5, 'medio_pago_codigo' => 'efectivo'])->assertSessionHas('success');
        $this->actingAs($this->admin)->post('/caja/movimientos', ['tipo' => 'egreso', 'concepto' => 'Movilidad', 'monto' => 15])->assertSessionHas('success');

        $this->reporte('tipo=caja')->assertInertia(fn (Assert $pagina) => $pagina
            ->has('datos.filas', 2)
            //                         ventas   cobros  ingresos egresos proveed. neto
            ->where('datos.filas.0', ['Efectivo', '40.00', '5.00', '0.00', '15.00', '0.00', '30.00'])
            ->where('datos.filas.1', ['Yape', '10.00', '0.00', '0.00', '0.00', '0.00', '10.00'])
            ->where('datos.resumen.0.valor', 'S/ 55.00')
            ->where('datos.resumen.1.valor', 'S/ 15.00')
            ->where('datos.resumen.2.valor', 'S/ 40.00')
            ->where('datos.resumen.3.valor', 'S/ 30.00'));

        // fuera del rango no hay nada
        $antes = now()->subDays(10)->toDateString();
        $this->reporte("tipo=caja&desde={$antes}&hasta={$antes}")->assertInertia(fn (Assert $pagina) => $pagina->has('datos.filas', 0));
    }

    public function test_todos_los_reportes_se_exportan_a_excel_y_pdf(): void
    {
        $this->venderLoBasico();
        $this->venderACredito($this->crearCliente(limiteCredito: 500), 1);

        foreach (['productos', 'categorias', 'vendedores', 'horas', 'dias', 'utilidad', 'clientes', 'cobranza', 'caja'] as $tipo) {
            $libro = $this->abrirExcel($this->actingAs($this->admin)->get("/reportes/exportar?tipo={$tipo}&formato=xlsx"));
            $this->assertGreaterThan(3, count($this->filasDe($libro)), "El Excel de {$tipo} salió vacío");
        }

        $pdf = $this->actingAs($this->admin)->get('/reportes/exportar?tipo=categorias&formato=pdf');
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_otra_empresa_no_ve_estos_datos(): void
    {
        $this->venderLoBasico();
        $this->venderACredito($this->crearCliente(limiteCredito: 500), 1);

        $this->crearEscenarioBase(); // otra empresa, otro admin

        foreach (['productos', 'categorias', 'vendedores', 'horas', 'dias', 'utilidad', 'clientes', 'cobranza', 'caja'] as $tipo) {
            $this->reporte("tipo={$tipo}")->assertInertia(fn (Assert $pagina) => $pagina->has('datos.filas', 0));
        }
    }

    public function test_el_dashboard_compara_el_mes_con_el_mismo_tramo_del_mes_anterior(): void
    {
        $this->venderLoBasico();
        // la venta de pan (10, utilidad 6) pasa al dia 1 del mes anterior
        $this->ventaDe($this->pan)->update(['fecha_emision' => now()->subMonthNoOverflow()->startOfMonth()->toDateString()]);

        $cerca = fn (float $esperado) => fn ($valor) => abs($valor - $esperado) < 0.001;

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('comparacion.actual.desde', now()->startOfMonth()->toDateString())
            ->where('comparacion.actual.hasta', now()->toDateString())
            ->where('comparacion.anterior.desde', now()->subMonthNoOverflow()->startOfMonth()->toDateString())
            ->where('comparacion.mes_anterior_completo', $cerca(10))
            ->has('comparacion.filas', 4)
            ->where('comparacion.filas.0.clave', 'ventas')
            ->where('comparacion.filas.0.actual', $cerca(30))
            ->where('comparacion.filas.0.anterior', $cerca(10))
            ->where('comparacion.filas.0.variacion', $cerca(200))
            ->where('comparacion.filas.1.clave', 'utilidad')
            ->where('comparacion.filas.1.actual', $cerca(18))
            ->where('comparacion.filas.1.anterior', $cerca(6))
            ->where('comparacion.filas.2.actual', $cerca(1))
            ->where('comparacion.filas.2.variacion', $cerca(0))
            ->where('comparacion.filas.3.actual', $cerca(30))
            ->where('comparacion.filas.3.anterior', $cerca(10)));

        // quien no ve finanzas no recibe la comparacion
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');
        $this->actingAs($cajero)->get('/dashboard')->assertInertia(fn (Assert $pagina) => $pagina->where('comparacion', null));
    }

    public function test_sin_mes_anterior_la_variacion_queda_vacia(): void
    {
        $this->venderLoBasico();

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('comparacion.filas.0.variacion', null)
            ->where('comparacion.filas.0.anterior', fn ($v) => (float) $v === 0.0));
    }
}
