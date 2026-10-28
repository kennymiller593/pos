<?php

namespace Tests\Feature;

use App\Models\Compra;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/**
 * Pasó en producción: el negocio tiene un correo por sucursal y entra con los dos en el mismo navegador. La
 * pestaña del usuario de Tarapaca registraba la compra como el usuario de Principal (la sesión ya era de él).
 */
class SesionCoherenteTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    private function compra(): array
    {
        $producto = $this->crearProducto(precio: 20.00);

        return [
            'proveedor_id' => null, 'tipo_comprobante_codigo' => null, 'serie_numero' => null,
            'fecha' => now()->toDateString(), 'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 25, 'costo_unitario' => 20]],
        ];
    }

    public function test_una_pestana_de_otro_usuario_no_registra_y_se_recarga_con_aviso(): void
    {
        $otro = $this->crearUsuario('admin', 'otro'.random_int(1000, 9999).'@test.local');
        $inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => 'http://localhost/compras/crear'];

        // la sesión es del admin, pero la pestaña se dibujó para "otro"
        $this->actingAs($this->admin)->withHeaders([...$inertia, 'X-Usuario' => $otro->id])->post('/compras', $this->compra())
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'http://localhost/compras/crear')
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'otro usuario') && str_contains($e, $this->admin->nombre_completo));
        $this->assertSame(0, Compra::where('empresa_id', $this->empresa->id)->count());

        // navegar desde esa pestaña también recarga, a la página pedida
        $this->actingAs($this->admin)->withHeaders([...$inertia, 'X-Usuario' => $otro->id])->get('/compras')
            ->assertStatus(409)->assertHeader('X-Inertia-Location', 'http://localhost/compras');

        // con el usuario correcto, o sin cabecera (peticiones que no son de Inertia), todo sigue igual
        $this->actingAs($this->admin)->withHeaders([...$inertia, 'X-Usuario' => $this->admin->id])->post('/compras', $this->compra())->assertRedirect(route('compras.index'));
        $this->actingAs($this->admin)->post('/compras', $this->compra())->assertRedirect(route('compras.index'));
        $this->assertSame(2, Compra::where('empresa_id', $this->empresa->id)->count());
    }
}
