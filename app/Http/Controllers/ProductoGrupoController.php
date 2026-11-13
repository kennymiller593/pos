<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Http\Requests\ProductoGrupoRequest;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoGrupo;
use App\Models\TipoAfectacionIgv;
use App\Models\UnidadMedida;
use App\Services\VarianteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Modelos con variantes (talla, color...): cada combinación es un producto normal del catálogo. */
class ProductoGrupoController extends Controller
{
    public function __construct(private readonly VarianteService $variantes) {}

    public function crear(Request $request): Response
    {
        return Inertia::render('Productos/FormularioVariantes', [
            'modelo' => null,
            'catalogos' => $this->catalogos($request),
        ]);
    }

    public function store(ProductoGrupoRequest $request): RedirectResponse
    {
        try {
            $grupo = $this->variantes->guardarGrupo($request->user(), $this->datos($request), null, $this->sucursalElegida($request, 'sucursal_id'));
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        $resumen = $this->variantes->resumen;
        $mensaje = "Modelo \"{$grupo->nombre}\" creado con {$this->contar($resumen['creadas'], 'variante')}";
        if ($resumen['stock'] > 0) {
            $mensaje .= ' y '.$this->contar((int) $resumen['stock'], 'unidad', 'unidades').' de stock inicial';
        }

        return redirect("/productos?grupo_id={$grupo->id}")->with('success', $mensaje.'.');
    }

    public function editar(Request $request, ProductoGrupo $grupo): Response
    {
        $this->autorizar($request, $grupo);

        $atributos = $grupo->atributos ?? [];
        $sucursales = $this->sucursalesConsulta($request);
        $variantes = $grupo->variantes()
            ->with('presentaciones')
            ->withSum(['stock as stock_total' => fn ($q) => $q->when($sucursales, fn ($w, $ids) => $w->whereIn('sucursal_id', $ids))], 'cantidad')
            ->get();
        $principal = fn (Producto $p) => $p->presentaciones->firstWhere('es_default', true) ?? $p->presentaciones->first();

        // precio base: el más repetido entre las variantes (es el que se propone para las que se agreguen)
        $precioBase = $variantes
            ->map(fn (Producto $p) => $principal($p) ? number_format((float) $principal($p)->precio_venta, 2, '.', '') : null)
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        return Inertia::render('Productos/FormularioVariantes', [
            'modelo' => [
                'id' => $grupo->id,
                'nombre' => $grupo->nombre,
                'categoria_id' => $grupo->categoria_id,
                'marca_id' => $grupo->marca_id,
                'atributos' => $atributos,
                // combinaciones que se decidió no tener: [["L", "Azul"], ...]
                'omitidas' => $grupo->omitidas ?? [],
                // unidad y afectación viven en cada producto: se toman de sus variantes
                'unidad_base_codigo' => $variantes->first()?->unidad_base_codigo,
                'tipo_afectacion_codigo' => $variantes->first() ? trim((string) $variantes->first()->tipo_afectacion_codigo) : null,
                'precio_base' => $precioBase !== null ? (float) $precioBase : null,
                // serie / IMEI a nivel de modelo: todas sus variantes a la venta la controlan
                'controla_serie' => $grupo->controlaSerie($variantes),
                'variantes' => $variantes->map(fn (Producto $p) => [
                    'id' => $p->id,
                    'codigo_interno' => $p->codigo_interno,
                    'nombre' => $p->nombre,
                    'valores' => VarianteService::valoresDe($p, $atributos),
                    'activo' => (bool) $p->activo,
                    'precio_venta' => $principal($p) ? (float) $principal($p)->precio_venta : null,
                    'codigo_barras' => $principal($p)?->codigo_barras,
                    'stock' => (float) ($p->stock_total ?? 0),
                ])->values(),
            ],
            'catalogos' => $this->catalogos($request),
        ]);
    }

    public function update(ProductoGrupoRequest $request, ProductoGrupo $grupo): RedirectResponse
    {
        $this->autorizar($request, $grupo);

        try {
            $this->variantes->guardarGrupo($request->user(), $this->datos($request), $grupo, $this->sucursalElegida($request, 'sucursal_id'));
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        $resumen = $this->variantes->resumen;
        $partes = array_filter([
            $resumen['creadas'] ? $this->contar($resumen['creadas'], 'variante nueva', 'variantes nuevas') : null,
            $resumen['desactivadas'] ? $this->contar($resumen['desactivadas'], 'desactivada', 'desactivadas').' (con movimientos)' : null,
            $resumen['eliminadas'] ? $this->contar($resumen['eliminadas'], 'eliminada', 'eliminadas') : null,
        ]);

        return redirect("/productos?grupo_id={$grupo->id}")
            ->with('success', "Modelo \"{$grupo->nombre}\" actualizado".($partes ? ': '.implode(', ', $partes) : '').'.');
    }

    public function destroy(Request $request, ProductoGrupo $grupo): RedirectResponse
    {
        $this->autorizar($request, $grupo);

        try {
            $eliminadas = $this->variantes->eliminarGrupo($request->user(), $grupo);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/productos')->with('success', "Modelo \"{$grupo->nombre}\" eliminado con {$this->contar($eliminadas, 'variante')}.");
    }

    /** Lo validado, con la serie / IMEI como la entiende el request: sin el módulo activo va null y no se toca. */
    private function datos(ProductoGrupoRequest $request): array
    {
        return [...$request->validated(), 'controla_serie' => $request->controlaSerie()];
    }

    /** Un usuario jamás ve ni toca modelos de otra empresa. */
    private function autorizar(Request $request, ProductoGrupo $grupo): void
    {
        abort_unless($grupo->empresa_id === $request->user()->empresa_id, 403);
    }

    private function catalogos(Request $request): array
    {
        $empresaId = $request->user()->empresa_id;

        return [
            'categorias' => Categoria::where('empresa_id', $empresaId)->orderBy('nombre')->get(['id', 'nombre']),
            'marcas' => Marca::where('empresa_id', $empresaId)->orderBy('nombre')->get(['id', 'nombre']),
            'unidades' => UnidadMedida::where('activo', true)->orderBy('descripcion_sunat')->get(['codigo', 'nombre', 'descripcion_sunat', 'permite_decimales']),
            'tiposAfectacion' => TipoAfectacionIgv::orderBy('codigo')->get(['codigo', 'nombre']),
        ];
    }

    /** 1 → "1 variante", 3 → "3 variantes" */
    private function contar(int $n, string $singular, ?string $plural = null): string
    {
        return $n.' '.($n === 1 ? $singular : ($plural ?? $singular.'s'));
    }
}
