<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\ProductoGrupo;
use App\Models\ProductoSerie;
use App\Models\Sucursal;
use App\Models\TipoAfectacionIgv;
use App\Services\VarianteService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/**
 * Serie / IMEI a nivel de modelo: una casilla en el formulario del modelo marca todas sus variantes como
 * `controla_serie`, con las mismas reglas del producto (SerieService::impedimentoParaCambiar).
 */
class VarianteSerieModeloTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->empresa->update(['variantes_activo' => true, 'series_activo' => true]);
    }

    /** Galaxy A15 en Capacidad 128GB/256GB y Color Negro/Azul: 4 variantes a S/ 700. */
    private function datos(array $extra = []): array
    {
        return [
            'nombre' => 'Galaxy A15',
            'categoria_id' => null,
            'marca_id' => null,
            'unidad_base_codigo' => 'NIU',
            'tipo_afectacion_codigo' => TipoAfectacionIgv::where('afecto', true)->value('codigo'),
            'precio_base' => 700,
            'costo' => null,
            'atributos' => [
                ['nombre' => 'Capacidad', 'valores' => ['128GB', '256GB']],
                ['nombre' => 'Color', 'valores' => ['Negro', 'Azul']],
            ],
            'variantes' => [],
            ...$extra,
        ];
    }

    private function crearModelo(array $extra = []): ProductoGrupo
    {
        $this->actingAs($this->admin)->post('/productos/modelos', $this->datos($extra))->assertSessionHasNoErrors()->assertRedirect();

        return ProductoGrupo::where('empresa_id', $this->empresa->id)->where('nombre', $extra['nombre'] ?? 'Galaxy A15')->firstOrFail();
    }

    private function actualizar(ProductoGrupo $grupo, array $extra = [])
    {
        return $this->actingAs($this->admin)->put("/productos/modelos/{$grupo->id}", $this->datos(['nombre' => $grupo->nombre, ...$extra]));
    }

    private function variante(ProductoGrupo $grupo, string ...$valores): ?Producto
    {
        return $grupo->variantes()->with('presentaciones')->get()
            ->first(fn (Producto $p) => VarianteService::valoresDe($p, $grupo->atributos) === $valores);
    }

    /** Compra de unidades con serie de esa variante (así entran con su número, como en la tienda). */
    private function comprar(Producto $variante, array $series)
    {
        return $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null,
            'tipo_comprobante_codigo' => '00',
            'serie_numero' => 'N-'.random_int(100000, 999999),
            'fecha' => now()->toDateString(),
            'es_credito' => false,
            'items' => [[
                'presentacion_id' => $variante->presentaciones->first()->id,
                'cantidad' => count($series),
                'costo_unitario' => 500,
                'series' => $series,
            ]],
        ]);
    }

    public function test_marcar_el_modelo_marca_todas_sus_variantes_al_crear_y_al_editar(): void
    {
        // al crear con la casilla: las 4 nacen con serie
        $grupo = $this->crearModelo(['controla_serie' => true]);
        $this->assertSame(4, $grupo->variantes()->count());
        $this->assertSame(4, $grupo->variantes()->where('controla_serie', true)->count());
        $this->assertTrue($grupo->controlaSerie());

        // el formulario de edición lo sabe
        $this->actingAs($this->admin)->get("/productos/modelos/{$grupo->id}/editar")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('modelo.controla_serie', true));

        // un modelo que nació sin serie: al marcarlo después, todas las que ya existían quedan con serie
        $otro = $this->crearModelo(['nombre' => 'Redmi 13']);
        $this->assertSame(0, $otro->variantes()->where('controla_serie', true)->count());
        $this->actingAs($this->admin)->get("/productos/modelos/{$otro->id}/editar")
            ->assertInertia(fn (Assert $p) => $p->where('modelo.controla_serie', false));

        $this->actualizar($otro, ['controla_serie' => true])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(4, $otro->variantes()->where('controla_serie', true)->count());

        // y ya se compra con series como cualquier producto con serie
        $variante = $this->variante($otro, '128GB', 'Negro');
        $this->comprar($variante, ['356000000000011'])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(1, ProductoSerie::where('producto_id', $variante->id)->where('estado', 'disponible')->count());
    }

    public function test_una_variante_agregada_despues_nace_con_serie(): void
    {
        $grupo = $this->crearModelo(['controla_serie' => true]);

        // se agrega la capacidad 512GB: 2 variantes nuevas
        $atributos = [
            ['nombre' => 'Capacidad', 'valores' => ['128GB', '256GB', '512GB']],
            ['nombre' => 'Color', 'valores' => ['Negro', 'Azul']],
        ];
        $this->actualizar($grupo, ['controla_serie' => true, 'atributos' => $atributos])->assertSessionHasNoErrors()->assertRedirect();

        $grupo->refresh();
        $this->assertSame(6, $grupo->variantes()->count());
        $this->assertTrue((bool) $this->variante($grupo, '512GB', 'Azul')->controla_serie);
        $this->assertSame(6, $grupo->variantes()->where('controla_serie', true)->count());

        // también cuando el formulario no manda el campo (pantalla abierta desde antes): nace como las demás
        $atributos[0]['valores'][] = '1TB';
        $this->actualizar($grupo, ['atributos' => $atributos])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue((bool) $this->variante($grupo->refresh(), '1TB', 'Negro')->controla_serie);
        $this->assertSame(8, $grupo->variantes()->where('controla_serie', true)->count());
    }

    public function test_con_serie_el_modelo_no_admite_stock_inicial(): void
    {
        $this->actingAs($this->admin)->post('/productos/modelos', $this->datos([
            'controla_serie' => true,
            'costo' => 500,
            'variantes' => [['valores' => ['128GB', 'Negro'], 'stock_inicial' => 3]],
        ]))->assertSessionHasErrors('variantes.0.stock_inicial');
        $this->assertSame(0, ProductoGrupo::where('empresa_id', $this->empresa->id)->count());

        // tampoco al agregar una variante a un modelo que ya controla serie
        $grupo = $this->crearModelo(['controla_serie' => true]);
        $this->actualizar($grupo, [
            'controla_serie' => true,
            'costo' => 500,
            'atributos' => [
                ['nombre' => 'Capacidad', 'valores' => ['128GB', '256GB', '512GB']],
                ['nombre' => 'Color', 'valores' => ['Negro', 'Azul']],
            ],
            'variantes' => [['valores' => ['512GB', 'Negro'], 'stock_inicial' => 2]],
        ])->assertSessionHasErrors('variantes.0.stock_inicial');
        $this->assertSame(4, $grupo->variantes()->count());
    }

    public function test_desmarcar_con_series_disponibles_se_rechaza_y_dice_cual_variante(): void
    {
        $grupo = $this->crearModelo(['controla_serie' => true]);
        $variante = $this->variante($grupo, '256GB', 'Azul');
        $this->comprar($variante, ['356000000000029', '356000000000037'])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $respuesta = $this->actualizar($grupo, ['controla_serie' => false]);
        $respuesta->assertSessionHasErrors('controla_serie');
        $mensaje = session('errors')->first('controla_serie');
        $this->assertStringContainsString('Galaxy A15 256GB Azul', $mensaje);
        $this->assertStringContainsString('2 unidades con serie en stock', $mensaje);
        // no se desmarcó ninguna
        $this->assertSame(4, $grupo->variantes()->where('controla_serie', true)->count());

        // sin series vivas sí se puede: se dan de baja y se desmarca
        ProductoSerie::where('producto_id', $variante->id)->update(['estado' => 'baja']);
        $this->actualizar($grupo, ['controla_serie' => false])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0, $grupo->variantes()->where('controla_serie', true)->count());
    }

    public function test_no_se_activa_con_una_transferencia_en_camino(): void
    {
        $grupo = $this->crearModelo([
            'costo' => 500,
            'variantes' => [['valores' => ['128GB', 'Negro'], 'stock_inicial' => 3]],
        ]);
        $variante = $this->variante($grupo, '128GB', 'Negro');
        $segunda = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Segunda', 'activo' => true]);
        $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_origen_id' => $this->sucursal->id,
            'sucursal_destino_id' => $segunda->id,
            'items' => [['producto_id' => $variante->id, 'cantidad' => 1]],
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->actualizar($grupo, ['controla_serie' => true])->assertSessionHasErrors('controla_serie');
        $mensaje = session('errors')->first('controla_serie');
        $this->assertStringContainsString('Galaxy A15 128GB Negro', $mensaje);
        $this->assertStringContainsString('transferencia en camino', $mensaje);
        $this->assertSame(0, $grupo->variantes()->where('controla_serie', true)->count());
    }

    public function test_con_el_modulo_de_series_apagado_el_campo_se_ignora(): void
    {
        $this->empresa->update(['series_activo' => false]);
        $this->admin->unsetRelation('empresa');

        // al crear: aunque llegue el campo, ninguna variante queda con serie
        $grupo = $this->crearModelo(['controla_serie' => true]);
        $this->assertSame(0, $grupo->variantes()->where('controla_serie', true)->count());

        // al editar un modelo que ya tenía serie: mandar false no lo desmarca
        $grupo->variantes()->update(['controla_serie' => true]);
        $this->actualizar($grupo, ['controla_serie' => false])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(4, $grupo->variantes()->where('controla_serie', true)->count());
    }
}
