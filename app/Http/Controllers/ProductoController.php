<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Http\Requests\ProductoRequest;
use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\TipoAfectacionIgv;
use App\Models\UnidadMedida;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProductoController extends Controller
{
    public function index(Request $request): Response
    {
        $empresaId = $request->user()->empresa_id;
        $filtros = $request->only(['buscar', 'categoria_id', 'estado']);

        $productos = Producto::query()
            ->where('empresa_id', $empresaId)
            ->with([
                'categoria:id,nombre',
                'marca:id,nombre',
                'unidadBase:codigo,nombre',
                'presentaciones' => fn ($q) => $q->orderByDesc('es_default')->orderBy('nombre'),
            ])
            ->withSum('stock as stock_total', 'cantidad')
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->where(fn ($w) => $w
                ->where('nombre', 'ilike', "%{$buscar}%")
                ->orWhere('codigo_interno', 'ilike', "%{$buscar}%")))
            ->when($filtros['categoria_id'] ?? null, fn ($q, $categoria) => $q->where('categoria_id', $categoria))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('activo', $estado === 'activo'))
            ->orderBy('nombre')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Productos/Index', [
            'productos' => $productos,
            'filtros' => $filtros,
            'catalogos' => [
                'categorias' => Categoria::where('empresa_id', $empresaId)->orderBy('nombre')->get(['id', 'nombre']),
                'marcas' => Marca::where('empresa_id', $empresaId)->orderBy('nombre')->get(['id', 'nombre']),
                'unidades' => UnidadMedida::orderBy('nombre')->get(['codigo', 'nombre']),
                // vista previa del codigo que tomara el proximo producto (se confirma al guardar)
                'siguienteCodigo' => Producto::siguienteCodigo($empresaId),
                'tiposAfectacion' => TipoAfectacionIgv::orderBy('codigo')->get(['codigo', 'nombre']),
            ],
        ]);
    }

    public function store(ProductoRequest $request): RedirectResponse
    {
        $empresaId = $request->user()->empresa_id;

        // el codigo se calcula bajo lock: dos altas simultaneas no pueden tomar el mismo numero
        $producto = Cache::lock("producto-codigo:{$empresaId}", 10)->block(5, function () use ($request, $empresaId) {
            $producto = new Producto(['empresa_id' => $empresaId]);
            $producto->codigo_interno = Producto::siguienteCodigo($empresaId);
            $this->guardar($request, $producto);

            return $producto;
        });

        return back()->with('success', "Producto {$producto->codigo_interno} creado.");
    }

    public function update(ProductoRequest $request, Producto $producto): RedirectResponse
    {
        abort_unless($producto->empresa_id === $request->user()->empresa_id, 403);

        try {
            $this->guardar($request, $producto);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Producto actualizado.');
    }

    public function destroy(Request $request, Producto $producto): RedirectResponse
    {
        abort_unless($producto->empresa_id === $request->user()->empresa_id, 403);

        $producto->delete();

        return back()->with('success', 'Producto eliminado.');
    }

    private function guardar(ProductoRequest $request, Producto $producto): void
    {
        $datos = $request->safe()->except(['presentaciones', 'imagen', 'imagen_eliminar']);

        // al crear, el codigo lo asigna store() (P0001...): se ignora lo que envie el formulario
        if (! $producto->exists || blank($datos['codigo_interno'] ?? null)) {
            unset($datos['codigo_interno']);
        }
        $datos['stock_minimo'] = $datos['stock_minimo'] ?? 0;

        if ($request->hasFile('imagen')) {
            $ruta = $request->file('imagen')->store('productos', 'public');
            $this->eliminarImagenLocal($producto->imagen_url);
            $datos['imagen_url'] = Storage::url($ruta);
        } elseif ($request->boolean('imagen_eliminar')) {
            $this->eliminarImagenLocal($producto->imagen_url);
            $datos['imagen_url'] = null;
        }
        $presentaciones = collect($request->validated('presentaciones'));

        // garantiza exactamente una presentacion por defecto
        if ($presentaciones->where('es_default', true)->count() !== 1) {
            $presentaciones = $presentaciones->values()->map(
                fn ($p, $i) => [...$p, 'es_default' => $i === 0]
            );
        }

        $preciosAntes = $producto->exists
            ? $producto->presentaciones()->pluck('precio_venta', 'id')
            : collect();

        // un almacenero mantiene el catalogo, pero los precios de lista los fija quien tiene el permiso
        if ($producto->exists && ! $request->user()->can('productos.precios')) {
            $mayoristasAntes = $producto->presentaciones()->pluck('precio_mayorista', 'id');
            $cambiaPrecios = $presentaciones->contains(fn ($p) => ($p['id'] ?? null)
                && $preciosAntes->has($p['id'])
                && (abs((float) $preciosAntes[$p['id']] - (float) $p['precio_venta']) >= 0.005
                    || abs((float) ($mayoristasAntes[$p['id']] ?? 0) - (float) ($p['precio_mayorista'] ?? 0)) >= 0.005));

            if ($cambiaPrecios) {
                throw new ErrorDeNegocio('Tu rol no puede cambiar precios de venta. Pide a un administrador que lo haga.');
            }
        }

        DB::transaction(function () use ($producto, $datos, $presentaciones) {
            $producto->fill($datos)->save();

            $idsEnviados = $presentaciones->pluck('id')->filter();
            $producto->presentaciones()->whereNotIn('id', $idsEnviados)->delete();

            foreach ($presentaciones as $datosPresentacion) {
                $producto->presentaciones()->updateOrCreate(
                    ['id' => $datosPresentacion['id'] ?? null],
                    [
                        ...collect($datosPresentacion)->except('id'),
                        'empresa_id' => $producto->empresa_id,
                        'activo' => true,
                    ]
                );
            }
        });

        // constancia de cambios de precio sobre presentaciones ya existentes
        $cambios = $presentaciones
            ->filter(fn ($p) => ($p['id'] ?? null)
                && $preciosAntes->has($p['id'])
                && abs((float) $preciosAntes[$p['id']] - (float) $p['precio_venta']) >= 0.005)
            ->map(fn ($p) => [
                'presentacion' => $p['nombre'],
                'de' => (float) $preciosAntes[$p['id']],
                'a' => (float) $p['precio_venta'],
            ])
            ->values();

        if ($cambios->isNotEmpty()) {
            Auditoria::registrar($request->user(), 'producto.precio_actualizado', 'producto', $producto->id, [
                'producto' => $producto->nombre,
                'cambios' => $cambios->all(),
            ]);
        }
    }

    /** Borra el archivo de imagen solo si fue subido a nuestro almacenamiento. */
    private function eliminarImagenLocal(?string $url): void
    {
        if ($url && str_starts_with($url, '/storage/')) {
            Storage::disk('public')->delete(substr($url, strlen('/storage/')));
        }
    }
}
