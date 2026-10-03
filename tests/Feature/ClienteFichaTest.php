<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\CuentaPorCobrar;
use App\Models\Producto;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class ClienteFichaTest extends TestCase
{
    use CreaEscenarioPos;

    private Producto $gaseosa;

    private Producto $pan;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();

        $this->gaseosa = $this->crearProducto(precio: 10, atributos: ['nombre' => 'Gaseosa']);
        $this->pan = $this->crearProducto(precio: 5, atributos: ['nombre' => 'Pan']);
        $this->darStock($this->gaseosa, 200, 4);
        $this->darStock($this->pan, 200, 2);
        $this->abrirCaja();

        $this->cliente = $this->crearCliente(limiteCredito: 100);
        $this->cliente->update(['nombre' => 'Rosa Quispe', 'telefono' => '987654321']);
    }

    private function vender(Producto $producto, float $cantidad, bool $credito = false, ?Cliente $cliente = null): Comprobante
    {
        $presentacion = $producto->presentaciones->first();
        $total = round($cantidad * (float) $presentacion->precio_venta, 2);

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => ($cliente ?? $this->cliente)->id,
            'es_credito' => $credito,
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => $cantidad]],
            'pagos' => $credito ? [] : [['medio_pago_codigo' => 'efectivo', 'monto' => $total, 'referencia' => null]],
        ])->assertSessionHas('success');

        return Comprobante::where('empresa_id', $this->empresa->id)->latest('creado_en')->latest('correlativo')->firstOrFail();
    }

    private function cerca(float $esperado): \Closure
    {
        return fn ($valor) => abs((float) $valor - $esperado) < 0.001;
    }

    public function test_la_ficha_resume_las_compras_la_deuda_y_lo_que_mas_lleva(): void
    {
        $this->vender($this->gaseosa, 3);               // 30 contado
        $this->vender($this->pan, 2);                   // 10 contado
        $credito = $this->vender($this->gaseosa, 4, credito: true); // 40 al credito
        $credito->update(['fecha_emision' => now()->subDays(12)->toDateString()]);
        // lo de otro cliente no se mezcla
        $this->vender($this->pan, 20, cliente: $this->crearCliente());

        $this->actingAs($this->admin)->get("/clientes/{$this->cliente->id}")->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Clientes/Ficha')
            ->where('cliente.nombre', 'Rosa Quispe')
            ->where('cliente.telefono', '987654321')
            ->where('resumen.total', $this->cerca(80))
            ->where('resumen.compras', 3)
            ->where('resumen.ticket_promedio', $this->cerca(26.67))
            ->where('resumen.ultima', now()->toDateString())
            ->where('resumen.primera', now()->subDays(12)->toDateString())
            ->where('resumen.deuda', $this->cerca(40))
            ->where('resumen.credito_disponible', $this->cerca(60))
            // historial: lo mas reciente primero, con lo que llevo en cada compra
            ->has('compras.data', 3)
            ->where('compras.data.2.numero', "{$credito->serie}-".str_pad((string) $credito->correlativo, 6, '0', STR_PAD_LEFT))
            ->where('compras.data.2.es_credito', true)
            ->where('compras.data.2.saldo', $this->cerca(40))
            ->has('compras.data.2.items', 1)
            ->where('compras.data.2.items.0.descripcion', fn ($d) => str_contains($d, 'Gaseosa'))
            ->where('compras.data.2.items.0.cantidad', $this->cerca(4))
            // deuda pendiente con su antiguedad
            ->has('deudas', 1)
            ->where('deudas.0.dias', 12)
            ->where('deudas.0.saldo', $this->cerca(40))
            // lo que mas compra
            ->has('frecuentes', 2)
            ->where('frecuentes.0.nombre', 'Gaseosa')
            ->where('frecuentes.0.cantidad', $this->cerca(7))
            ->where('frecuentes.0.veces', 2)
            ->where('frecuentes.0.total', $this->cerca(70))
            // sin programa de puntos y sin saldo, la seccion no aparece
            ->where('puntos', null));
    }

    public function test_los_cobros_bajan_la_deuda_y_lo_anulado_no_cuenta(): void
    {
        $this->vender($this->gaseosa, 4, credito: true); // debe 40
        $anulada = $this->vender($this->pan, 2);         // 10 que se anula
        $cuenta = CuentaPorCobrar::where('cliente_id', $this->cliente->id)->firstOrFail();

        $this->actingAs($this->admin)->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 15, 'medio_pago_codigo' => 'efectivo'])->assertSessionHas('success');
        $this->actingAs($this->admin)->post("/comprobantes/{$anulada->id}/anular", ['motivo' => 'Error de digitación']);

        $this->actingAs($this->admin)->get("/clientes/{$this->cliente->id}")->assertInertia(fn (Assert $pagina) => $pagina
            ->where('resumen.total', $this->cerca(40))
            ->where('resumen.compras', 1)
            ->where('resumen.deuda', $this->cerca(25))
            ->where('resumen.credito_disponible', $this->cerca(75))
            ->where('deudas.0.pagado', $this->cerca(15))
            // el anulado se ve en el historial, marcado
            ->has('compras.data', 2)
            ->where('compras.data.0.estado', 'anulado')
            ->has('cobros', 1)
            ->where('cobros.0.monto', $this->cerca(15))
            ->where('cobros.0.medio', 'Efectivo'));
    }

    public function test_la_ficha_muestra_los_puntos_cuando_el_programa_esta_activo(): void
    {
        $this->empresa->update(['puntos_activo' => true, 'puntos_soles_por_punto' => 10, 'puntos_valor' => 0.5]);
        $this->actingAs($this->admin->fresh());
        $this->admin = $this->admin->fresh();
        $this->vender($this->gaseosa, 3); // 30 -> 3 puntos

        $this->actingAs($this->admin)->get("/clientes/{$this->cliente->id}")->assertInertia(fn (Assert $pagina) => $pagina
            ->where('cliente.puntos', 3)
            ->where('puntos.saldo', 3)
            ->where('puntos.reglas.valor', $this->cerca(0.5))
            ->has('puntos.movimientos', 1)
            ->where('puntos.movimientos.0.puntos', 3)
            ->where('puntos.movimientos.0.tipo', 'Ganados en compra'));

        // la lista de clientes trae los puntos y las reglas del programa
        $this->actingAs($this->admin)->get('/clientes')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('programaPuntos.activo', true)
            ->where('clientes.data.0.puntos', 3));
    }

    public function test_un_cliente_sin_compras_tiene_ficha_vacia_y_el_cajero_puede_verla(): void
    {
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');

        $this->actingAs($cajero)->get("/clientes/{$this->cliente->id}")->assertInertia(fn (Assert $pagina) => $pagina
            ->where('resumen.compras', 0)
            ->where('resumen.total', $this->cerca(0))
            ->where('resumen.ultima', null)
            ->has('compras.data', 0)
            ->has('deudas', 0)
            ->has('frecuentes', 0));
    }

    public function test_un_cliente_eliminado_o_de_otra_empresa_no_tiene_ficha(): void
    {
        $this->cliente->delete();
        $this->actingAs($this->admin)->get("/clientes/{$this->cliente->id}")->assertNotFound();

        $ajeno = $this->crearCliente();
        $this->crearEscenarioBase();
        $this->actingAs($this->admin)->get("/clientes/{$ajeno->id}")->assertForbidden();
    }
}
