<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Comprobante;
use App\Models\Rol;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class AuditoriaTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    private function eventos(string $accion): \Illuminate\Support\Collection
    {
        return Auditoria::where('empresa_id', $this->empresa->id)->where('accion', $accion)->get();
    }

    public function test_anular_una_venta_deja_constancia(): void
    {
        $producto = $this->crearProducto(precio: 5.00);
        $this->darStock($producto, 10, 2.00);
        $this->abrirCaja();
        $this->venderContado($producto->presentaciones->first(), 2);

        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->actingAs($this->admin)->post("/comprobantes/{$comprobante->id}/anular", ['motivo' => 'Cliente se arrepintió']);

        $evento = $this->eventos('comprobante.anulado')->sole();
        $this->assertSame($this->admin->id, $evento->usuario_id);
        $this->assertSame('Cliente se arrepintió', $evento->detalle['motivo']);
        $this->assertEqualsWithDelta(10.0, $evento->detalle['total'], 0.001);
    }

    public function test_vender_con_precio_manual_deja_constancia(): void
    {
        $producto = $this->crearProducto(precio: 5.00);
        $this->darStock($producto, 10, 2.00);
        $this->abrirCaja();

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1, 'precio_unitario' => 4.00]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 4.00, 'referencia' => null]],
        ])->assertSessionHas('success');

        $evento = $this->eventos('venta.precio_modificado')->sole();
        $this->assertEqualsWithDelta(5.00, $evento->detalle['lineas'][0]['precio_lista'], 0.001);
        $this->assertEqualsWithDelta(4.00, $evento->detalle['lineas'][0]['precio_cobrado'], 0.001);

        // vender a precio de lista NO genera evento
        $this->venderContado($producto->presentaciones->first(), 1);
        $this->assertCount(1, $this->eventos('venta.precio_modificado'));
    }

    public function test_cierre_de_caja_con_diferencia_deja_constancia(): void
    {
        $this->abrirCaja(100);
        $this->actingAs($this->admin)->post('/caja/cerrar', ['monto_cierre' => 90]);

        $evento = $this->eventos('caja.cierre_con_diferencia')->sole();
        $this->assertEqualsWithDelta(-10.0, $evento->detalle['diferencia'], 0.001);

        // cierre cuadrado no genera evento
        $this->abrirCaja(50);
        $this->actingAs($this->admin)->post('/caja/cerrar', ['monto_cierre' => 50]);
        $this->assertCount(1, $this->eventos('caja.cierre_con_diferencia'));
    }

    public function test_cambio_de_precio_de_producto_deja_constancia(): void
    {
        $producto = $this->crearProducto(precio: 5.00);
        $unidad = $producto->presentaciones->first();

        $this->actingAs($this->admin)->put("/productos/{$producto->id}", [
            'codigo_interno' => $producto->codigo_interno,
            'nombre' => $producto->nombre,
            'categoria_id' => null,
            'marca_id' => null,
            'unidad_base_codigo' => $producto->unidad_base_codigo,
            'tipo_afectacion_codigo' => $producto->tipo_afectacion_codigo,
            'permite_fraccion' => false,
            'controla_lote' => false,
            'controla_stock' => true,
            'stock_minimo' => 0,
            'activo' => true,
            'presentaciones' => [[
                'id' => $unidad->id,
                'nombre' => $unidad->nombre,
                'unidad_codigo' => $unidad->unidad_codigo,
                'factor_conversion' => 1,
                'precio_venta' => 6.50,
                'precio_mayorista' => null,
                'cantidad_mayorista' => null,
                'codigo_barras' => null,
                'es_default' => true,
            ]],
        ])->assertSessionHas('success');

        $evento = $this->eventos('producto.precio_actualizado')->sole();
        $this->assertEqualsWithDelta(5.00, $evento->detalle['cambios'][0]['de'], 0.001);
        $this->assertEqualsWithDelta(6.50, $evento->detalle['cambios'][0]['a'], 0.001);
    }

    public function test_editar_usuario_deja_constancia_de_los_cambios(): void
    {
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');

        $this->actingAs($this->admin)->put("/usuarios/{$cajero->id}", [
            'nombre_completo' => $cajero->nombre_completo,
            'email' => $cajero->email,
            'rol_id' => Rol::where('codigo', 'vendedor')->value('id'),
            'sucursal_ids' => [],
            'password' => null,
            'password_confirmation' => null,
            'activo' => true,
        ])->assertSessionHas('success');

        $evento = $this->eventos('usuario.actualizado')->sole();
        $this->assertSame('Vendedor', $evento->detalle['cambios']['rol']['a'] ?? null);
    }

    public function test_solo_el_admin_ve_la_auditoria(): void
    {
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');

        $this->actingAs($cajero)->get('/auditoria')->assertForbidden();
        $this->actingAs($this->admin)->get('/auditoria')->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Auditoria/Index')
        );
    }
}
