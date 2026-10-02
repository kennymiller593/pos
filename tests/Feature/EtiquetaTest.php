<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Support\CodigoBarras;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class EtiquetaTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_el_codigo_interno_es_un_ean13_valido_de_uso_interno(): void
    {
        $this->assertSame(2, CodigoBarras::digitoControl('775123456789')); // 7751234567892
        $this->assertTrue(CodigoBarras::esEan13Valido('7751234567892'));
        $this->assertFalse(CodigoBarras::esEan13Valido('7751234567893'));

        $codigo = CodigoBarras::generar($this->empresa->id);

        $this->assertTrue(CodigoBarras::esEan13Valido($codigo));
        $this->assertStringStartsWith('20', $codigo);
    }

    public function test_generar_para_el_formulario_no_guarda_nada(): void
    {
        $producto = $this->crearProducto();

        $codigo = $this->actingAs($this->admin)
            ->postJson('/productos/codigos-barras/generar', ['evitar' => []])
            ->assertOk()
            ->json('codigo');

        $this->assertTrue(CodigoBarras::esEan13Valido($codigo));
        $this->assertNull($producto->presentaciones->first()->fresh()->codigo_barras);
    }

    public function test_asignar_solo_toca_presentaciones_sin_codigo_y_de_la_empresa(): void
    {
        $sinCodigo = $this->crearProducto()->presentaciones->first();
        $conCodigo = $this->crearProducto()->presentaciones->first();
        $conCodigo->update(['codigo_barras' => '7751234567892']);

        $admin = $this->admin;
        $this->crearEscenarioBase(); // otra empresa
        $ajena = $this->crearProducto()->presentaciones->first();

        $codigos = $this->actingAs($admin)
            ->postJson('/productos/codigos-barras/asignar', ['presentacion_ids' => [$sinCodigo->id, $conCodigo->id, $ajena->id]])
            ->assertOk()
            ->json('codigos');

        $this->assertSame([$sinCodigo->id], array_keys($codigos));
        $this->assertSame($codigos[$sinCodigo->id], $sinCodigo->fresh()->codigo_barras);
        $this->assertTrue(CodigoBarras::esEan13Valido($sinCodigo->fresh()->codigo_barras));
        $this->assertSame('7751234567892', $conCodigo->fresh()->codigo_barras);
        $this->assertNull($ajena->fresh()->codigo_barras);
    }

    public function test_la_pantalla_carga_los_productos_elegidos_o_los_de_una_compra(): void
    {
        $producto = $this->crearProducto(precio: 12.5);
        $unidad = $producto->presentaciones->first();
        $caja = $this->agregarPresentacion($producto, 'Caja x12', 12, 140);

        $this->actingAs($this->admin)->get("/productos/etiquetas?ids={$producto->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Productos/Etiquetas')
                ->has('inicial.items', 1)
                ->where('inicial.items.0.presentacion_id', $unidad->id) // la principal
                ->where('inicial.items.0.copias', 1));

        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null, 'tipo_comprobante_codigo' => null, 'serie_numero' => null,
            'fecha' => now()->toDateString(), 'es_credito' => false,
            'items' => [
                ['presentacion_id' => $caja->id, 'cantidad' => 3, 'costo_unitario' => 90],
                ['presentacion_id' => $unidad->id, 'cantidad' => 200, 'costo_unitario' => 8],
            ],
        ])->assertSessionHas('success');
        $compra = Compra::where('empresa_id', $this->empresa->id)->firstOrFail();

        $this->actingAs($this->admin)->get("/productos/etiquetas?compra={$compra->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('inicial.items', 2)
                ->where('inicial.origen', fn ($origen) => str_starts_with($origen, 'Compra del'))
                ->where('inicial.items', fn ($items) => collect($items)->pluck('copias', 'presentacion_id')->all() === [$caja->id => 3, $unidad->id => 50]));
    }

    public function test_permisos(): void
    {
        $presentacion = $this->crearProducto()->presentaciones->first();
        $vendedor = $this->crearUsuario('vendedor', 'vend'.random_int(10000, 99999).'@test.local');

        // el vendedor ve productos: puede imprimir etiquetas, pero no crear códigos
        $this->actingAs($vendedor)->get('/productos/etiquetas')->assertOk();
        $this->actingAs($vendedor)->postJson('/productos/codigos-barras/asignar', ['presentacion_ids' => [$presentacion->id]])->assertForbidden();
        $this->actingAs($vendedor)->postJson('/productos/codigos-barras/generar')->assertForbidden();
    }
}
