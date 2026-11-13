<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Auditoria;
use App\Models\CapaCosto;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\ProductoSerie;
use App\Models\Stock;
use App\Services\InventarioService;
use App\Services\SerieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    public function __construct(private readonly InventarioService $inventario, private readonly SerieService $series) {}

    public function index(Request $request): Response
    {
        $empresaId = $request->user()->empresa_id;
        $contexto = $this->contextoSucursal($request);
        $sucursalId = $this->sucursalDeTrabajo($request);
        // la sucursal elegida o, viendo "todas", cada una de las suyas: el stock sale por columna y sumado
        $ids = $sucursalId ? [$sucursalId] : $contexto->accesibles->pluck('id')->all();
        $marcas = implode(',', array_fill(0, max(1, count($ids)), '?'));
        $filtros = $request->only(['buscar', 'bajos', 'vencen']);

        $capasPorVencer = fn ($q) => $q
            ->whereIn('sucursal_id', $ids)
            ->where('cantidad_restante', '>', 0)
            ->whereHas('lote', fn ($l) => $l
                ->whereNotNull('fecha_vencimiento')
                ->where('fecha_vencimiento', '<=', now()->addDays(30)->toDateString()));

        $condicionBajo = "COALESCE((select sum(s.cantidad) from stock s where s.producto_id = productos.id and s.sucursal_id in ({$marcas})), 0) <= 0"
            ." or (stock_minimo > 0 and COALESCE((select sum(s.cantidad) from stock s where s.producto_id = productos.id and s.sucursal_id in ({$marcas})), 0) <= stock_minimo)";

        $productos = Producto::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->where('controla_stock', true)
            ->when($filtros['vencen'] ?? null, fn ($q) => $q->whereHas('capasCosto', $capasPorVencer))
            ->with(['categoria:id,nombre', 'unidadBase:codigo,nombre'])
            ->withSum(['stock as stock' => fn ($q) => $q->whereIn('sucursal_id', $ids)], 'cantidad')
            ->addSelect(['valor_inventario' => CapaCosto::query()
                ->selectRaw('COALESCE(SUM(cantidad_restante * costo_unitario), 0)')
                ->whereColumn('producto_id', 'productos.id')
                ->whereIn('sucursal_id', $ids)
                ->where('cantidad_restante', '>', 0),
            ])
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->where(fn ($w) => $w
                ->where('nombre', 'ilike', "%{$buscar}%")
                ->orWhere('codigo_interno', 'ilike', "%{$buscar}%")))
            ->when($filtros['bajos'] ?? null, fn ($q) => $q->whereRaw("({$condicionBajo})", [...$ids, ...$ids]))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        // viendo varias sucursales: cuánto hay en cada una (columna por sucursal)
        if (count($ids) > 1) {
            $porSucursal = Stock::query()
                ->whereIn('producto_id', $productos->getCollection()->pluck('id'))
                ->whereIn('sucursal_id', $ids)
                ->get(['producto_id', 'sucursal_id', 'cantidad'])
                ->groupBy('producto_id');
            $productos->through(function ($producto) use ($porSucursal) {
                $producto->por_sucursal = ($porSucursal[$producto->id] ?? collect())
                    ->mapWithKeys(fn ($s) => [$s->sucursal_id => (float) $s->cantidad]);

                return $producto;
            });
        }

        // productos con serie / IMEI: cuántas unidades tienen en stock SIN serie registrada (por regularizar)
        $conSerie = $productos->getCollection()->where('controla_serie', true)->pluck('id');
        if ($conSerie->isNotEmpty()) {
            $stocks = Stock::query()->whereIn('producto_id', $conSerie)->whereIn('sucursal_id', $ids)->get(['producto_id', 'sucursal_id', 'cantidad']);
            $disponibles = ProductoSerie::query()
                ->whereIn('producto_id', $conSerie)->whereIn('sucursal_id', $ids)->where('estado', 'disponible')
                ->selectRaw('producto_id, sucursal_id, count(*) as total')
                ->groupBy('producto_id', 'sucursal_id')
                ->toBase()->get()
                ->mapWithKeys(fn ($f) => ["{$f->producto_id}|{$f->sucursal_id}" => (int) $f->total]);
            $productos->through(function ($producto) use ($stocks, $disponibles) {
                $producto->sin_serie = $producto->controla_serie
                    ? (int) $stocks->where('producto_id', $producto->id)->sum(fn ($s) => max(0, (int) floor((float) $s->cantidad + 0.0005) - ($disponibles["{$producto->id}|{$s->sucursal_id}"] ?? 0)))
                    : 0;

                return $producto;
            });
        }

        $resumen = [
            'valor_total' => (float) CapaCosto::query()
                ->where('empresa_id', $empresaId)
                ->whereIn('sucursal_id', $ids)
                ->where('cantidad_restante', '>', 0)
                ->selectRaw('COALESCE(SUM(cantidad_restante * costo_unitario), 0) as valor')
                ->value('valor'),
            'bajos' => Producto::query()
                ->where('empresa_id', $empresaId)
                ->where('activo', true)
                ->where('controla_stock', true)
                ->whereRaw("({$condicionBajo})", [...$ids, ...$ids])
                ->count(),
            'productos' => Producto::query()
                ->where('empresa_id', $empresaId)
                ->where('activo', true)
                ->where('controla_stock', true)
                ->count(),
            'por_vencer' => Producto::query()
                ->where('empresa_id', $empresaId)
                ->where('activo', true)
                ->where('controla_stock', true)
                ->whereHas('capasCosto', $capasPorVencer)
                ->count(),
        ];

        // el valor del inventario al costo solo lo ven quienes manejan costos
        if (! $request->user()->can('stock.costos')) {
            $resumen['valor_total'] = null;
            $productos->through(function ($producto) {
                $producto->valor_inventario = null;

                return $producto;
            });
        }

        return Inertia::render('Stock/Index', [
            'productos' => $productos,
            'filtros' => $filtros,
            'resumen' => $resumen,
            'sucursal' => $contexto->nombreDe($sucursalId),
            // [{ id, nombre }] cuando se ven varias: una columna por sucursal y selector en ajustes y kardex
            'sucursales' => count($ids) > 1 ? $contexto->accesibles->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->nombre])->values() : [],
        ]);
    }

    public function kardex(Request $request, Producto $producto): JsonResponse
    {
        abort_unless($producto->empresa_id === $request->user()->empresa_id, 403);

        // ?sucursal_id= viendo "todas"; si no, la del selector
        $sucursalId = $this->sucursalElegida($request, 'sucursal_id');

        $lotes = Lote::query()
            ->where('producto_id', $producto->id)
            ->where('sucursal_id', $sucursalId)
            ->withSum('capasCosto as restante', 'cantidad_restante')
            ->orderByRaw('fecha_vencimiento asc nulls last')
            ->get()
            ->filter(fn ($lote) => (float) ($lote->restante ?? 0) > 0)
            ->values()
            ->map(fn ($lote) => [
                'id' => $lote->id,
                'numero_lote' => $lote->numero_lote,
                'fecha_vencimiento' => $lote->fecha_vencimiento?->toDateString(),
                'restante' => (float) $lote->restante,
            ]);

        // unidades con serie / IMEI disponibles; ?buscar_serie= filtra en el servidor (para cuando son más de las que se listan)
        $buscarSerie = $request->query('buscar_serie');
        $seriesDisponibles = $producto->controla_serie && $sucursalId
            ? $this->series->disponibles($producto->id, $sucursalId, is_string($buscarSerie) ? mb_substr($buscarSerie, 0, SerieService::MAX_LARGO) : null)
            : collect();

        return response()->json([
            'lotes' => $lotes,
            // unidades con serie / IMEI disponibles en la sucursal (vacío si el producto no controla serie)
            'series' => $seriesDisponibles->map(fn ($s) => ['id' => $s->id, 'numero_serie' => $s->numero_serie, 'serie_alterna' => $s->serie_alterna]),
            // hay más unidades que las listadas: la pantalla busca en el servidor con ?buscar_serie=
            'series_truncadas' => $seriesDisponibles->count() >= SerieService::LIMITE_LISTA,
            // unidades en stock sin serie registrada (por regularizar)
            'sin_serie' => $sucursalId ? $this->series->sinSerie($producto, $sucursalId) : 0,
            'movimientos' => MovimientoInventario::query()
                ->where('producto_id', $producto->id)
                ->where('sucursal_id', $sucursalId)
                ->with('usuario:id,nombre_completo')
                ->latest('creado_en')
                ->limit(30)
                ->get(['id', 'tipo', 'cantidad', 'costo_unitario', 'usuario_id', 'observacion', 'creado_en'])
                ->each(function ($m) use ($request) {
                    if (! $request->user()->can('stock.costos')) {
                        $m->costo_unitario = null;
                    }
                }),
        ]);
    }

    /**
     * Regularización: registra la serie / IMEI de unidades que ya están en el stock sin número (al activar la serie
     * en un producto con stock, o si volvió una venta anterior). No mueve stock ni capas de costo.
     */
    public function regularizarSeries(Request $request, Producto $producto): RedirectResponse
    {
        $usuario = $request->user();

        abort_unless($producto->empresa_id === $usuario->empresa_id, 403);

        $datos = $request->validate([
            'series' => ['required', 'array', 'min:1', 'max:1000'],
            'series.*' => ['required', 'string', 'max:'.SerieService::MAX_LARGO, SerieService::regla()],
            'series_alternas' => ['nullable', 'array', 'max:1000'],
            'series_alternas.*' => ['nullable', 'string', 'max:'.SerieService::MAX_LARGO, SerieService::regla(opcional: true)],
        ], [
            'series.required' => 'Registra al menos una serie.',
            'series.*.required' => 'Hay una serie vacía.',
            'series.*.max' => 'Cada serie no debe pasar de '.SerieService::MAX_LARGO.' caracteres.',
            'series_alternas.*.max' => 'Cada serie no debe pasar de '.SerieService::MAX_LARGO.' caracteres.',
        ]);

        $sucursalId = $this->sucursalElegida($request, 'sucursal_id');
        if (! $sucursalId) {
            return back()->with('error', 'Elige la sucursal.');
        }

        $series = SerieService::unir($datos['series'], $datos['series_alternas'] ?? []);

        try {
            DB::transaction(fn () => $this->series->regularizar($producto, $sucursalId, $series, $usuario));
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudieron registrar las series. Intenta de nuevo.');
        }

        Auditoria::registrar($usuario, 'stock.series_regularizadas', 'producto', $producto->id, [
            'producto' => $producto->nombre,
            'series' => array_column($series, 'numero'),
        ]);

        return back()->with('success', count($series) === 1 ? 'Serie registrada. El stock no cambió.' : count($series).' series registradas. El stock no cambió.');
    }

    public function ajustar(Request $request, Producto $producto): RedirectResponse
    {
        $usuario = $request->user();

        abort_unless($producto->empresa_id === $usuario->empresa_id, 403);

        if (! $producto->controla_stock) {
            return back()->with('error', 'Este producto no controla stock.');
        }

        $datos = $request->validate([
            'direccion' => ['required', 'in:entrada,salida'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'costo_unitario' => ['required_if:direccion,entrada', 'nullable', 'numeric', 'min:0'],
            'numero_lote' => ['nullable', 'string', 'max:50'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'motivo' => ['nullable', 'string', 'max:250'],
            // productos que controlan serie: entrada = una serie por unidad; salida = las unidades que se dan de baja
            'series' => ['nullable', 'array', 'max:1000'],
            'series.*' => ['required', 'string', 'max:'.SerieService::MAX_LARGO, SerieService::regla()],
            'series_alternas' => ['nullable', 'array', 'max:1000'],
            'series_alternas.*' => ['nullable', 'string', 'max:'.SerieService::MAX_LARGO, SerieService::regla(opcional: true)],
            'serie_ids' => ['nullable', 'array', 'max:1000'],
            'serie_ids.*' => ['required', 'uuid'],
        ], [
            'series.*.required' => 'Hay una serie vacía.',
            'series.*.max' => 'Cada serie no debe pasar de '.SerieService::MAX_LARGO.' caracteres.',
            'series_alternas.*.max' => 'Cada serie no debe pasar de '.SerieService::MAX_LARGO.' caracteres.',
            'motivo.max' => 'El motivo no debe pasar de 250 caracteres.',
            'cantidad.required' => 'Ingresa la cantidad.',
            'cantidad.gt' => 'La cantidad debe ser mayor a 0.',
            'costo_unitario.required_if' => 'Indica el costo unitario del ingreso.',
        ]);

        if ($datos['direccion'] === 'entrada' && $producto->controla_lote && blank($datos['numero_lote'] ?? null)) {
            return back()->with('error', 'Este producto controla lotes: indica el número de lote.');
        }

        if (! $producto->permite_fraccion && fmod((float) $datos['cantidad'], 1) != 0) {
            return back()->with('error', 'Este producto no permite cantidades fraccionadas.');
        }

        // la sucursal elegida en el modal (viendo "todas") o la del selector
        $sucursalId = $this->sucursalElegida($request, 'sucursal_id');
        $cantidad = round((float) $datos['cantidad'], 3);
        $motivo = trim((string) ($datos['motivo'] ?? '')) ?: null;

        // controla serie / IMEI: al entrar se registra cada unidad; al salir se indica cuáles se dan de baja
        $series = [];
        $serieIds = [];
        // salida: unidades que se retiran SIN serie (solo las que están en stock sin número registrado)
        $sinNumero = 0;
        if ($producto->controla_serie) {
            $series = SerieService::unir($datos['series'] ?? [], $datos['series_alternas'] ?? []);
            $serieIds = array_values(array_unique(array_map('strtolower', $datos['serie_ids'] ?? [])));

            if ($datos['direccion'] === 'entrada') {
                if (count($series) !== (int) $cantidad) {
                    return back()->with('error', sprintf('Este producto controla serie / IMEI: registra %d %s (hay %d).', (int) $cantidad, (int) $cantidad === 1 ? 'serie' : 'series, una por unidad', count($series)));
                }
            } else {
                $sinNumero = (int) $cantidad - count($serieIds);
                $sinSerie = $sinNumero > 0 ? $this->series->sinSerie($producto, $sucursalId) : 0;

                if ($sinNumero < 0 || $sinNumero > $sinSerie) {
                    return back()->with('error', sprintf(
                        'Este producto controla serie / IMEI: elige %s (elegiste %d).%s',
                        (int) $cantidad === 1 ? 'la unidad que sale' : 'las '.(int) $cantidad.' unidades que salen',
                        count($serieIds),
                        $sinSerie > 0 ? sprintf(' Sin serie registrada solo hay %d.', $sinSerie) : '',
                    ));
                }
            }
        }

        if ($datos['direccion'] === 'salida') {
            $disponible = $this->inventario->stockDisponible($producto->id, $sucursalId);

            if ($cantidad > $disponible) {
                return back()->with('error', "No puedes retirar más de lo disponible ({$disponible}).");
            }
        }

        try {
            DB::transaction(function () use ($datos, $producto, $usuario, $sucursalId, $cantidad, $motivo, $series, $serieIds, $sinNumero) {
                if ($datos['direccion'] === 'entrada') {
                    $loteId = null;
                    if ($producto->controla_lote && filled($datos['numero_lote'] ?? null)) {
                        $loteId = $this->inventario->obtenerLote(
                            $producto,
                            $sucursalId,
                            $datos['numero_lote'],
                            $datos['fecha_vencimiento'] ?? null,
                        )->id;
                    }

                    // nueva capa de costo para mantener el FIFO consistente
                    $costo = (float) $datos['costo_unitario'];
                    $this->inventario->ingresarCapa($producto, $sucursalId, $cantidad, $costo, loteId: $loteId);
                    $this->inventario->incrementarStock($producto->empresa_id, $producto->id, $sucursalId, $cantidad);
                    $this->inventario->registrarMovimiento(
                        $producto->empresa_id, $sucursalId, $producto->id, 'ajuste', $cantidad, $costo, usuarioId: $usuario->id, loteId: $loteId, observacion: $motivo,
                    );

                    if ($series !== []) {
                        $this->series->ingresar($producto, $sucursalId, $series, $costo, 'ajuste_entrada', null, null, $usuario);
                    }

                    return;
                }

                // unidades sin serie: con la fila de stock bloqueada se comprueba que de verdad sigan ahí
                if ($sinNumero > 0 && $sinNumero > $this->series->sinSerie($producto, $sucursalId, bloquear: true)) {
                    throw new ErrorDeNegocio('Las unidades sin serie cambiaron mientras registrabas la salida. Vuelve a abrir el ajuste.');
                }

                // salida (merma): consume capas FIFO igual que una venta
                $consumo = $this->inventario->consumirFifo($producto->id, $sucursalId, $cantidad);
                $this->inventario->descontarStock($producto->id, $sucursalId, $cantidad);
                $this->inventario->registrarMovimiento(
                    $producto->empresa_id,
                    $sucursalId,
                    $producto->id,
                    'merma',
                    $cantidad,
                    $cantidad > 0 ? $consumo['costo_total'] / $cantidad : 0,
                    usuarioId: $usuario->id,
                    observacion: $motivo,
                );

                if ($serieIds !== []) {
                    $this->series->darDeBaja($serieIds, $producto, $sucursalId, $usuario);
                }
            });
        } catch (\Throwable $e) {
            // con series el aviso dice qué unidad falló (repetida, ya vendida...); el resto conserva el aviso de siempre
            if ($e instanceof ErrorDeNegocio && $producto->controla_serie) {
                return back()->with('error', $e->getMessage());
            }

            report($e);

            return back()->with('error', 'No se pudo registrar el ajuste. Intenta de nuevo.');
        }

        // un ajuste mueve mercaderia sin documento: queda quien, cuanto y a que costo
        Auditoria::registrar($usuario, 'stock.ajustado', 'producto', $producto->id, [
            'producto' => $producto->nombre,
            'direccion' => $datos['direccion'],
            'cantidad' => $cantidad,
            'costo_unitario' => $datos['direccion'] === 'entrada' ? (float) $datos['costo_unitario'] : null,
            'lote' => $datos['numero_lote'] ?? null,
            'motivo' => $motivo,
            ...($producto->controla_serie ? ['series' => $datos['direccion'] === 'entrada' ? array_column($series, 'numero') : count($serieIds).' unidad(es) de baja'.($sinNumero > 0 ? " y {$sinNumero} sin serie" : '')] : []),
        ]);

        return back()->with('success', $datos['direccion'] === 'entrada'
            ? 'Entrada registrada. Stock actualizado.'
            : 'Salida registrada. Stock actualizado.');
    }
}
