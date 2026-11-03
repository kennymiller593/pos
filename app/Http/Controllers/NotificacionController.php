<?php

namespace App\Http\Controllers;

use App\Models\CapaCosto;
use App\Models\Comprobante;
use App\Models\ConversacionIa;
use App\Models\CuentaPorCobrar;
use App\Models\CuentaPorPagar;
use App\Models\Pedido;
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
        $sucursalIds = $this->sucursalesConsulta($request); // null = toda la empresa

        $filtroSucursal = $sucursalIds ? ' and s.sucursal_id in ('.implode(',', array_fill(0, count($sucursalIds), '?')).')' : '';
        $parametros = $sucursalIds ? [...$sucursalIds, ...$sucursalIds] : [];

        $stockBajo = Producto::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->where('controla_stock', true)
            ->whereRaw("(COALESCE((select sum(s.cantidad) from stock s where s.producto_id = productos.id{$filtroSucursal}), 0) <= 0"
                ." or (stock_minimo > 0 and COALESCE((select sum(s.cantidad) from stock s where s.producto_id = productos.id{$filtroSucursal}), 0) <= stock_minimo))", $parametros)
            ->count();

        $lotesPorVencer = CapaCosto::query()
            ->where('empresa_id', $empresaId)
            ->when($sucursalIds, fn ($q) => $q->whereIn('sucursal_id', $sucursalIds))
            ->where('cantidad_restante', '>', 0)
            ->whereHas('lote', fn ($l) => $l
                ->whereNotNull('fecha_vencimiento')
                ->where('fecha_vencimiento', '<=', now()->addDays(30)->toDateString()))
            ->distinct('lote_id')
            ->count('lote_id');

        $porCobrar = CuentaPorCobrar::query()
            ->where('empresa_id', $empresaId)
            ->when($sucursalIds, fn ($q) => $q->whereHas('comprobante', fn ($c) => $c->whereIn('sucursal_id', $sucursalIds)))
            ->where('estado', '!=', 'pagado')
            ->selectRaw('COUNT(*) as cuentas, COALESCE(SUM(monto_total - monto_pagado), 0) as saldo')
            ->first();

        $items = [];

        // chats de la tienda en línea con mensajes del cliente que nadie del negocio ha leído
        $chats = ConversacionIa::query()
            ->where('empresa_id', $empresaId)->where('canal', 'tienda')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ia_mensajes')
                ->whereColumn('ia_mensajes.conversacion_id', 'ia_conversaciones.id')
                ->where('ia_mensajes.rol', 'user')
                ->where(fn ($w) => $w->whereNull('ia_conversaciones.leida_en')->orWhereColumn('ia_mensajes.creado_en', '>', 'ia_conversaciones.leida_en')))
            ->count();

        if ($chats > 0) {
            $items[] = [
                'clave' => 'chats_tienda',
                'titulo' => 'Chats de la tienda',
                'detalle' => $chats === 1 ? '1 cliente te escribió en la tienda y nadie le ha respondido.' : "{$chats} clientes te escribieron en la tienda.",
                'cantidad' => $chats,
                'url' => '/asistente/tienda',
            ];
        }

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
            ->when($sucursalIds, fn ($q) => $q->whereIn('sucursal_id', $sucursalIds))
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

        // el certificado de firma vence pronto: sin el no se factura
        $empresa = $request->user()->empresa;
        if ($request->user()->can('empresa.gestionar') && $empresa->certificado_vence_en && $empresa->certificado_vence_en->lte(now()->addDays(30))) {
            $dias = (int) now()->startOfDay()->diffInDays($empresa->certificado_vence_en->startOfDay(), false);
            $items[] = [
                'clave' => 'certificado',
                'titulo' => $dias < 0 ? 'Tu certificado digital venció' : 'Tu certificado digital vence pronto',
                'detalle' => ($dias < 0 ? 'Venció el ' : 'Vence el ').$empresa->certificado_vence_en->format('d/m/Y').'. Renuévalo con tu proveedor y cárgalo en Facturación electrónica.',
                'cantidad' => 1,
                'url' => '/empresa/facturacion-electronica',
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

        // pedidos (encargos) que hay que alistar: atrasados, para hoy y para mañana
        if ($request->user()->can('pedidos.ver')) {
            $pedidos = Pedido::query()
                ->where('empresa_id', $empresaId)
                ->abiertos()
                ->when($sucursalIds, fn ($q) => $q->whereIn('sucursal_id', $sucursalIds))
                // "hoy" es el de Lima (como en la lista de pedidos), no el de la sesión de Postgres
                ->selectRaw('COUNT(*) FILTER (WHERE fecha_entrega < ?) AS atrasados,'
                    .' COUNT(*) FILTER (WHERE fecha_entrega = ?) AS hoy,'
                    .' COUNT(*) FILTER (WHERE fecha_entrega = ?) AS manana', [now()->toDateString(), now()->toDateString(), now()->addDay()->toDateString()])
                ->first();

            $plural = fn (int $n) => $n === 1 ? '1 pedido' : "{$n} pedidos";

            if (($pedidos->atrasados ?? 0) > 0) {
                $items[] = [
                    'clave' => 'pedidos_atrasados',
                    'titulo' => 'Pedidos atrasados',
                    'detalle' => $plural((int) $pedidos->atrasados).($pedidos->atrasados == 1 ? ' debió entregarse y sigue abierto.' : ' debieron entregarse y siguen abiertos.'),
                    'cantidad' => (int) $pedidos->atrasados,
                    'url' => '/pedidos?pestana=atrasados',
                ];
            }
            if (($pedidos->hoy ?? 0) > 0) {
                $items[] = [
                    'clave' => 'pedidos_hoy',
                    'titulo' => 'Pedidos para hoy',
                    'detalle' => $plural((int) $pedidos->hoy).($pedidos->hoy == 1 ? ' se entrega hoy.' : ' se entregan hoy.'),
                    'cantidad' => (int) $pedidos->hoy,
                    'url' => '/pedidos?pestana=hoy',
                ];
            }
            if (($pedidos->manana ?? 0) > 0) {
                $items[] = [
                    'clave' => 'pedidos_manana',
                    'titulo' => 'Pedidos para mañana',
                    'detalle' => $plural((int) $pedidos->manana).($pedidos->manana == 1 ? ' se entrega mañana: conviene alistarlo hoy.' : ' se entregan mañana: conviene alistarlos hoy.'),
                    'cantidad' => (int) $pedidos->manana,
                    'url' => '/pedidos?pestana=manana',
                ];
            }
        }

        return response()->json([
            'items' => $items,
            'total' => collect($items)->sum('cantidad'),
        ]);
    }
}
