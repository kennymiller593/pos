<?php

namespace Tests\Feature;

use App\Models\CapaCosto;
use App\Models\Compra;
use App\Models\CuentaPorPagar;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class CompraAnulacionTest extends TestCase
{
    use CreaEscenarioPos;

    private Producto $producto;

    private Proveedor $proveedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();

        $this->producto = $this->crearProducto(precio: 10.00);
        $this->proveedor = Proveedor::create([
            'empresa_id' => $this->empresa->id,
            'ruc' => '20'.random_int(100000000, 999999999),
            'razon_social' => 'Proveedor Test '.random_int(1000, 9999),
        ]);
    }

    private function comprar(float $cantidad = 10, bool $credito = false, ?string $serie = 'F001-000123'): Compra
    {
        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => $this->proveedor->id,
            'tipo_comprobante_codigo' => '01',
            'serie_numero' => $serie,
            'fecha' => now()->toDateString(),
            'es_credito' => $credito,
            'items' => [['presentacion_id' => $this->producto->presentaciones->first()->id, 'cantidad' => $cantidad, 'costo_unitario' => 6.00]],
        ])->assertSessionHas('success');

        return Compra::where('empresa_id', $this->empresa->id)->latest('creado_en')->firstOrFail();
    }

    private function anular(Compra $compra)
    {
        return $this->actingAs($this->admin)->post("/compras/{$compra->id}/anular", ['motivo' => 'cantidad equivocada']);
    }

    public function test_anular_una_compra_retira_el_stock_las_capas_y_la_deuda(): void
    {
        $compra = $this->comprar(cantidad: 10, credito: true);
        $this->assertSame(10.0, $this->stockDe($this->producto));
        $this->assertSame(1, CuentaPorPagar::where('compra_id', $compra->id)->count());

        $this->anular($compra)->assertSessionHas('success');

        $compra->refresh();
        $this->assertSame('anulada', $compra->estado);
        $this->assertSame('cantidad equivocada', $compra->motivo_anulacion);
        $this->assertSame($this->admin->id, $compra->anulada_por);

        $this->assertSame(0.0, $this->stockDe($this->producto));
        $this->assertSame(0, CapaCosto::where('producto_id', $this->producto->id)->count());
        $this->assertSame(0, CuentaPorPagar::where('compra_id', $compra->id)->count());
        $this->assertSame(1, MovimientoInventario::where('producto_id', $this->producto->id)->where('tipo', 'compra_anulada')->count());
        $this->assertDatabaseHas('auditoria', ['entidad_id' => $compra->id, 'accion' => 'compra.anulada']);

        // anulada, ya no se puede anular otra vez
        $this->anular($compra)->assertSessionHas('error');
    }

    public function test_no_se_anula_si_ya_se_vendio_parte_de_la_mercaderia(): void
    {
        $compra = $this->comprar(cantidad: 10);
        $this->abrirCaja();
        $this->venderContado($this->producto->presentaciones->first(), 3)->assertSessionHas('success');

        $this->anular($compra)->assertSessionHas('error', fn ($m) => str_contains($m, 'ya se vendió'));

        $this->assertSame('registrada', $compra->fresh()->estado);
        $this->assertSame(7.0, $this->stockDe($this->producto));
    }

    public function test_no_se_anula_si_la_deuda_tiene_pagos(): void
    {
        $compra = $this->comprar(cantidad: 5, credito: true);
        CuentaPorPagar::where('compra_id', $compra->id)->update(['monto_pagado' => 10, 'estado' => 'parcial']);

        $this->anular($compra)->assertSessionHas('error', fn ($m) => str_contains($m, 'pagos'));
        $this->assertSame('registrada', $compra->fresh()->estado);
    }

    public function test_el_mismo_comprobante_del_proveedor_no_se_registra_dos_veces(): void
    {
        $this->comprar(serie: 'F001-000777');

        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => $this->proveedor->id,
            'tipo_comprobante_codigo' => '01',
            'serie_numero' => 'F001-000777',
            'fecha' => now()->toDateString(),
            'es_credito' => false,
            'items' => [['presentacion_id' => $this->producto->presentaciones->first()->id, 'cantidad' => 1, 'costo_unitario' => 6.00]],
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'Ya registraste'));

        $this->assertSame(1, Compra::where('empresa_id', $this->empresa->id)->count());

        // tras anular la primera, se puede volver a registrar
        $this->anular(Compra::where('empresa_id', $this->empresa->id)->firstOrFail());
        $this->comprar(serie: 'F001-000777');
        $this->assertSame(2, Compra::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_solo_el_admin_anula_compras(): void
    {
        $compra = $this->comprar();
        $almacenero = $this->crearUsuario('almacenero', 'alm'.random_int(10000, 99999).'@test.local');

        $this->actingAs($almacenero)->post("/compras/{$compra->id}/anular", ['motivo' => 'x'])->assertForbidden();
    }
}
