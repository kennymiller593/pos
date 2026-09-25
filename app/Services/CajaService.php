<?php

namespace App\Services;

use App\Models\AperturaCaja;
use App\Models\CierreCajaMedio;
use App\Models\MedioPago;
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

    /**
     * Efectivo esperado en el cajon: inicial + ingresos - egresos + ventas y cobros
     * - pagos a proveedor, todo en efectivo. Las devoluciones por Yape, tarjeta,
     * etc. se informan aparte porque no salen del cajon.
     */
    public function resumen(AperturaCaja $apertura): array
    {
        $ingresos = (float) $apertura->movimientos()->where('tipo', 'ingreso')->where('medio_pago_codigo', 'efectivo')->sum('monto');
        $egresos = (float) $apertura->movimientos()->where('tipo', 'egreso')->where('medio_pago_codigo', 'efectivo')->sum('monto');
        $egresosOtrosMedios = (float) $apertura->movimientos()->where('tipo', 'egreso')->where('medio_pago_codigo', '!=', 'efectivo')->sum('monto');
        $ventasEfectivo = (float) $apertura->pagos()->where('medio_pago_codigo', 'efectivo')->sum('monto');
        $cobrosEfectivo = (float) $apertura->cobros()->where('medio_pago_codigo', 'efectivo')->sum('monto');
        $pagosProveedor = (float) $apertura->pagosProveedor()->where('medio_pago_codigo', 'efectivo')->sum('monto');

        return [
            'ingresos' => round($ingresos, 2),
            'egresos' => round($egresos + $pagosProveedor, 2),
            'egresos_otros_medios' => round($egresosOtrosMedios, 2),
            'ventas_efectivo' => round($ventasEfectivo + $cobrosEfectivo, 2),
            'esperado' => round((float) $apertura->monto_inicial + $ingresos - $egresos + $ventasEfectivo + $cobrosEfectivo - $pagosProveedor, 2),
        ];
    }

    /** Billetes y monedas en soles para el conteo del efectivo. */
    public const DENOMINACIONES = ['200', '100', '50', '20', '10', '5', '2', '1', '0.50', '0.20', '0.10'];

    /**
     * Cuadre por medio de pago del turno: lo que entro y salio por cada uno
     * (ventas, cobros de credito, ingresos/egresos manuales, devoluciones por
     * nota de credito y pagos a proveedores) y lo que deberia haber.
     * Solo aparecen los medios con movimiento; el efectivo siempre (incluye el monto inicial).
     *
     * @return list<array{codigo: string, nombre: string, requiere_referencia: bool, inicial: float, ventas: float, cobros: float, ingresos: float, egresos: float, pagos_proveedor: float, esperado: float}>
     */
    public function resumenPorMedio(AperturaCaja $apertura): array
    {
        $sumar = fn ($consulta) => $consulta->selectRaw('medio_pago_codigo, COALESCE(SUM(monto), 0) AS total')
            ->groupBy('medio_pago_codigo')->pluck('total', 'medio_pago_codigo');

        $ventas = $sumar($apertura->pagos());
        $cobros = $sumar($apertura->cobros());
        $ingresos = $sumar($apertura->movimientos()->where('tipo', 'ingreso'));
        $egresos = $sumar($apertura->movimientos()->where('tipo', 'egreso'));
        $pagosProveedor = $sumar($apertura->pagosProveedor());

        $codigos = collect([$ventas, $cobros, $ingresos, $egresos, $pagosProveedor])
            ->flatMap(fn ($c) => $c->keys())
            ->push('efectivo')
            ->unique();

        $medios = MedioPago::whereIn('codigo', $codigos)->get()->keyBy('codigo');

        return $codigos
            ->map(function (string $codigo) use ($apertura, $medios, $ventas, $cobros, $ingresos, $egresos, $pagosProveedor) {
                $inicial = $codigo === 'efectivo' ? (float) $apertura->monto_inicial : 0.0;
                $fila = [
                    'codigo' => $codigo,
                    'nombre' => $medios[$codigo]?->nombre ?? ucfirst($codigo),
                    'requiere_referencia' => (bool) ($medios[$codigo]?->requiere_referencia ?? false),
                    'inicial' => round($inicial, 2),
                    'ventas' => round((float) ($ventas[$codigo] ?? 0), 2),
                    'cobros' => round((float) ($cobros[$codigo] ?? 0), 2),
                    'ingresos' => round((float) ($ingresos[$codigo] ?? 0), 2),
                    'egresos' => round((float) ($egresos[$codigo] ?? 0), 2),
                    'pagos_proveedor' => round((float) ($pagosProveedor[$codigo] ?? 0), 2),
                ];
                $fila['esperado'] = round($fila['inicial'] + $fila['ventas'] + $fila['cobros'] + $fila['ingresos'] - $fila['egresos'] - $fila['pagos_proveedor'], 2);

                return $fila;
            })
            // efectivo primero, el resto por nombre
            ->sortBy(fn ($f) => ($f['codigo'] === 'efectivo' ? '0' : '1').$f['nombre'])
            ->values()
            ->all();
    }

    /**
     * Cierra el turno: cuadra cada medio contra lo declarado por el cajero
     * (null = no verificado, se asume igual al esperado) y guarda el detalle.
     * Debe llamarse dentro de una transaccion con la apertura bloqueada.
     *
     * @param  array<string, float|null>  $declarados  codigo de medio => monto declarado (el efectivo es obligatorio)
     * @param  array<string, int>|null  $conteo  denominacion => cantidad (opcional)
     * @return list<array{codigo: string, nombre: string, esperado: float, declarado: float|null, diferencia: float}>
     */
    public function cerrar(AperturaCaja $apertura, array $declarados, ?array $conteo = null): array
    {
        $cuadre = [];

        foreach ($this->resumenPorMedio($apertura) as $medio) {
            $declarado = array_key_exists($medio['codigo'], $declarados) && $declarados[$medio['codigo']] !== null
                ? round((float) $declarados[$medio['codigo']], 2)
                : null;
            $diferencia = $declarado === null ? 0.0 : round($declarado - $medio['esperado'], 2);

            CierreCajaMedio::create([
                'empresa_id' => $apertura->empresa_id,
                'apertura_id' => $apertura->id,
                'medio_pago_codigo' => $medio['codigo'],
                'esperado' => $medio['esperado'],
                'declarado' => $declarado,
                'diferencia' => $diferencia,
            ]);

            $cuadre[] = ['codigo' => $medio['codigo'], 'nombre' => $medio['nombre'], 'esperado' => $medio['esperado'], 'declarado' => $declarado, 'diferencia' => $diferencia];
        }

        $efectivo = collect($cuadre)->firstWhere('codigo', 'efectivo');

        $apertura->update([
            'monto_cierre' => $efectivo['declarado'] ?? $efectivo['esperado'],
            'monto_sistema' => $efectivo['esperado'],
            'conteo_efectivo' => $conteo ? array_filter($conteo, fn ($n) => (int) $n > 0) : null,
            'cerrada_en' => now(),
        ]);

        return $cuadre;
    }
}
