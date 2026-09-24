<?php

namespace App\Http\Controllers;

use App\Models\CapaCosto;
use App\Models\Comprobante;
use App\Models\CuentaPorCobrar;
use App\Models\CuentaPorPagar;
use App\Models\Producto;
use App\Services\SuscripcionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    /**
     * Alertas del negocio para la campanita: se calculan al momento,
     * respetando la sucursal elegida en el selector (null = todas).
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $request->user()->empresa_id;
        $sucursalId = $this->sucursalConsultaId($request);

        $filtroSucursal = $sucursalId ? ' and s.sucursal_id = ?' : '';
        $parametros = $sucursalId ? [$sucursalId, $sucursalId] : [];

        $stockBajo = Producto::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->where('controla_stock', true)
            ->whereRaw("(COALESCE((select sum(s.cantidad) from stock s where s.producto_id = productos.id{$filtroSucursal}), 0) <= 0"
                ." or (stock_minimo > 0 and COALESCE((select sum(s.cantidad) from stock s where s.producto_id = productos.id{$filtroSucursal}), 0) <= stock_minimo))", $parametros)
            ->count();

        $lotesPorVencer = CapaCosto::query()
            ->where('empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->where('cantidad_restante', '>', 0)
            ->whereHas('lote', fn ($l) => $l
                ->whereNotNull('fecha_vencimiento')
                ->where('fecha_vencimiento', '<=', now()->addDays(30)->toDateString()))
            ->distinct('lote_id')
            ->count('lote_id');

        $porCobrar = CuentaPorCobrar::query()
            ->where('empresa_id', $empresaId)
            ->where('estado', '!=', 'pagado')
            ->selectRaw('COUNT(*) as cuentas, COALESCE(SUM(monto_total - monto_pagado), 0) as saldo')
            ->first();

        $items = [];

        if ($stockBajo > 0) {
            $items[] = [
                'clave' => 'stock_bajo',
                'titulo' => 'Stock bajo o agotado',
                'detalle' => $stockBajo === 1 ? '1 producto necesita reposición.' : "{$stockBajo} productos necesitan reposición.",
                'cantidad' => $stockBajo,
                'url' => '/stock?bajos=1',
            ];
        }

        if ($lotesPorVencer > 0) {
            $items[] = [
                'clave' => 'lotes_por_vencer',
                'titulo' => 'Lotes por vencer',
                'detalle' => ($lotesPorVencer === 1 ? '1 lote vence' : "{$lotesPorVencer} lotes vencen").' dentro de 30 días o ya vencieron.',
                'cantidad' => $lotesPorVencer,
                'url' => '/stock?vencen=1',
            ];
        }

        if (($porCobrar->cuentas ?? 0) > 0) {
            $items[] = [
                'clave' => 'por_cobrar',
                'titulo' => 'Cuentas por cobrar',
                'detalle' => ($porCobrar->cuentas === 1 ? '1 cliente te debe' : "{$porCobrar->cuentas} deudas suman")
                    .' S/ '.number_format((float) $porCobrar->saldo, 2).'.',
                'cantidad' => (int) $porCobrar->cuentas,
                'url' => '/cuentas-por-cobrar',
            ];
        }

        $porPagar = CuentaPorPagar::query()
            ->where('empresa_id', $empresaId)
            ->where('estado', '!=', 'pagado')
            ->selectRaw('COUNT(*) as cuentas, COALESCE(SUM(monto_total - monto_pagado), 0) as saldo,'
                .' COUNT(*) FILTER (WHERE fecha_vencimiento < CURRENT_DATE) as vencidas')
            ->first();

        if (($porPagar->cuentas ?? 0) > 0) {
            $items[] = [
                'clave' => 'por_pagar',
                'titulo' => 'Deudas con proveedores',
                'detalle' => ($porPagar->cuentas === 1 ? '1 deuda de' : "{$porPagar->cuentas} deudas suman")
                    .' S/ '.number_format((float) $porPagar->saldo, 2)
                    .($porPagar->vencidas > 0 ? " ({$porPagar->vencidas} vencida".($porPagar->vencidas === 1 ? '' : 's').')' : '')
                    .'.',
                'cantidad' => (int) $porPagar->cuentas,
                'url' => '/cuentas-por-pagar',
            ];
        }

        // comprobantes electronicos que SUNAT no ha aceptado: los pendientes con mas de
        // 24 h corren riesgo de vencer el plazo legal de envio; los rechazados exigen accion
        $sunat = Comprobante::query()
            ->where('empresa_id', $empresaId)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->whereIn('tipo_comprobante_codigo', ['01', '03', '07'])
            ->where('estado', 'emitido')
            ->where('creado_en', '<', now()->subDay())
            ->where(fn ($q) => $q
                ->whereDoesntHave('sunat')
                ->orWhereHas('sunat', fn ($s) => $s->whereIn('estado', ['pendiente', 'rechazado'])))
            ->count();

        if ($sunat > 0) {
            $items[] = [
                'clave' => 'sunat_pendientes',
                'titulo' => 'Comprobantes sin aceptar en SUNAT',
                'detalle' => ($sunat === 1 ? '1 comprobante lleva' : "{$sunat} comprobantes llevan")
                    .' más de un día sin ser aceptado'.($sunat === 1 ? '' : 's').' por SUNAT.',
                'cantidad' => $sunat,
                'url' => '/comprobantes?sunat=pendiente',
            ];
        }

        // el plan vence pronto: solo lo ve quien puede gestionarlo
        if ($request->user()->can('empresa.gestionar')) {
            $suscripcion = app(SuscripcionService::class)->resumen($request->user()->empresa);

            if ($suscripcion['vigente'] && $suscripcion['dias_restantes'] <= 7) {
                $dias = $suscripcion['dias_restantes'];
                $items[] = [
                    'clave' => 'suscripcion',
                    'titulo' => $suscripcion['es_prueba'] ? 'Tu prueba gratuita termina pronto' : 'Tu plan vence pronto',
                    'detalle' => $dias < 0
                        ? 'Venció hace '.abs($dias).' día'.(abs($dias) === 1 ? '' : 's').'; estás en los días de gracia.'
                        : ($dias === 0 ? 'Vence hoy.' : "Vence en {$dias} día".($dias === 1 ? '' : 's').'.'),
                    'cantidad' => 1,
                    'url' => '/suscripcion',
                ];
            }
        }

        return response()->json([
            'items' => $items,
            'total' => collect($items)->sum('cantidad'),
        ]);
    }
}
