<?php

namespace App\Services;

use App\Models\AperturaCaja;
use App\Models\Usuario;

class CajaService
{
    /** Apertura de caja vigente del usuario (con su caja cargada), o null. */
    public function aperturaDe(Usuario $usuario): ?AperturaCaja
    {
        return AperturaCaja::query()
            ->where('usuario_id', $usuario->id)
            ->whereNull('cerrada_en')
            ->with('caja:id,nombre,sucursal_id')
            ->first();
    }

    /** Efectivo esperado en el cajon: inicial + ingresos - egresos + ventas y cobros - pagos a proveedor, todo en efectivo. */
    public function resumen(AperturaCaja $apertura): array
    {
        $ingresos = (float) $apertura->movimientos()->where('tipo', 'ingreso')->sum('monto');
        $egresos = (float) $apertura->movimientos()->where('tipo', 'egreso')->sum('monto');
        $ventasEfectivo = (float) $apertura->pagos()->where('medio_pago_codigo', 'efectivo')->sum('monto');
        $cobrosEfectivo = (float) $apertura->cobros()->where('medio_pago_codigo', 'efectivo')->sum('monto');
        $pagosProveedor = (float) $apertura->pagosProveedor()->where('medio_pago_codigo', 'efectivo')->sum('monto');

        return [
            'ingresos' => round($ingresos, 2),
            'egresos' => round($egresos + $pagosProveedor, 2),
            'ventas_efectivo' => round($ventasEfectivo + $cobrosEfectivo, 2),
            'esperado' => round((float) $apertura->monto_inicial + $ingresos - $egresos + $ventasEfectivo + $cobrosEfectivo - $pagosProveedor, 2),
        ];
    }
}
