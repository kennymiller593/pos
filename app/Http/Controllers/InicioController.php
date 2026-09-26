<?php

namespace App\Http\Controllers;

use App\Models\CapaCosto;
use App\Models\Comprobante;
use App\Models\ComprobanteDetalle;
use App\Models\CuentaPorCobrar;
use App\Models\CuentaPorPagar;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\Sucursal;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InicioController extends Controller
{
    public function index(Request $request): Response
    {
        $empresaId = $request->user()->empresa_id;
        $sucursalId = $this->sucursalConsultaId($request); // null = todas
        $hoy = now()->toDateString();

        // en todas las sumas de venta, las notas de credito restan (venta neta)
        $neto = Comprobante::SQL_TOTAL_NETO;

        // ---- ventas de hoy ----
        $ventasHoy = Comprobante::query()
            ->where('empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->where('estado', 'emitido')
            ->whereDate('fecha_emision', $hoy)
            ->selectRaw("COALESCE(SUM({$neto}), 0) as total, COUNT(*) FILTER (WHERE tipo_comprobante_codigo <> '07') as tickets")
            ->first();

        $totalAyer = (float) Comprobante::query()
            ->where('empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->where('estado', 'emitido')
            ->whereDate('fecha_emision', now()->subDay()->toDateString())
            ->selectRaw("COALESCE(SUM({$neto}), 0) as total")
            ->value('total');

        // ---- mes actual vs el mismo tramo del mes pasado ----
        $totalEnRango = fn (string $desde, string $hasta) => (float) Comprobante::query()
            ->where('empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->where('estado', 'emitido')
            ->whereBetween('fecha_emision', [$desde, $hasta])
            ->selectRaw("COALESCE(SUM({$neto}), 0) as total")
            ->value('total');

        $totalMes = $totalEnRango(now()->startOfMonth()->toDateString(), $hoy);
        $totalMesAnterior = $totalEnRango(
            now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            now()->subMonthNoOverflow()->toDateString(),
        );

        // margen real: venta - costo FIFO registrado en cada detalle
        // (los detalles de una nota de credito revierten el margen de lo devuelto)
        $signo = "(CASE WHEN comprobantes.tipo_comprobante_codigo = '07' THEN -1 ELSE 1 END)";

        $margenEnRango = fn (string $desde, string $hasta) => (float) ComprobanteDetalle::query()
            ->join('comprobantes', 'comprobantes.id', '=', 'comprobante_detalles.comprobante_id')
            ->where('comprobantes.empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('comprobantes.sucursal_id', $sucursalId))
            ->where('comprobantes.estado', 'emitido')
            ->whereBetween('comprobantes.fecha_emision', [$desde, $hasta])
            ->selectRaw("COALESCE(SUM({$signo} * (comprobante_detalles.total - comprobante_detalles.costo_unitario * comprobante_detalles.cantidad)), 0) as margen")
            ->value('margen');

        $margenHoy = $margenEnRango($hoy, $hoy);
        $margenMes = $margenEnRango(now()->startOfMonth()->toDateString(), $hoy);

        // ---- serie de ventas de los ultimos 14 dias ----
        $desde = now()->subDays(13)->toDateString();

        $porDia = Comprobante::query()
            ->where('empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->where('estado', 'emitido')
            ->whereDate('fecha_emision', '>=', $desde)
            ->selectRaw("fecha_emision, COALESCE(SUM({$neto}), 0) as total")
            ->groupBy('fecha_emision')
            ->pluck('total', 'fecha_emision')
            ->mapWithKeys(fn ($total, $fecha) => [substr((string) $fecha, 0, 10) => (float) $total]);

        $serie = collect(range(13, 0))->map(function ($dias) use ($porDia) {
            $fecha = now()->subDays($dias)->toDateString();

            return ['fecha' => $fecha, 'total' => $porDia->get($fecha, 0.0)];
        })->values();

        // ---- top productos (30 dias) ----
        $topProductos = ComprobanteDetalle::query()
            ->join('comprobantes', 'comprobantes.id', '=', 'comprobante_detalles.comprobante_id')
            ->join('productos', 'productos.id', '=', 'comprobante_detalles.producto_id')
            ->where('comprobantes.empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('comprobantes.sucursal_id', $sucursalId))
            ->where('comprobantes.estado', 'emitido')
            ->whereDate('comprobantes.fecha_emision', '>=', now()->subDays(29)->toDateString())
            ->groupBy('productos.id', 'productos.nombre')
            ->selectRaw("productos.nombre, SUM({$signo} * comprobante_detalles.cantidad) as cantidad, SUM({$signo} * comprobante_detalles.total) as total")
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($fila) => [
                'nombre' => $fila->nombre,
                'cantidad' => (float) $fila->cantidad,
                'total' => (float) $fila->total,
            ]);

        // ---- ventas por medio de pago (7 dias) ----
        $mediosPago = Pago::query()
            ->join('medios_pago', 'medios_pago.codigo', '=', 'pagos.medio_pago_codigo')
            ->where('pagos.empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q
                ->join('comprobantes', 'comprobantes.id', '=', 'pagos.comprobante_id')
                ->where('comprobantes.sucursal_id', $sucursalId))
            ->where('pagos.creado_en', '>=', now()->subDays(6)->startOfDay())
            ->groupBy('medios_pago.codigo', 'medios_pago.nombre')
            ->selectRaw('medios_pago.nombre, COALESCE(SUM(pagos.monto), 0) as total')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($fila) => ['nombre' => $fila->nombre, 'total' => (float) $fila->total]);

        // ---- ventas por mes (12 ultimos) ----
        $porMes = Comprobante::query()
            ->where('empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->where('estado', 'emitido')
            ->where('fecha_emision', '>=', now()->subMonthsNoOverflow(11)->startOfMonth()->toDateString())
            ->selectRaw("to_char(fecha_emision, 'YYYY-MM') as mes, COALESCE(SUM({$neto}), 0) as total")
            ->groupBy('mes')
            ->pluck('total', 'mes');

        $serieMeses = collect(range(11, 0))->map(function ($haceMeses) use ($porMes) {
            $mes = now()->subMonthsNoOverflow($haceMeses);

            return ['mes' => $mes->format('Y-m'), 'total' => (float) ($porMes[$mes->format('Y-m')] ?? 0)];
        })->values();

        // ---- promedio de venta por dia de la semana (90 dias) ----
        $porDiaSemana = Comprobante::query()
            ->where('empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->where('estado', 'emitido')
            ->where('fecha_emision', '>=', now()->subDays(89)->toDateString())
            ->selectRaw("EXTRACT(isodow FROM fecha_emision) as dia, COALESCE(SUM({$neto}), 0) as total, COUNT(DISTINCT fecha_emision) as dias")
            ->groupBy('dia')
            ->get()
            ->keyBy(fn ($fila) => (int) $fila->dia);

        $serieDias = collect(range(1, 7))->map(fn ($dia) => [
            'dia' => $dia,
            'promedio' => isset($porDiaSemana[$dia]) && $porDiaSemana[$dia]->dias > 0
                ? round($porDiaSemana[$dia]->total / $porDiaSemana[$dia]->dias, 2)
                : 0.0,
            'total' => (float) ($porDiaSemana[$dia]->total ?? 0),
        ])->values();

        // ---- ventas por sucursal (solo si hay mas de una) ----
        $permitidas = $request->user()->sucursalesPermitidas();
        $sucursales = Sucursal::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->when($permitidas, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $ventasSucursales = [];
        if ($sucursales->count() > 1) {
            $totales = Comprobante::query()
                ->where('empresa_id', $empresaId)
                ->where('estado', 'emitido')
                ->whereBetween('fecha_emision', [now()->startOfMonth()->toDateString(), $hoy])
                ->groupBy('sucursal_id')
                ->selectRaw("sucursal_id, COALESCE(SUM({$neto}), 0) as mes, COALESCE(SUM({$neto}) FILTER (WHERE fecha_emision = ?), 0) as hoy", [$hoy])
                ->get()
                ->keyBy('sucursal_id');

            $ventasSucursales = $sucursales->map(fn ($s) => [
                'nombre' => $s->nombre,
                'hoy' => (float) ($totales[$s->id]->hoy ?? 0),
                'mes' => (float) ($totales[$s->id]->mes ?? 0),
            ])->sortByDesc('mes')->values()->all();
        }

        // ---- ultimas ventas (las notas de credito no son ventas) ----
        $ultimasVentas = Comprobante::query()
            ->where('empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->where('tipo_comprobante_codigo', '!=', '07')
            ->with('sucursal:id,nombre')
            ->latest('creado_en')
            ->limit(6)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'numero' => "{$c->serie}-".str_pad($c->correlativo, 6, '0', STR_PAD_LEFT),
                'cliente' => $c->cliente_nombre ?? 'Público general',
                'sucursal' => $c->sucursal?->nombre,
                'hora' => substr((string) $c->hora_emision, 0, 5),
                'fecha' => $c->fecha_emision->toDateString(),
                'total' => (float) $c->total,
                'estado' => $c->estado,
                'es_credito' => (bool) $c->es_credito,
            ]);

        // ---- pendientes ----
        $porCobrar = (float) CuentaPorCobrar::query()
            ->where('empresa_id', $empresaId)
            ->where('estado', '!=', 'pagado')
            ->selectRaw('COALESCE(SUM(monto_total - monto_pagado), 0) as saldo')
            ->value('saldo');

        $porPagar = (float) CuentaPorPagar::query()
            ->where('empresa_id', $empresaId)
            ->where('estado', '!=', 'pagado')
            ->selectRaw('COALESCE(SUM(monto_total - monto_pagado), 0) as saldo')
            ->value('saldo');

        $lotesPorVencer = CapaCosto::query()
            ->where('empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->where('cantidad_restante', '>', 0)
            ->whereHas('lote', fn ($l) => $l
                ->whereNotNull('fecha_vencimiento')
                ->where('fecha_vencimiento', '<=', now()->addDays(30)->toDateString()))
            ->distinct('lote_id')
            ->count('lote_id');

        $filtroSucursal = $sucursalId ? ' and s.sucursal_id = ?' : '';
        $parametros = $sucursalId ? [$sucursalId, $sucursalId] : [];
        $stockBajo = Producto::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->where('controla_stock', true)
            ->whereRaw("(COALESCE((select sum(s.cantidad) from stock s where s.producto_id = productos.id{$filtroSucursal}), 0) <= 0"
                ." or (stock_minimo > 0 and COALESCE((select sum(s.cantidad) from stock s where s.producto_id = productos.id{$filtroSucursal}), 0) <= stock_minimo))", $parametros)
            ->count();

        $variacion = fn (float $actual, float $previo) => $previo > 0 ? round(($actual - $previo) / $previo * 100, 1) : null;
        // margen como % de lo vendido
        $porcentaje = fn (float $margen, float $venta) => $venta > 0 ? round($margen / $venta * 100, 1) : null;

        // margen, acumulado del mes y deudas con proveedores son datos del dueno
        $veFinanzas = $request->user()->can('dashboard.finanzas');

        return Inertia::render('Inicio', [
            'hoy' => [
                'total' => (float) $ventasHoy->total,
                'tickets' => (int) $ventasHoy->tickets,
                'promedio' => $ventasHoy->tickets > 0 ? round($ventasHoy->total / $ventasHoy->tickets, 2) : 0.0,
                'margen' => $veFinanzas ? round($margenHoy, 2) : null,
                'margen_porcentaje' => $veFinanzas ? $porcentaje($margenHoy, (float) $ventasHoy->total) : null,
                'variacion' => $variacion((float) $ventasHoy->total, $totalAyer),
            ],
            'mes' => $veFinanzas ? [
                'total' => round($totalMes, 2),
                'margen' => round($margenMes, 2),
                'margen_porcentaje' => $porcentaje($margenMes, $totalMes),
                'variacion' => $variacion($totalMes, $totalMesAnterior),
            ] : null,
            'serie' => $serie,
            'serieMeses' => $serieMeses,
            'serieDias' => $serieDias,
            'topProductos' => $topProductos,
            'mediosPago' => $mediosPago,
            'ventasSucursales' => $ventasSucursales,
            'ultimasVentas' => $ultimasVentas,
            'pendientes' => [
                'por_cobrar' => $porCobrar,
                'por_pagar' => $veFinanzas ? $porPagar : null,
                'stock_bajo' => $stockBajo,
                'lotes_por_vencer' => $lotesPorVencer,
            ],
        ]);
    }
}
