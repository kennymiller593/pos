<?php

namespace App\Services;

use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\ComprobanteDetalle;
use App\Models\CuentaPorCobrar;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\Pago;
use App\Models\PagoProveedor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reportes de gestión para el dueño: qué se vende, quién vende, cuándo, cuánto se gana,
 * quién compra, quién debe y por dónde entra el dinero.
 *
 * Todos devuelven la misma forma que usa la pantalla y las exportaciones:
 * titulo, columnas, filas, resumen y, opcionalmente, contexto, periodo, nota,
 * numericas (columnas alineadas a la derecha) y grafico.
 *
 * Las ventas son siempre netas: las notas de crédito restan y los anulados no cuentan.
 */
class ReportesNegocioService
{
    private const SIGNO = "(CASE WHEN comprobantes.tipo_comprobante_codigo = '07' THEN -1 ELSE 1 END)";

    private const COSTO = 'comprobante_detalles.costo_unitario * comprobante_detalles.cantidad';

    private const ES_VENTA = "comprobantes.tipo_comprobante_codigo <> '07'";

    private const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    private const MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    /** Tramos de antigüedad de una deuda, en días desde la venta: etiqueta => tope (null = sin tope). */
    private const TRAMOS = ['0 a 30 días' => 30, '31 a 60 días' => 60, '61 a 90 días' => 90, 'Más de 90 días' => null];

    private const TOPE_GRAFICO = 10;

    // ---------------------------------------------------------------
    // Ventas
    // ---------------------------------------------------------------

    public function porProducto(string $empresaId, ?string $sucursalId, string $desde, string $hasta): array
    {
        $signo = self::SIGNO;

        $filas = $this->detalles($empresaId, $sucursalId, $desde, $hasta)
            ->join('productos', 'productos.id', '=', 'comprobante_detalles.producto_id')
            ->leftJoin('categorias', 'categorias.id', '=', 'productos.categoria_id')
            // la cantidad se lleva a la unidad base: una caja x12 cuenta como 12 unidades
            ->leftJoin('producto_presentaciones', 'producto_presentaciones.id', '=', 'comprobante_detalles.presentacion_id')
            ->groupBy('productos.id', 'productos.nombre', 'productos.codigo_interno', 'categorias.nombre')
            ->selectRaw("
                productos.nombre,
                productos.codigo_interno as codigo,
                categorias.nombre as categoria,
                SUM({$signo} * comprobante_detalles.cantidad * COALESCE(producto_presentaciones.factor_conversion, 1)) as cantidad,
                COUNT(DISTINCT comprobantes.id) FILTER (WHERE ".self::ES_VENTA.") as ventas,
                SUM({$signo} * comprobante_detalles.total) as venta
            ")
            ->orderByDesc('venta')
            ->orderBy('productos.nombre')
            ->get();

        $total = (float) $filas->sum('venta');
        $mejor = $filas->first();

        return [
            'titulo' => 'Ventas por producto',
            'columnas' => ['Producto', 'Código', 'Categoría', 'Cant. vendida', 'Nº de ventas', 'Venta', '% del total'],
            'numericas' => [3, 4, 5, 6],
            'filas' => $filas->map(fn ($f) => [
                $f->nombre,
                $f->codigo,
                $f->categoria ?? 'Sin categoría',
                $this->cantidad((float) $f->cantidad),
                (int) $f->ventas,
                $this->numero((float) $f->venta),
                $this->porcentaje((float) $f->venta, $total),
            ])->values(),
            'resumen' => [
                ['etiqueta' => 'Productos vendidos', 'valor' => (string) $filas->count()],
                ['etiqueta' => 'Venta total', 'valor' => $this->soles($total)],
                ['etiqueta' => 'El que más vende', 'valor' => $mejor?->nombre ?? '—'],
                ['etiqueta' => 'Su parte de la venta', 'valor' => $mejor ? $this->porcentaje((float) $mejor->venta, $total) : '—'],
            ],
            'grafico' => $this->grafico('Los que más venden', $filas->pluck('nombre'), $filas->pluck('venta'), horizontal: true),
        ];
    }

    public function porCategoria(string $empresaId, ?string $sucursalId, string $desde, string $hasta): array
    {
        $signo = self::SIGNO;
        $costo = self::COSTO;

        $filas = $this->detalles($empresaId, $sucursalId, $desde, $hasta)
            ->join('productos', 'productos.id', '=', 'comprobante_detalles.producto_id')
            ->leftJoin('categorias', 'categorias.id', '=', 'productos.categoria_id')
            ->groupBy('categorias.id', 'categorias.nombre')
            ->selectRaw("
                categorias.nombre as categoria,
                COUNT(DISTINCT productos.id) as productos,
                SUM({$signo} * comprobante_detalles.total) as venta,
                SUM({$signo} * {$costo}) as costo
            ")
            ->orderByDesc('venta')
            ->get();

        $total = (float) $filas->sum('venta');
        $costoTotal = (float) $filas->sum('costo');
        $mejor = $filas->first();
        $nombre = fn ($f) => $f->categoria ?? 'Sin categoría';

        return [
            'titulo' => 'Ventas por categoría',
            'columnas' => ['Categoría', 'Productos', 'Venta', 'Costo', 'Utilidad', 'Margen %', '% del total'],
            'numericas' => [1, 2, 3, 4, 5, 6],
            'filas' => $filas->map(fn ($f) => [
                $nombre($f),
                (int) $f->productos,
                $this->numero((float) $f->venta),
                $this->numero((float) $f->costo),
                $this->numero((float) $f->venta - (float) $f->costo),
                $this->porcentaje((float) $f->venta - (float) $f->costo, (float) $f->venta),
                $this->porcentaje((float) $f->venta, $total),
            ])->values(),
            'resumen' => [
                ['etiqueta' => 'Categorías con venta', 'valor' => (string) $filas->count()],
                ['etiqueta' => 'Venta total', 'valor' => $this->soles($total)],
                ['etiqueta' => 'Utilidad', 'valor' => $this->soles($total - $costoTotal)],
                ['etiqueta' => 'La que más vende', 'valor' => $mejor ? $nombre($mejor) : '—'],
            ],
            'grafico' => $this->grafico('Venta por categoría', $filas->map($nombre), $filas->pluck('venta'), horizontal: true),
        ];
    }

    public function porVendedor(string $empresaId, ?string $sucursalId, string $desde, string $hasta): array
    {
        $signo = self::SIGNO;

        $filas = $this->comprobantes($empresaId, $sucursalId, $desde, $hasta)
            ->join('usuarios', 'usuarios.id', '=', 'comprobantes.usuario_id')
            ->groupBy('usuarios.id', 'usuarios.nombre_completo')
            ->selectRaw('
                usuarios.id,
                usuarios.nombre_completo as nombre,
                COUNT(*) FILTER (WHERE '.self::ES_VENTA.") as ventas,
                COALESCE(SUM({$signo} * comprobantes.total), 0) as venta
            ")
            ->orderByDesc('venta')
            ->get();

        $utilidades = $this->detalles($empresaId, $sucursalId, $desde, $hasta)
            ->groupBy('comprobantes.usuario_id')
            ->selectRaw("comprobantes.usuario_id, SUM({$signo} * (comprobante_detalles.total - ".self::COSTO.')) as utilidad')
            ->pluck('utilidad', 'usuario_id');

        $total = (float) $filas->sum('venta');
        $ventas = (int) $filas->sum('ventas');
        $mejor = $filas->first();

        return [
            'titulo' => 'Ventas por vendedor',
            'columnas' => ['Vendedor', 'Nº de ventas', 'Venta', 'Ticket promedio', 'Utilidad', '% del total'],
            'numericas' => [1, 2, 3, 4, 5],
            'filas' => $filas->map(fn ($f) => [
                $f->nombre,
                (int) $f->ventas,
                $this->numero((float) $f->venta),
                $this->numero($f->ventas > 0 ? (float) $f->venta / $f->ventas : 0),
                $this->numero((float) ($utilidades[$f->id] ?? 0)),
                $this->porcentaje((float) $f->venta, $total),
            ])->values(),
            'resumen' => [
                ['etiqueta' => 'Vendedores', 'valor' => (string) $filas->count()],
                ['etiqueta' => 'Venta total', 'valor' => $this->soles($total)],
                ['etiqueta' => 'Ticket promedio', 'valor' => $this->soles($ventas > 0 ? $total / $ventas : 0)],
                ['etiqueta' => 'El que más vende', 'valor' => $mejor?->nombre ?? '—'],
            ],
            'grafico' => $this->grafico('Venta por vendedor', $filas->pluck('nombre'), $filas->pluck('venta'), horizontal: true),
        ];
    }

    public function porHora(string $empresaId, ?string $sucursalId, string $desde, string $hasta): array
    {
        $signo = self::SIGNO;

        $porHora = $this->comprobantes($empresaId, $sucursalId, $desde, $hasta)
            ->groupByRaw('EXTRACT(hour FROM comprobantes.hora_emision)')
            ->selectRaw('
                EXTRACT(hour FROM comprobantes.hora_emision)::int as hora,
                COUNT(*) FILTER (WHERE '.self::ES_VENTA.") as ventas,
                COALESCE(SUM({$signo} * comprobantes.total), 0) as venta
            ")
            ->get()
            ->keyBy('hora');

        // de la primera a la última hora con movimiento, sin saltos (una hora sin ventas también informa)
        $horas = $porHora->isEmpty() ? collect() : collect(range($porHora->keys()->min(), $porHora->keys()->max()));
        $filas = $horas->map(fn (int $hora) => (object) [
            'hora' => $hora,
            'ventas' => (int) ($porHora[$hora]->ventas ?? 0),
            'venta' => (float) ($porHora[$hora]->venta ?? 0),
        ]);

        $total = (float) $filas->sum('venta');
        $ventas = (int) $filas->sum('ventas');
        $pico = $filas->sortByDesc('venta')->first();
        $etiqueta = fn (int $hora) => sprintf('%02d:00 a %02d:59', $hora, $hora);

        return [
            'titulo' => 'Ventas por hora',
            'columnas' => ['Hora', 'Nº de ventas', 'Venta', 'Ticket promedio', '% del total'],
            'numericas' => [1, 2, 3, 4],
            'filas' => $filas->map(fn ($f) => [
                $etiqueta($f->hora),
                $f->ventas,
                $this->numero($f->venta),
                $this->numero($f->ventas > 0 ? $f->venta / $f->ventas : 0),
                $this->porcentaje($f->venta, $total),
            ])->values(),
            'resumen' => [
                ['etiqueta' => 'Hora de más venta', 'valor' => $pico ? $etiqueta($pico->hora) : '—'],
                ['etiqueta' => 'Vendido en esa hora', 'valor' => $this->soles((float) ($pico->venta ?? 0))],
                ['etiqueta' => 'Nº de ventas', 'valor' => (string) $ventas],
                ['etiqueta' => 'Venta total', 'valor' => $this->soles($total)],
            ],
            'grafico' => $this->grafico(
                'Venta por hora del día',
                $filas->map(fn ($f) => sprintf('%02d h', $f->hora)),
                $filas->pluck('venta'),
                tope: null,
            ),
        ];
    }

    public function porDiaSemana(string $empresaId, ?string $sucursalId, string $desde, string $hasta): array
    {
        $signo = self::SIGNO;

        $porDia = $this->comprobantes($empresaId, $sucursalId, $desde, $hasta)
            ->groupByRaw('EXTRACT(isodow FROM comprobantes.fecha_emision)')
            ->selectRaw('
                EXTRACT(isodow FROM comprobantes.fecha_emision)::int as dia,
                COUNT(DISTINCT comprobantes.fecha_emision) as dias,
                COUNT(*) FILTER (WHERE '.self::ES_VENTA.") as ventas,
                COALESCE(SUM({$signo} * comprobantes.total), 0) as venta
            ")
            ->get()
            ->keyBy('dia');

        $filas = collect(self::DIAS)->map(fn (string $nombre, int $dia) => (object) [
            'nombre' => $nombre,
            'dias' => (int) ($porDia[$dia]->dias ?? 0),
            'ventas' => (int) ($porDia[$dia]->ventas ?? 0),
            'venta' => (float) ($porDia[$dia]->venta ?? 0),
        ])->map(function ($f) {
            // promedio sobre los días en que hubo venta: compara un lunes típico con un sábado típico
            $f->promedio = $f->dias > 0 ? $f->venta / $f->dias : 0.0;

            return $f;
        })->values();

        $total = (float) $filas->sum('venta');
        $diasConVenta = (int) $filas->sum('dias');
        $mejor = $porDia->isEmpty() ? null : $filas->sortByDesc('promedio')->first();

        return [
            'titulo' => 'Ventas por día de la semana',
            'columnas' => ['Día', 'Días con venta', 'Nº de ventas', 'Venta', 'Promedio por día', '% del total'],
            'numericas' => [1, 2, 3, 4, 5],
            // sin ventas en el rango no se listan siete filas en cero
            'filas' => $porDia->isEmpty() ? collect() : $filas->map(fn ($f) => [
                $f->nombre,
                $f->dias,
                $f->ventas,
                $this->numero($f->venta),
                $this->numero($f->promedio),
                $this->porcentaje($f->venta, $total),
            ]),
            'resumen' => $porDia->isEmpty() ? [] : [
                ['etiqueta' => 'Mejor día', 'valor' => $mejor->nombre],
                ['etiqueta' => 'Promedio ese día', 'valor' => $this->soles($mejor->promedio)],
                ['etiqueta' => 'Promedio diario', 'valor' => $this->soles($diasConVenta > 0 ? $total / $diasConVenta : 0)],
                ['etiqueta' => 'Venta total', 'valor' => $this->soles($total)],
            ],
            'grafico' => $porDia->isEmpty() ? null : $this->grafico('Venta promedio por día', $filas->pluck('nombre'), $filas->pluck('promedio'), tope: null),
        ];
    }

    // ---------------------------------------------------------------
    // Ganancias
    // ---------------------------------------------------------------

    /** @param  string|null  $agrupar  dia, semana o mes; null = según el largo del rango */
    public function utilidadPorPeriodo(string $empresaId, ?string $sucursalId, string $desde, string $hasta, ?string $agrupar = null): array
    {
        $agrupar = in_array($agrupar, ['dia', 'semana', 'mes'], true) ? $agrupar : $this->agrupacionSugerida($desde, $hasta);
        $signo = self::SIGNO;

        $periodo = match ($agrupar) {
            'semana' => "date_trunc('week', comprobantes.fecha_emision)",
            'mes' => "date_trunc('month', comprobantes.fecha_emision)",
            default => 'comprobantes.fecha_emision',
        };

        $filas = $this->detalles($empresaId, $sucursalId, $desde, $hasta)
            ->groupByRaw($periodo)
            ->selectRaw("
                to_char({$periodo}, 'YYYY-MM-DD') as periodo,
                COUNT(DISTINCT comprobantes.id) FILTER (WHERE ".self::ES_VENTA.") as ventas,
                SUM({$signo} * comprobante_detalles.total) as venta,
                SUM({$signo} * ".self::COSTO.') as costo
            ')
            ->orderByRaw($periodo)
            ->get();

        $etiqueta = function (string $iso, bool $corta = false) use ($agrupar, $desde, $hasta): string {
            $fecha = Carbon::parse($iso);

            if ($agrupar === 'mes') {
                return $corta ? mb_substr(self::MESES[$fecha->month], 0, 3).' '.$fecha->format('y') : self::MESES[$fecha->month].' '.$fecha->year;
            }

            if ($agrupar === 'semana') {
                // la semana se recorta al rango pedido: no se anuncia un día que no entró en la suma
                $inicio = $fecha->max(Carbon::parse($desde));
                $fin = $fecha->copy()->addDays(6)->min(Carbon::parse($hasta));

                return $corta ? $inicio->format('d/m') : 'Del '.$inicio->format('d/m').' al '.$fin->format('d/m/Y');
            }

            return $corta ? $fecha->format('d/m') : $fecha->format('d/m/Y');
        };

        $venta = (float) $filas->sum('venta');
        $costo = (float) $filas->sum('costo');

        return [
            'titulo' => 'Utilidad por período',
            'contexto' => ['dia' => 'Por día', 'semana' => 'Por semana', 'mes' => 'Por mes'][$agrupar],
            'agrupar' => $agrupar,
            'nota' => 'Utilidad bruta: lo vendido menos lo que te costó la mercadería. No descuenta gastos como alquiler, sueldos o servicios.',
            'columnas' => [['dia' => 'Día', 'semana' => 'Semana', 'mes' => 'Mes'][$agrupar], 'Nº de ventas', 'Venta', 'Costo', 'Utilidad', 'Margen %'],
            'numericas' => [1, 2, 3, 4, 5],
            'filas' => $filas->map(fn ($f) => [
                $etiqueta($f->periodo),
                (int) $f->ventas,
                $this->numero((float) $f->venta),
                $this->numero((float) $f->costo),
                $this->numero((float) $f->venta - (float) $f->costo),
                $this->porcentaje((float) $f->venta - (float) $f->costo, (float) $f->venta),
            ])->values(),
            'resumen' => [
                ['etiqueta' => 'Venta', 'valor' => $this->soles($venta)],
                ['etiqueta' => 'Costo', 'valor' => $this->soles($costo)],
                ['etiqueta' => 'Utilidad', 'valor' => $this->soles($venta - $costo)],
                ['etiqueta' => 'Margen', 'valor' => $this->porcentaje($venta - $costo, $venta)],
            ],
            'grafico' => $this->grafico(
                'Utilidad '.['dia' => 'por día', 'semana' => 'por semana', 'mes' => 'por mes'][$agrupar],
                $filas->map(fn ($f) => $etiqueta($f->periodo, corta: true)),
                $filas->map(fn ($f) => (float) $f->venta - (float) $f->costo),
                tope: null,
            ),
        ];
    }

    // ---------------------------------------------------------------
    // Clientes
    // ---------------------------------------------------------------

    public function clientesQueMasCompran(string $empresaId, ?string $sucursalId, string $desde, string $hasta): array
    {
        $signo = self::SIGNO;

        $filas = $this->comprobantes($empresaId, $sucursalId, $desde, $hasta)
            ->leftJoin('clientes', 'clientes.id', '=', 'comprobantes.cliente_id')
            ->groupBy('comprobantes.cliente_id', 'clientes.nombre', 'clientes.numero_documento')
            ->selectRaw('
                comprobantes.cliente_id,
                clientes.nombre,
                clientes.numero_documento as documento,
                COUNT(*) FILTER (WHERE '.self::ES_VENTA.") as compras,
                COALESCE(SUM({$signo} * comprobantes.total), 0) as total,
                MAX(comprobantes.fecha_emision) FILTER (WHERE ".self::ES_VENTA.') as ultima
            ')
            ->orderByDesc('total')
            ->get();

        $total = (float) $filas->sum('total');
        $identificados = $filas->whereNotNull('cliente_id');
        $ventaIdentificada = (float) $identificados->sum('total');
        $mejor = $identificados->first();
        $nombre = fn ($f) => $f->cliente_id ? $f->nombre : 'Público general (sin identificar)';

        return [
            'titulo' => 'Clientes que más compran',
            'columnas' => ['Cliente', 'Documento', 'Nº de compras', 'Total comprado', 'Ticket promedio', 'Última compra', '% del total'],
            'numericas' => [2, 3, 4, 6],
            'filas' => $filas->map(fn ($f) => [
                $nombre($f),
                $f->cliente_id ? ($f->documento ?: '—') : '—',
                (int) $f->compras,
                $this->numero((float) $f->total),
                $this->numero($f->compras > 0 ? (float) $f->total / $f->compras : 0),
                $f->ultima ? Carbon::parse($f->ultima)->format('d/m/Y') : '—',
                $this->porcentaje((float) $f->total, $total),
            ])->values(),
            'resumen' => [
                ['etiqueta' => 'Clientes que compraron', 'valor' => (string) $identificados->count()],
                ['etiqueta' => 'Venta a clientes registrados', 'valor' => $this->soles($ventaIdentificada)],
                ['etiqueta' => 'Venta al público general', 'valor' => $this->soles($total - $ventaIdentificada)],
                ['etiqueta' => 'Mejor cliente', 'valor' => $mejor?->nombre ?? '—'],
            ],
            // el público general no es un cliente: en la gráfica solo compiten los registrados
            'grafico' => $this->grafico('Mejores clientes', $identificados->pluck('nombre'), $identificados->pluck('total'), horizontal: true),
        ];
    }

    /**
     * Lo que deben los clientes hoy, según cuánto tiempo lleva cada deuda.
     * Las ventas a crédito no guardan fecha de vencimiento: la antigüedad se cuenta desde la venta.
     */
    public function cobranzaPorAntiguedad(string $empresaId, ?string $sucursalId): array
    {
        $hoy = now()->startOfDay();

        $cuentas = CuentaPorCobrar::query()
            ->join('comprobantes', 'comprobantes.id', '=', 'cuentas_por_cobrar.comprobante_id')
            ->join('clientes', 'clientes.id', '=', 'cuentas_por_cobrar.cliente_id')
            ->where('cuentas_por_cobrar.empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('comprobantes.sucursal_id', $sucursalId))
            ->where('cuentas_por_cobrar.estado', '!=', 'pagado')
            ->where('comprobantes.estado', 'emitido')
            ->selectRaw('
                clientes.nombre as cliente,
                clientes.numero_documento as documento,
                clientes.telefono,
                comprobantes.serie,
                comprobantes.correlativo,
                comprobantes.fecha_emision,
                cuentas_por_cobrar.monto_total,
                cuentas_por_cobrar.monto_pagado
            ')
            ->orderBy('comprobantes.fecha_emision')
            ->orderBy('clientes.nombre')
            ->toBase()
            ->get()
            ->map(function ($c) use ($hoy) {
                $c->dias = (int) Carbon::parse($c->fecha_emision)->startOfDay()->diffInDays($hoy);
                $c->saldo = (float) $c->monto_total - (float) $c->monto_pagado;
                $c->tramo = $this->tramo($c->dias);

                return $c;
            });

        $total = (float) $cuentas->sum('saldo');
        $porTramo = collect(self::TRAMOS)->keys()->mapWithKeys(fn ($t) => [$t => (float) $cuentas->where('tramo', $t)->sum('saldo')]);

        return [
            'titulo' => 'Cuentas por cobrar por antigüedad',
            'periodo' => 'Al '.$hoy->format('d/m/Y'),
            'nota' => 'Muestra lo que te deben hoy; no depende de las fechas. La antigüedad se cuenta desde el día de la venta a crédito.',
            'columnas' => ['Cliente', 'Documento', 'Teléfono', 'Comprobante', 'Fecha de venta', 'Días', 'Antigüedad', 'Total', 'Pagado', 'Saldo'],
            'numericas' => [5, 7, 8, 9],
            'filas' => $cuentas->map(fn ($c) => [
                $c->cliente,
                $c->documento ?: '—',
                $c->telefono ?: '—',
                "{$c->serie}-".str_pad((string) $c->correlativo, 6, '0', STR_PAD_LEFT),
                Carbon::parse($c->fecha_emision)->format('d/m/Y'),
                $c->dias,
                $c->tramo,
                $this->numero((float) $c->monto_total),
                $this->numero((float) $c->monto_pagado),
                $this->numero($c->saldo),
            ])->values(),
            'resumen' => $cuentas->isEmpty() ? [] : [
                ['etiqueta' => 'Total por cobrar', 'valor' => $this->soles($total)],
                ...$porTramo->map(fn ($monto, $tramo) => ['etiqueta' => $tramo, 'valor' => $this->soles($monto)])->values()->all(),
            ],
            'grafico' => $cuentas->isEmpty() ? null : $this->grafico('Deuda por antigüedad', $porTramo->keys(), $porTramo->values(), tope: null),
        ];
    }

    // ---------------------------------------------------------------
    // Caja
    // ---------------------------------------------------------------

    /** Por dónde entró y salió el dinero en el rango: mismas partidas que el arqueo de caja. */
    public function cajaPorMedioDePago(string $empresaId, ?string $sucursalId, string $desde, string $hasta): array
    {
        $inicio = Carbon::parse($desde)->startOfDay();
        $fin = Carbon::parse($hasta)->endOfDay();

        $sumar = fn (Builder $consulta, string $tabla) => $consulta
            ->where("{$tabla}.empresa_id", $empresaId)
            ->whereBetween("{$tabla}.creado_en", [$inicio, $fin])
            ->groupBy("{$tabla}.medio_pago_codigo")
            ->selectRaw("{$tabla}.medio_pago_codigo as medio, COALESCE(SUM({$tabla}.monto), 0) as total")
            ->pluck('total', 'medio');

        // con una sucursal elegida: el pago sigue a su comprobante; lo demás, a la caja del turno
        $porTurno = fn (Builder $consulta, string $tabla) => $consulta->when($sucursalId, fn ($q) => $q
            ->join('aperturas_caja', 'aperturas_caja.id', '=', "{$tabla}.apertura_id")
            ->join('cajas', 'cajas.id', '=', 'aperturas_caja.caja_id')
            ->where('cajas.sucursal_id', $sucursalId));

        $ventas = $sumar(Pago::query()->when($sucursalId, fn ($q) => $q
            ->join('comprobantes', 'comprobantes.id', '=', 'pagos.comprobante_id')
            ->where('comprobantes.sucursal_id', $sucursalId)), 'pagos');
        $cobros = $sumar($porTurno(Cobro::query(), 'cobros'), 'cobros');
        $ingresos = $sumar($porTurno(MovimientoCaja::query()->where('movimientos_caja.tipo', 'ingreso'), 'movimientos_caja'), 'movimientos_caja');
        $egresos = $sumar($porTurno(MovimientoCaja::query()->where('movimientos_caja.tipo', 'egreso'), 'movimientos_caja'), 'movimientos_caja');
        $proveedores = $sumar($porTurno(PagoProveedor::query(), 'pagos_proveedor'), 'pagos_proveedor');

        $codigos = collect([$ventas, $cobros, $ingresos, $egresos, $proveedores])->flatMap(fn ($c) => $c->keys())->unique();
        $nombres = MedioPago::whereIn('codigo', $codigos)->pluck('nombre', 'codigo');

        $filas = $codigos->map(function (string $codigo) use ($nombres, $ventas, $cobros, $ingresos, $egresos, $proveedores) {
            $f = (object) [
                'codigo' => $codigo,
                'nombre' => $nombres[$codigo] ?? ucfirst($codigo),
                'ventas' => (float) ($ventas[$codigo] ?? 0),
                'cobros' => (float) ($cobros[$codigo] ?? 0),
                'ingresos' => (float) ($ingresos[$codigo] ?? 0),
                'egresos' => (float) ($egresos[$codigo] ?? 0),
                'proveedores' => (float) ($proveedores[$codigo] ?? 0),
            ];
            $f->entradas = $f->ventas + $f->cobros + $f->ingresos;
            $f->salidas = $f->egresos + $f->proveedores;

            return $f;
        })
            // efectivo primero, el resto por lo que más ingresó
            ->sortBy(fn ($f) => [$f->codigo === 'efectivo' ? 0 : 1, -$f->entradas])
            ->values();

        $entradas = (float) $filas->sum('entradas');
        $salidas = (float) $filas->sum('salidas');
        $efectivo = $filas->firstWhere('codigo', 'efectivo');

        return [
            'titulo' => 'Caja por medio de pago',
            'nota' => 'Ventas cobradas, cobros de créditos, otros ingresos, egresos y pagos a proveedores registrados en caja, según el medio de pago.',
            'columnas' => ['Medio de pago', 'Ventas', 'Cobros de créditos', 'Otros ingresos', 'Egresos', 'Pagos a proveedores', 'Neto'],
            'numericas' => [1, 2, 3, 4, 5, 6],
            'filas' => $filas->map(fn ($f) => [
                $f->nombre,
                $this->numero($f->ventas),
                $this->numero($f->cobros),
                $this->numero($f->ingresos),
                $this->numero($f->egresos),
                $this->numero($f->proveedores),
                $this->numero($f->entradas - $f->salidas),
            ]),
            'resumen' => $filas->isEmpty() ? [] : [
                ['etiqueta' => 'Entró', 'valor' => $this->soles($entradas)],
                ['etiqueta' => 'Salió', 'valor' => $this->soles($salidas)],
                ['etiqueta' => 'Neto', 'valor' => $this->soles($entradas - $salidas)],
                ['etiqueta' => 'Neto en efectivo', 'valor' => $this->soles($efectivo ? $efectivo->entradas - $efectivo->salidas : 0)],
            ],
            'grafico' => $this->grafico('Lo que entró por cada medio', $filas->pluck('nombre'), $filas->pluck('entradas'), horizontal: true),
        ];
    }

    // ---------------------------------------------------------------

    /** Comprobantes emitidos (no anulados) del rango. */
    private function comprobantes(string $empresaId, ?string $sucursalId, string $desde, string $hasta): Builder
    {
        return Comprobante::query()
            ->where('comprobantes.empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('comprobantes.sucursal_id', $sucursalId))
            ->where('comprobantes.estado', 'emitido')
            ->whereBetween('comprobantes.fecha_emision', [$desde, $hasta]);
    }

    /** Líneas vendidas en el rango, unidas a su comprobante. */
    private function detalles(string $empresaId, ?string $sucursalId, string $desde, string $hasta): Builder
    {
        return ComprobanteDetalle::query()
            ->join('comprobantes', 'comprobantes.id', '=', 'comprobante_detalles.comprobante_id')
            ->where('comprobantes.empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('comprobantes.sucursal_id', $sucursalId))
            ->where('comprobantes.estado', 'emitido')
            ->whereBetween('comprobantes.fecha_emision', [$desde, $hasta]);
    }

    private function agrupacionSugerida(string $desde, string $hasta): string
    {
        $dias = Carbon::parse($desde)->diffInDays(Carbon::parse($hasta)) + 1;

        return match (true) {
            $dias <= 31 => 'dia',
            $dias <= 120 => 'semana',
            default => 'mes',
        };
    }

    private function tramo(int $dias): string
    {
        foreach (self::TRAMOS as $etiqueta => $tope) {
            if ($tope === null || $dias <= $tope) {
                return $etiqueta;
            }
        }

        return array_key_last(self::TRAMOS);
    }

    /**
     * Barras para la pantalla (no se exportan). Con tope, solo los primeros: la tabla tiene el resto.
     *
     * @param  iterable<string>  $etiquetas
     * @param  iterable<float|string>  $valores
     */
    private function grafico(string $titulo, iterable $etiquetas, iterable $valores, bool $horizontal = false, ?int $tope = self::TOPE_GRAFICO): ?array
    {
        $etiquetas = collect($etiquetas)->values();
        $valores = collect($valores)->map(fn ($v) => round((float) $v, 2))->values();

        if ($etiquetas->isEmpty()) {
            return null;
        }

        if ($tope !== null && $etiquetas->count() > $tope) {
            $titulo .= " (primeros {$tope})";
            $etiquetas = $etiquetas->take($tope);
            $valores = $valores->take($tope);
        }

        return ['titulo' => $titulo, 'etiquetas' => $etiquetas->all(), 'valores' => $valores->all(), 'horizontal' => $horizontal];
    }

    private function soles(float $valor): string
    {
        return 'S/ '.number_format($valor, 2);
    }

    private function numero(float $valor): string
    {
        return number_format($valor, 2);
    }

    private function cantidad(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 3), '0'), '.');
    }

    private function porcentaje(float $parte, float $total): string
    {
        return ($total > 0 ? number_format($parte / $total * 100, 1) : '0.0').'%';
    }
}
