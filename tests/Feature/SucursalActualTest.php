<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Compra;
use App\Models\Plan;
use App\Models\SerieCorrelativo;
use App\Models\Stock;
use App\Models\Sucursal;
use App\Models\Transferencia;
use App\Models\Usuario;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/**
 * Una sola noción de "sucursal actual" en todo el sistema: lo que dice el selector es lo que
 * muestran el dashboard, las listas y las pantallas de trabajo; quien solo puede entrar a una
 * sucursal siempre está en esa.
 */
class SucursalActualTest extends TestCase
{
    use CreaEscenarioPos;

    private Sucursal $secundaria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();

        $this->secundaria = Sucursal::create([
            'empresa_id' => $this->empresa->id,
            'codigo_sunat' => '0001',
            'nombre' => 'Secundaria',
            'activo' => true,
        ]);
    }

    private function usuarioDe(string $rol, Sucursal $sucursal): Usuario
    {
        $usuario = $this->crearUsuario($rol, $rol.random_int(10000, 99999).'@test.local');
        $usuario->update(['sucursal_id' => $sucursal->id]);
        $usuario->sucursales()->sync([$sucursal->id]);

        return $usuario;
    }

    public function test_un_admin_de_una_sola_sucursal_ve_solo_esa_aunque_no_use_el_selector(): void
    {
        // venta de 7.00 en la principal
        $producto = $this->crearProducto(precio: 3.50);
        $this->darStock($producto, 50, 2.00);
        $this->abrirCaja(100);
        $this->venderContado($producto->presentaciones->first(), 2);

        // admin asignado solo a la secundaria: nunca eligió nada en el selector
        $adminSecundaria = $this->usuarioDe('admin', $this->secundaria);

        $this->actingAs($adminSecundaria)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->where('hoy.total', 0)
            ->where('hoy.tickets', 0)
            ->where('auth.user.sucursal_consulta_id', $this->secundaria->id)
            ->where('auth.user.sucursal_etiqueta', 'Secundaria')
            ->where('auth.user.puede_ver_todas', false)
            ->where('auth.user.empresa.multisucursal', true)
        );

        // la lista de comprobantes tampoco le muestra la venta de la otra sucursal
        $this->actingAs($adminSecundaria)->get('/comprobantes')->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina->has('comprobantes.data', 0));

        // el dueño (sin restricción) la ve, con la sucursal de cada venta
        $this->actingAs($this->admin)->get('/comprobantes')->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->has('comprobantes.data', 1)
            ->where('comprobantes.data.0.sucursal.nombre', $this->sucursal->nombre)
            ->where('auth.user.sucursal_etiqueta', 'Todas las sucursales')
        );
    }

    public function test_abrir_caja_pasa_el_selector_a_la_sucursal_de_esa_caja(): void
    {
        // el dueño estaba viendo la secundaria
        $this->actingAs($this->admin)->post('/sucursal-activa', ['sucursal_id' => $this->secundaria->id]);

        $this->actingAs($this->admin)->post('/caja/abrir', ['caja_id' => $this->caja->id, 'monto_inicial' => 50])
            ->assertSessionHas('success');

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('auth.user.sucursal_consulta_id', $this->sucursal->id)
            ->where('auth.user.caja_abierta.sucursal_id', $this->sucursal->id)
            ->where('auth.user.caja_abierta.caja_nombre', $this->caja->nombre)
        );
    }

    public function test_viendo_todas_la_compra_entra_a_la_sucursal_elegida_en_el_formulario(): void
    {
        $producto = $this->crearProducto(precio: 10.00);
        $unidad = $producto->presentaciones->first();

        // el formulario ofrece elegir la sucursal porque el dueño está viendo "todas"
        $this->actingAs($this->admin)->get('/compras/crear')->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->where('sucursalDestino', null)
            ->has('sucursales', 2)
        );

        $this->actingAs($this->admin)->post('/compras', [
            'sucursal_id' => $this->secundaria->id,
            'proveedor_id' => null,
            'tipo_comprobante_codigo' => '01',
            'serie_numero' => 'F001-000100',
            'fecha' => now()->toDateString(),
            'es_credito' => false,
            'items' => [['presentacion_id' => $unidad->id, 'cantidad' => 4, 'costo_unitario' => 6.00]],
        ])->assertRedirect(route('compras.index'));

        $this->assertSame($this->secundaria->id, Compra::where('empresa_id', $this->empresa->id)->value('sucursal_id'));
        $this->assertEqualsWithDelta(4, (float) Stock::where('producto_id', $producto->id)->where('sucursal_id', $this->secundaria->id)->value('cantidad'), 0.001);
        $this->assertNull(Stock::where('producto_id', $producto->id)->where('sucursal_id', $this->sucursal->id)->value('cantidad'));

        // con una sucursal elegida en el selector, el formulario ya no pregunta y manda a esa
        $this->actingAs($this->admin)->post('/sucursal-activa', ['sucursal_id' => $this->sucursal->id]);
        $this->actingAs($this->admin)->get('/compras/crear')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('sucursalDestino', $this->sucursal->nombre)
            ->has('sucursales', 0)
        );
        // y una sucursal a la que no puede entrar se ignora
        $adminSecundaria = $this->usuarioDe('admin', $this->secundaria);
        $this->actingAs($adminSecundaria)->post('/compras', [
            'sucursal_id' => $this->sucursal->id,
            'proveedor_id' => null,
            'tipo_comprobante_codigo' => '01',
            'serie_numero' => 'F001-000101',
            'fecha' => now()->toDateString(),
            'es_credito' => false,
            'items' => [['presentacion_id' => $unidad->id, 'cantidad' => 1, 'costo_unitario' => 6.00]],
        ])->assertRedirect(route('compras.index'));
        $this->assertSame($this->secundaria->id, Compra::where('serie_numero', 'F001-000101')->value('sucursal_id'));
    }

    public function test_el_stock_viendo_todas_muestra_una_columna_por_sucursal(): void
    {
        $producto = $this->crearProducto(precio: 10.00);
        $this->darStock($producto, 7, 2.00);
        Stock::create(['empresa_id' => $this->empresa->id, 'producto_id' => $producto->id, 'sucursal_id' => $this->secundaria->id, 'cantidad' => 3]);

        $this->actingAs($this->admin)->get('/stock')->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->where('sucursal', null)
            ->has('sucursales', 2)
            ->where('productos.data.0.stock', fn ($v) => abs($v - 10) < 0.001)
            ->where("productos.data.0.por_sucursal.{$this->sucursal->id}", fn ($v) => abs($v - 7) < 0.001)
            ->where("productos.data.0.por_sucursal.{$this->secundaria->id}", fn ($v) => abs($v - 3) < 0.001)
        );

        // con una elegida: solo esa, sin columnas extra
        $this->actingAs($this->admin)->post('/sucursal-activa', ['sucursal_id' => $this->secundaria->id]);
        $this->actingAs($this->admin)->get('/stock')->assertInertia(fn (Assert $pagina) => $pagina
            ->where('sucursal', 'Secundaria')
            ->has('sucursales', 0)
            ->where('productos.data.0.stock', fn ($v) => abs($v - 3) < 0.001)
        );

        // el ajuste viendo "todas" va a la sucursal elegida en el modal
        $this->actingAs($this->admin)->post('/sucursal-activa', ['sucursal_id' => null]);
        $this->actingAs($this->admin)->post("/stock/{$producto->id}/ajustar", [
            'sucursal_id' => $this->secundaria->id, 'direccion' => 'entrada', 'cantidad' => 2, 'costo_unitario' => 2.50,
        ])->assertSessionHas('success');
        $this->assertEqualsWithDelta(5, (float) Stock::where('producto_id', $producto->id)->where('sucursal_id', $this->secundaria->id)->value('cantidad'), 0.001);
    }

    public function test_el_cajero_recibe_la_transferencia_solo_en_su_sucursal(): void
    {
        $producto = $this->crearProducto(precio: 10.00);
        $this->darStock($producto, 20, 2.00);

        $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_origen_id' => $this->sucursal->id,
            'sucursal_destino_id' => $this->secundaria->id,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 5]],
        ])->assertRedirect(route('transferencias.index'));
        $transferencia = Transferencia::where('empresa_id', $this->empresa->id)->firstOrFail();

        // el cajero de la principal (origen) no puede confirmar la llegada
        $cajeroOrigen = $this->usuarioDe('cajero', $this->sucursal);
        $this->actingAs($cajeroOrigen)->post("/transferencias/{$transferencia->id}/recibir")->assertSessionHas('error');
        $this->assertSame('en_transito', $transferencia->fresh()->estado);

        // el cajero de la secundaria (destino) sí
        $cajeroDestino = $this->usuarioDe('cajero', $this->secundaria);
        $this->actingAs($cajeroDestino)->post("/transferencias/{$transferencia->id}/recibir")->assertSessionHas('success');
        $this->assertSame('recibida', $transferencia->fresh()->estado);
        $this->assertEqualsWithDelta(5, (float) Stock::where('producto_id', $producto->id)->where('sucursal_id', $this->secundaria->id)->value('cantidad'), 0.001);

        // y la lista de transferencias de ese cajero solo trae las que tocan su sucursal
        $this->actingAs($cajeroDestino)->get('/transferencias')->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina->has('transferencias.data', 1));
    }

    public function test_una_sucursal_nueva_nace_con_sus_series_y_reactivarla_respeta_el_plan(): void
    {
        // el plan de prueba admite 2 sucursales y ya hay 2: se amplia para crear la tercera
        Plan::where('codigo', 'prueba')->update(['max_sucursales' => null]);
        $this->actingAs($this->admin)->post('/sucursales', [
            'nombre' => 'Tercera', 'codigo_sunat' => '0002', 'direccion' => null, 'ubigeo' => null, 'telefono' => null, 'activo' => true,
        ])->assertSessionHas('success');
        $tercera = Sucursal::where('empresa_id', $this->empresa->id)->where('nombre', 'Tercera')->firstOrFail();

        // la principal aún no vendió (sin series) y la secundaria tampoco: la tercera toma las primeras libres
        $series = SerieCorrelativo::where('sucursal_id', $tercera->id)->pluck('serie', 'tipo_comprobante_codigo')->all();
        ksort($series);
        $this->assertSame(['00' => 'NV01', '01' => 'F001', '03' => 'B001', '09' => 'T001'], $series);
        $this->assertTrue(Caja::where('sucursal_id', $tercera->id)->where('nombre', 'Caja 1')->exists());

        // desactivarla y bajar el plan a 2 sucursales: no se puede volver a activar
        $this->actingAs($this->admin)->put("/sucursales/{$tercera->id}", [
            'nombre' => 'Tercera', 'codigo_sunat' => '0002', 'direccion' => null, 'ubigeo' => null, 'telefono' => null, 'activo' => false,
        ])->assertSessionHas('success');
        Plan::where('codigo', 'prueba')->update(['max_sucursales' => 2]);
        $this->actingAs($this->admin)->put("/sucursales/{$tercera->id}", [
            'nombre' => 'Tercera', 'codigo_sunat' => '0002', 'direccion' => null, 'ubigeo' => null, 'telefono' => null, 'activo' => true,
        ])->assertSessionHas('error');
        $this->assertFalse($tercera->fresh()->activo);
        Plan::where('codigo', 'prueba')->update(['max_sucursales' => 2]);
    }

    public function test_cada_sucursal_ve_y_cobra_solo_sus_cuentas_por_cobrar(): void
    {
        // venta al crédito de 60.00 en la principal
        $producto = $this->crearProducto(precio: 30);
        $this->darStock($producto, 10, 20);
        $this->abrirCaja(100);
        $cliente = $this->crearCliente(limiteCredito: 100);
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => $cliente->id, 'es_credito' => true,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 2]], 'pagos' => [],
        ])->assertSessionHas('success');
        $cuenta = \App\Models\CuentaPorCobrar::where('cliente_id', $cliente->id)->firstOrFail();

        // el administrador de la secundaria no la ve: ni en el dashboard, ni en la campanita, ni en cuentas por cobrar
        $adminSecundaria = $this->usuarioDe('admin', $this->secundaria);
        $this->actingAs($adminSecundaria)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('pendientes.por_cobrar', 0));
        $this->actingAs($adminSecundaria)->get('/cuentas-por-cobrar')->assertInertia(fn (Assert $p) => $p
            ->has('cuentas.data', 0)->where('resumen.por_cobrar', 0)->where('resumen.cuentas_pendientes', 0));
        $this->assertStringNotContainsString('por_cobrar', $this->actingAs($adminSecundaria)->get('/notificaciones')->getContent());

        // el dueño, viendo la secundaria en el selector, tampoco; viendo todas, sí
        $this->actingAs($this->admin)->post('/sucursal-activa', ['sucursal_id' => $this->secundaria->id]);
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('pendientes.por_cobrar', 0));
        $this->actingAs($this->admin)->post('/sucursal-activa', ['sucursal_id' => null]);
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('pendientes.por_cobrar', 60));
        $this->actingAs($this->admin)->get('/cuentas-por-cobrar')->assertInertia(fn (Assert $p) => $p
            ->has('cuentas.data', 1)->where('resumen.por_cobrar', 60)->where('cajaSucursalId', $this->sucursal->id));

        // con caja abierta en la secundaria no se puede cobrar una deuda de la principal
        $cajaSecundaria = Caja::create(['empresa_id' => $this->empresa->id, 'sucursal_id' => $this->secundaria->id, 'nombre' => 'Caja S', 'activo' => true]);
        $this->actingAs($adminSecundaria)->post('/caja/abrir', ['caja_id' => $cajaSecundaria->id, 'monto_inicial' => 50])->assertSessionHas('success');
        $this->actingAs($adminSecundaria)->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 20, 'medio_pago_codigo' => 'efectivo'])
            ->assertSessionHas('error', 'Esta deuda es de la sucursal '.$this->sucursal->nombre.': se cobra desde una caja de esa sucursal.');
        $this->assertSame(0, $cuenta->cobros()->count());

        // desde la caja de la principal, sí
        $this->actingAs($this->admin)->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 20, 'medio_pago_codigo' => 'efectivo'])->assertSessionHasNoErrors();
        $this->assertSame(1, $cuenta->cobros()->count());
    }
}
