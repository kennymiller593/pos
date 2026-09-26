<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Sucursal;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class InicioTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_el_dashboard_muestra_metricas_reales(): void
    {
        // producto a S/3.50 con costo S/2.00: vender 2 -> venta 7.00, margen 3.00
        $producto = $this->crearProducto(precio: 3.50);
        $this->darStock($producto, 50, 2.00);
        $this->abrirCaja(100);
        $this->venderContado($producto->presentaciones->first(), 2);

        $respuesta = $this->actingAs($this->admin)->get('/dashboard');

        $respuesta->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Inicio')
            ->where('hoy.tickets', 1)
            ->where('hoy.total', fn ($v) => abs($v - 7.0) < 0.001)
            ->where('hoy.margen', fn ($v) => abs($v - 3.0) < 0.001)
            ->where('hoy.promedio', fn ($v) => abs($v - 7.0) < 0.001)
            ->has('serie', 14)
            ->has('topProductos', 1)
            ->where('topProductos.0.nombre', $producto->nombre)
            ->has('mediosPago', 1)
            ->where('mediosPago.0.total', fn ($v) => abs($v - 7.0) < 0.001)
        );
    }

    public function test_el_margen_del_mes_suma_todas_las_ventas_del_mes(): void
    {
        // venta a S/3.50 con costo S/2.00: 2 hoy (margen 3.00) y 4 a inicio de mes (margen 6.00)
        $producto = $this->crearProducto(precio: 3.50);
        $this->darStock($producto, 50, 2.00);
        $this->abrirCaja(100);
        $this->venderContado($producto->presentaciones->first(), 4);
        \App\Models\Comprobante::where('empresa_id', $this->empresa->id)->update(['fecha_emision' => now()->startOfMonth()->toDateString()]);
        $this->venderContado($producto->presentaciones->first(), 2);

        $hoyEsInicioDeMes = now()->isSameDay(now()->startOfMonth());

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('hoy.margen', fn ($v) => abs($v - ($hoyEsInicioDeMes ? 9.0 : 3.0)) < 0.001)
            ->where('hoy.margen_porcentaje', fn ($v) => abs($v - 42.9) < 0.001)
            ->where('mes.total', fn ($v) => abs($v - 21.0) < 0.001)
            ->where('mes.margen', fn ($v) => abs($v - 9.0) < 0.001)
            ->where('mes.margen_porcentaje', fn ($v) => abs($v - 42.9) < 0.001)
        );
    }

    public function test_el_dashboard_carga_sin_datos(): void
    {
        $respuesta = $this->actingAs($this->admin)->get('/dashboard');

        $respuesta->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Inicio')
            ->where('hoy.total', 0)
            ->where('hoy.tickets', 0)
            ->has('serie', 14)
            ->has('topProductos', 0)
            ->has('mediosPago', 0)
        );
    }

    public function test_el_selector_de_sucursal_filtra_el_dashboard(): void
    {
        // venta de 7.00 en la sucursal principal
        $producto = $this->crearProducto(precio: 3.50);
        $this->darStock($producto, 50, 2.00);
        $this->abrirCaja(100);
        $this->venderContado($producto->presentaciones->first(), 2);

        $secundaria = Sucursal::create([
            'empresa_id' => $this->empresa->id,
            'codigo_sunat' => '0001',
            'nombre' => 'Secundaria',
            'activo' => true,
        ]);

        // viendo la secundaria: sin ventas
        $this->actingAs($this->admin)
            ->post('/sucursal-activa', ['sucursal_id' => $secundaria->id])
            ->assertRedirect();
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('hoy.total', 0)
            ->where('hoy.tickets', 0)
        );

        // viendo la principal: la venta aparece
        $this->actingAs($this->admin)->post('/sucursal-activa', ['sucursal_id' => $this->sucursal->id]);
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('hoy.total', fn ($v) => abs($v - 7.0) < 0.001)
            ->where('hoy.tickets', 1)
        );

        // de vuelta a todas: tambien aparece
        $this->actingAs($this->admin)->post('/sucursal-activa', ['sucursal_id' => null]);
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('hoy.tickets', 1)
        );
    }

    public function test_un_usuario_restringido_no_puede_ver_otra_sucursal(): void
    {
        $secundaria = Sucursal::create([
            'empresa_id' => $this->empresa->id,
            'codigo_sunat' => '0001',
            'nombre' => 'Secundaria',
            'activo' => true,
        ]);

        // cajero asignado solo a la principal
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');
        $cajero->sucursales()->sync([$this->sucursal->id]);

        $this->actingAs($cajero)
            ->post('/sucursal-activa', ['sucursal_id' => $secundaria->id])
            ->assertForbidden();
    }

    public function test_las_ventas_anuladas_no_cuentan(): void
    {
        $producto = $this->crearProducto(precio: 5.00);
        $this->darStock($producto, 50, 2.00);
        $this->abrirCaja(100);
        $this->venderContado($producto->presentaciones->first(), 1);

        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->actingAs($this->admin)->post("/comprobantes/{$comprobante->id}/anular", ['motivo' => 'Prueba']);

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('hoy.total', 0)
            ->where('hoy.tickets', 0)
            ->has('topProductos', 0)
        );
    }
}
