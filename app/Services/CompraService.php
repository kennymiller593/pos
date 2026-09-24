<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Auditoria;
use App\Models\CapaCosto;
use App\Models\Compra;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class CompraService
{
    public function __construct(private readonly InventarioService $inventario) {}

    /**
     * Anula una compra mal registrada: retira sus capas de costo y su stock y
     * elimina la deuda con el proveedor. Solo procede si nada de esa
     * mercadería se vendió o movió (las capas siguen completas) y la deuda no
     * tiene pagos; si no, corresponde un ajuste de stock.
     *
     * @throws ErrorDeNegocio
     */
    public function anular(Compra $compra, Usuario $usuario, string $motivo): void
    {
        if ($compra->estado !== 'registrada') {
            throw new ErrorDeNegocio('Esta compra ya está anulada.');
        }

        $cuenta = $compra->cuentaPorPagar;
        if ($cuenta && (float) $cuenta->monto_pagado > 0) {
            throw new ErrorDeNegocio('No se puede anular: la deuda de esta compra ya tiene pagos registrados.');
        }

        DB::transaction(function () use ($compra, $usuario, $motivo) {
            $bloqueada = Compra::lockForUpdate()->find($compra->id);

            if (! $bloqueada || $bloqueada->estado !== 'registrada') {
                throw new ErrorDeNegocio('Esta compra ya está anulada.');
            }

            $compra->load(['detalles.producto' => fn ($q) => $q->withTrashed()]);

            foreach ($compra->detalles as $detalle) {
                $producto = $detalle->producto;

                if (! $producto || ! $producto->controla_stock) {
                    continue;
                }

                $capa = CapaCosto::query()
                    ->where('compra_detalle_id', $detalle->id)
                    ->lockForUpdate()
                    ->first();

                if (! $capa) {
                    continue;
                }

                // si ya se consumio parte de la capa, la mercaderia salio: no hay vuelta atras limpia
                if ((float) $capa->cantidad_restante + 0.0005 < (float) $capa->cantidad_inicial) {
                    throw new ErrorDeNegocio(
                        "No se puede anular: ya se vendió o movió parte de \"{$producto->nombre}\" de esta compra. Registra un ajuste de stock en su lugar."
                    );
                }

                $cantidad = (float) $capa->cantidad_inicial;
                $costo = (float) $capa->costo_unitario;

                $capa->delete();
                $this->inventario->descontarStock($producto->id, $compra->sucursal_id, $cantidad);
                $this->inventario->registrarMovimiento(
                    $compra->empresa_id,
                    $compra->sucursal_id,
                    $producto->id,
                    'compra_anulada',
                    $cantidad,
                    $costo,
                    $compra->id,
                    $usuario->id,
                    $detalle->lote_id,
                );
            }

            $compra->cuentaPorPagar?->delete();

            $compra->update([
                'estado' => 'anulada',
                'anulada_en' => now(),
                'anulada_por' => $usuario->id,
                'motivo_anulacion' => $motivo,
            ]);

            Auditoria::registrar($usuario, 'compra.anulada', 'compra', $compra->id, [
                'documento' => $compra->serie_numero,
                'proveedor' => $compra->proveedor?->razon_social,
                'total' => (float) $compra->total,
                'motivo' => $motivo,
            ]);
        });
    }
}
