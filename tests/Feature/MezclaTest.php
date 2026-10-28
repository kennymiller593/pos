<?php

namespace Tests\Feature;

use App\Models\CapaCosto;
use App\Models\Mezcla;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Receta;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class MezclaTest extends TestCase
{
    use CreaEscenarioPos;

    private Producto $urea;

    private Producto $fosfato;

    private Producto $mezcla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->empresa->update(['mezclas_activo' => true]);

        // urea: dos capas (FIFO cruza), fosfato: una; la mezcla controla lote y se vende a S/ 50
        $this->urea = $this->crearProducto(precio: 4.00, atributos: ['nombre' => 'Urea granulada', 'permite_fraccion' => true]);
        $this->darStock($this->urea, 10, 2.00, diasAtras: 10);
        $this->darStock($this->urea, 10, 3.00, diasAtras: 2);
        $this->fosfato = $this->crearProducto(precio: 12.00, atributos: ['nombre' => 'Fosfato diamónico']);
        $this->darStock($this->fosfato, 5, 10.00);
        $this->mezcla = $this->crearProducto(precio: 50.00, atributos: ['nombre' => 'Mezcla Papa 20-20-20', 'controla_lote' => true]);
    }

    private function datos(array $extra = []): array
    {
        return [
            'producto_id' => $this->mezcla->id,
            'presentacion_id' => null,
            'cantidad' => 4,
            'numero_lote' => 'MZ-0001',
            'fecha_vencimiento' => '2027-12-31',
            'otros_costos' => 4,
            'observacion' => 'tanda de prueba',
            'guardar_formula' => true,
            'insumos' => [
                ['producto_id' => $this->urea->id, 'presentacion_id' => null, 'cantidad' => 12],
                ['producto_id' => $this->fosfato->id, 'presentacion_id' => null, 'cantidad' => 2],
            ],
            ...$extra,
        ];
    }

    public function test_la_mezcla_saca_los_insumos_al_costo_fifo_y_entra_lo_obtenido_con_el_costo_calculado(): void
    {
        $this->actingAs($this->admin)->get('/mezclas/crear')->assertInertia(fn (Assert $p) => $p
            ->where('siguienteCodigo', 'MZ-0001')
            ->where('verCostos', true)
            ->has('productos', 3));

        $this->actingAs($this->admin)->post('/mezclas', $this->datos())
            ->assertRedirect(route('mezclas.index'))
            ->assertSessionHas('success', fn ($s) => str_contains($s, 'MZ-0001') && str_contains($s, 'S/ 12.50'));

        // urea 12: 10 a 2.00 + 2 a 3.00 = 26; fosfato 2 a 10.00 = 20; + otros 4 = 50 entre 4 = 12.50
        $mezcla = Mezcla::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertSame('MZ-0001', $mezcla->codigo());
        $this->assertEqualsWithDelta(46.00, (float) $mezcla->costo_insumos, 0.001);
        $this->assertEqualsWithDelta(50.00, (float) $mezcla->costo_total, 0.001);
        $this->assertEqualsWithDelta(12.50, (float) $mezcla->costo_unitario, 0.0001);
        $this->assertSame(2, $mezcla->insumos()->count());

        $this->assertEqualsWithDelta(8, $this->stockDe($this->urea), 0.001);
        $this->assertEqualsWithDelta(3, $this->stockDe($this->fosfato), 0.001);
        $this->assertEqualsWithDelta(4, $this->stockDe($this->mezcla), 0.001);

        // lo obtenido entra como una capa con su lote y el costo calculado
        $capa = CapaCosto::where('producto_id', $this->mezcla->id)->firstOrFail();
        $this->assertSame($capa->id, $mezcla->capa_id);
        $this->assertEqualsWithDelta(12.50, (float) $capa->costo_unitario, 0.0001);
        $this->assertSame('MZ-0001', $capa->lote->numero_lote);
        $this->assertSame('2027-12-31', $capa->lote->fecha_vencimiento->toDateString());

        // kardex: dos salidas "usado en mezcla" y una entrada "obtenido de mezcla"
        $this->assertSame(2, MovimientoInventario::where('referencia_id', $mezcla->id)->where('tipo', 'mezcla_salida')->count());
        $this->assertSame(1, MovimientoInventario::where('referencia_id', $mezcla->id)->where('tipo', 'mezcla_entrada')->count());

        // la fórmula queda guardada y vuelve al formulario
        $receta = Receta::where('producto_id', $this->mezcla->id)->firstOrFail();
        $this->assertEqualsWithDelta(4, (float) $receta->rinde_base, 0.001);
        $this->assertEqualsWithDelta(12, (float) $receta->insumos()->where('producto_id', $this->urea->id)->value('cantidad_base'), 0.001);
        $this->actingAs($this->admin)->get('/mezclas/crear')->assertInertia(fn (Assert $p) => $p
            ->where('siguienteCodigo', 'MZ-0002')
            ->where("recetas.{$this->mezcla->id}.rinde_base", 4)
            ->has("recetas.{$this->mezcla->id}.insumos", 2));

        // el listado busca por producto obtenido, por insumo y por código
        $total = fn (string $q) => $this->actingAs($this->admin)->get('/mezclas?'.$q)->assertOk()->viewData('page')['props']['mezclas']['total'];
        $this->assertSame(1, $total('buscar=papa'));
        $this->assertSame(1, $total('buscar=fósfato'));
        $this->assertSame(1, $total('buscar=mz-0001'));
        $this->assertSame(0, $total('buscar=glifosato'));
        $this->assertSame(0, $total('desde=2030-01-01'));
    }

    public function test_sin_stock_suficiente_no_se_registra_nada(): void
    {
        $this->actingAs($this->admin)->post('/mezclas', $this->datos(['insumos' => [['producto_id' => $this->urea->id, 'presentacion_id' => null, 'cantidad' => 25]]]))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Stock insuficiente de "Urea granulada"'));

        $this->assertSame(0, Mezcla::where('empresa_id', $this->empresa->id)->count());
        $this->assertEqualsWithDelta(20, $this->stockDe($this->urea), 0.001);

        // el producto obtenido no puede ser su propio insumo
        $this->actingAs($this->admin)->post('/mezclas', $this->datos(['insumos' => [['producto_id' => $this->mezcla->id, 'presentacion_id' => null, 'cantidad' => 1]]]))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'insumo de sí mismo'));
    }

    public function test_anular_devuelve_los_insumos_y_retira_lo_obtenido(): void
    {
        $this->actingAs($this->admin)->post('/mezclas', $this->datos())->assertSessionHas('success');
        $mezcla = Mezcla::where('empresa_id', $this->empresa->id)->firstOrFail();

        $this->actingAs($this->admin)->post("/mezclas/{$mezcla->id}/anular", ['motivo' => 'cantidades mal registradas'])->assertSessionHas('success');

        $this->assertSame('anulada', $mezcla->fresh()->estado);
        $this->assertEqualsWithDelta(20, $this->stockDe($this->urea), 0.001);
        $this->assertEqualsWithDelta(5, $this->stockDe($this->fosfato), 0.001);
        $this->assertEqualsWithDelta(0, $this->stockDe($this->mezcla), 0.001);
        // las capas de la urea recuperan lo consumido
        $this->assertEqualsWithDelta(20, (float) CapaCosto::where('producto_id', $this->urea->id)->sum('cantidad_restante'), 0.001);
        $this->assertSame(0, CapaCosto::where('producto_id', $this->mezcla->id)->count());
        $this->assertSame(1, MovimientoInventario::where('referencia_id', $mezcla->id)->where('tipo', 'mezcla_anulada')->count());
        $this->assertSame(2, MovimientoInventario::where('referencia_id', $mezcla->id)->where('tipo', 'devolucion')->count());

        $this->actingAs($this->admin)->post("/mezclas/{$mezcla->id}/anular", ['motivo' => 'otra vez'])->assertSessionHas('error');
    }

    public function test_no_se_anula_si_ya_se_vendio_parte_de_lo_obtenido(): void
    {
        $this->actingAs($this->admin)->post('/mezclas', $this->datos())->assertSessionHas('success');
        $mezcla = Mezcla::where('empresa_id', $this->empresa->id)->firstOrFail();

        $this->abrirCaja();
        $this->venderContado($this->mezcla->presentaciones->first(), 1)->assertSessionHas('success');

        $this->actingAs($this->admin)->post("/mezclas/{$mezcla->id}/anular", ['motivo' => 'error'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'ya se vendió o movió'));
        $this->assertSame('registrada', $mezcla->fresh()->estado);
        $this->assertEqualsWithDelta(3, $this->stockDe($this->mezcla), 0.001);
    }

    public function test_el_modulo_apagado_y_los_permisos_cierran_la_seccion(): void
    {
        $almacenero = $this->crearUsuario('almacenero', 'almacen'.random_int(1000, 9999).'@test.local');
        $cajero = $this->crearUsuario('cajero', 'caja'.random_int(1000, 9999).'@test.local');

        $this->actingAs($this->admin)->post('/mezclas', $this->datos())->assertSessionHas('success');
        $mezcla = Mezcla::where('empresa_id', $this->empresa->id)->firstOrFail();

        $this->actingAs($almacenero)->get('/mezclas/crear')->assertOk();
        $this->actingAs($cajero)->get('/mezclas')->assertForbidden();
        $this->actingAs($almacenero)->post("/mezclas/{$mezcla->id}/anular", ['motivo' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('auth.user.empresa.mezclas_activo', true));

        // apagado: ni el admin entra y el menú no lo muestra (el usuario en memoria relee su empresa)
        $this->empresa->update(['mezclas_activo' => false]);
        $this->admin->unsetRelation('empresa');
        $this->actingAs($this->admin)->get('/mezclas')->assertForbidden();
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('auth.user.empresa.mezclas_activo', false));

        // se enciende desde Configuración → Empresa
        $this->actingAs($this->admin)->put('/empresa', [
            'razon_social' => $this->empresa->razon_social, 'regimen_tributario' => 'MYPE', 'rubro_codigo' => $this->empresa->rubro_codigo, 'mezclas_activo' => true,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->empresa->fresh()->mezclas_activo);
        $this->admin->unsetRelation('empresa');
        $this->actingAs($this->admin)->get('/mezclas')->assertOk();
    }
}
