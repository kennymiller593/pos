<?php

namespace Tests\Feature;

use App\Models\Mezcla;
use App\Models\Sucursal;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** Con dos sucursales y el selector en la segunda, la mezcla se prepara con el stock de esa sucursal. */
class MezclaSucursalTest extends TestCase
{
    use CreaEscenarioPos;

    public function test_la_mezcla_usa_la_sucursal_del_selector(): void
    {
        $this->crearEscenarioBase();
        $this->empresa->update(['mezclas_activo' => true]);
        $huanuco = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Sucursal Huanuco', 'activo' => true]);

        $atun = $this->crearProducto(precio: 12.00, atributos: ['nombre' => 'Atún Pack x3']);
        $capa = $this->darStock($atun, 14, 9.79);
        // el stock está solo en Huanuco
        $capa->update(['sucursal_id' => $huanuco->id]);
        \App\Models\Stock::where('producto_id', $atun->id)->update(['sucursal_id' => $huanuco->id]);
        $gaseosa = $this->crearProducto(precio: 5.49, atributos: ['nombre' => 'Gaseosa 1.5L']);

        $sesion = ['sucursal_activa_id' => $huanuco->id];
        $this->actingAs($this->admin)->withSession($sesion)->get('/mezclas/crear')->assertInertia(fn (Assert $p) => $p
            ->where('sucursalNombre', 'Sucursal Huanuco')
            ->where('sucursalId', $huanuco->id)
            ->where('productos.0.stock', fn ($v) => (float) $v === 14.0));

        $this->actingAs($this->admin)->withSession($sesion)->post('/mezclas', [
            'sucursal_id' => $huanuco->id,
            'producto_id' => $gaseosa->id, 'presentacion_id' => null, 'cantidad' => 8, 'otros_costos' => 0,
            'insumos' => [['producto_id' => $atun->id, 'presentacion_id' => null, 'cantidad' => 1]],
        ])->assertRedirect(route('mezclas.index'))->assertSessionHas('success');

        $this->assertSame($huanuco->id, Mezcla::where('empresa_id', $this->empresa->id)->value('sucursal_id'));
    }
}
