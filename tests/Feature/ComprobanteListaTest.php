<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class ComprobanteListaTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();

        $producto = $this->crearProducto(precio: 10);
        $this->darStock($producto, 100, 4);
        $this->abrirCaja();

        // tres ventas: hace 40 días, hace 5 días y hoy (con totales distintos)
        foreach ([[40, 1], [5, 3], [0, 2]] as [$dias, $cantidad]) {
            $this->venderContado($producto->presentaciones->first(), $cantidad)->assertSessionHas('success');
            $ultimo = Comprobante::where('empresa_id', $this->empresa->id)->orderByDesc('correlativo')->value('id');
            Comprobante::whereKey($ultimo)->update(['fecha_emision' => now()->subDays($dias)->toDateString()]);
        }
    }

    private function totales(string $query): array
    {
        $totales = [];
        $this->actingAs($this->admin)->get('/comprobantes'.$query)
            ->assertInertia(function (AssertableInertia $page) use (&$totales) {
                $totales = collect($page->toArray()['props']['comprobantes']['data'])->map(fn ($c) => (float) $c['total'])->all();
            });

        return $totales;
    }

    public function test_filtra_por_rango_de_fechas(): void
    {
        $this->assertCount(3, $this->totales(''));
        $this->assertSame([20.0, 30.0], $this->totales('?desde='.now()->subDays(7)->toDateString()));
        $this->assertSame([10.0], $this->totales('?hasta='.now()->subDays(30)->toDateString()));
        $this->assertSame([30.0], $this->totales('?desde='.now()->subDays(6)->toDateString().'&hasta='.now()->subDay()->toDateString()));
        $this->assertCount(3, $this->totales('?desde=no-es-fecha')); // se ignora
    }

    public function test_ordena_por_columna(): void
    {
        $this->assertSame([20.0, 30.0, 10.0], $this->totales('')); // por defecto, la fecha mas reciente primero
        $this->assertSame([30.0, 20.0, 10.0], $this->totales('?orden=total&dir=desc'));
        $this->assertSame([10.0, 20.0, 30.0], $this->totales('?orden=total&dir=asc'));
        $this->assertSame([20.0, 30.0, 10.0], $this->totales('?orden=inventada')); // columna desconocida: orden por defecto
    }
}
