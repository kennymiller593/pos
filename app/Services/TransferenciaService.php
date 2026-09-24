<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Transferencia;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class TransferenciaService
{
    public function __construct(private readonly InventarioService $inventario) {}

    /**
     * Envía mercadería: consume FIFO en el origen, descuenta stock y deja la
     * transferencia "en tránsito". El costo viaja en el kardex de salida.
     *
     * $datos: sucursal_destino_id, observacion?, items[{producto_id, cantidad}]
     *
     * @throws ErrorDeNegocio
     */
    public function enviar(Usuario $usuario, string $sucursalOrigenId, array $datos): Transferencia
    {
        $empresaId = $usuario->empresa_id;

        $destino = Sucursal::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->find($datos['sucursal_destino_id']);

        if (! $destino) {
            throw new ErrorDeNegocio('La sucursal de destino no existe o está desactivada.');
        }

        if ($destino->id === $sucursalOrigenId) {
            throw new ErrorDeNegocio('El origen y el destino no pueden ser la misma sucursal.');
        }

        // consolidar cantidades por producto y validar
        $cantidades = collect($datos['items'])
            ->groupBy('producto_id')
            ->map(fn ($items) => round($items->sum('cantidad'), 3));

        $productos = Producto::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('id', $cantidades->keys())
            ->get()
            ->keyBy('id');

        foreach ($cantidades as $productoId => $cantidad) {
            $producto = $productos->get($productoId);

            if (! $producto) {
                throw new ErrorDeNegocio('Uno de los productos elegidos ya no existe.');
            }

            if (! $producto->controla_stock) {
                throw new ErrorDeNegocio("\"{$producto->nombre}\" no controla stock: no se puede transferir.");
            }

            if (! $producto->permite_fraccion && fmod($cantidad, 1) != 0) {
                throw new ErrorDeNegocio("\"{$producto->nombre}\" no permite cantidades fraccionadas.");
            }

            $disponible = $this->inventario->stockDisponible($productoId, $sucursalOrigenId);
            if ($cantidad > $disponible) {
                throw new ErrorDeNegocio("Stock insuficiente de \"{$producto->nombre}\" en el origen (disponible: {$disponible}).");
            }
        }

        return DB::transaction(function () use ($usuario, $empresaId, $sucursalOrigenId, $destino, $datos, $cantidades) {
            $transferencia = Transferencia::create([
                'empresa_id' => $empresaId,
                'sucursal_origen_id' => $sucursalOrigenId,
                'sucursal_destino_id' => $destino->id,
                'usuario_id' => $usuario->id,
                'estado' => 'en_transito',
                'observacion' => $datos['observacion'] ?? null,
            ]);

            foreach ($cantidades as $productoId => $cantidad) {
                $consumo = $this->inventario->consumirFifo($productoId, $sucursalOrigenId, $cantidad);
                $this->inventario->descontarStock($productoId, $sucursalOrigenId, $cantidad);

                // un detalle por lote consumido (FEFO), para que el lote y su
                // costo viajen al destino; sin lote queda una sola linea
                $porLote = collect($consumo['consumos'])->groupBy(fn ($c) => $c['lote_id'] ?? '');

                foreach ($porLote as $loteId => $grupo) {
                    $cantidadLote = round($grupo->sum('cantidad'), 3);
                    $costoLote = $grupo->sum(fn ($c) => $c['cantidad'] * $c['costo_unitario']);

                    $transferencia->detalles()->create([
                        'producto_id' => $productoId,
                        'lote_id' => $loteId ?: null,
                        'cantidad' => $cantidadLote,
                    ]);

                    // el costo viaja en el kardex: recibir() lo lee de aqui
                    $this->inventario->registrarMovimiento(
                        $empresaId,
                        $sucursalOrigenId,
                        $productoId,
                        'transferencia_salida',
                        $cantidadLote,
                        $cantidadLote > 0 ? $costoLote / $cantidadLote : 0,
                        $transferencia->id,
                        $usuario->id,
                        loteId: $loteId ?: null,
                    );
                }
            }

            return $transferencia;
        });
    }

    /**
     * Recibe la mercadería en el destino: crea capas con el costo que salió
     * del origen, suma stock y marca la transferencia como recibida.
     *
     * @throws ErrorDeNegocio
     */
    public function recibir(Transferencia $transferencia, Usuario $usuario): void
    {
        if (! in_array($transferencia->estado, ['pendiente', 'en_transito'], true)) {
            throw new ErrorDeNegocio('Esta transferencia ya fue '.($transferencia->estado === 'recibida' ? 'recibida' : 'anulada').'.');
        }

        // solo quien trabaja en el destino confirma que la mercaderia llego
        $permitidas = $usuario->sucursalesPermitidas();
        if ($permitidas && ! in_array($transferencia->sucursal_destino_id, $permitidas, true)) {
            throw new ErrorDeNegocio('Solo un usuario de la sucursal de destino puede confirmar la recepción.');
        }

        DB::transaction(function () use ($transferencia, $usuario) {
            $this->bloquearEnTransito($transferencia);
            $transferencia->load(['detalles.producto', 'detalles.lote']);

            foreach ($transferencia->detalles as $detalle) {
                $costo = $this->costoDeSalida($transferencia, $detalle->producto_id, $detalle->lote_id);
                $cantidad = (float) $detalle->cantidad;

                // el lote se replica en el destino con su numero y vencimiento
                $loteDestinoId = null;
                if ($detalle->lote) {
                    $loteDestinoId = $this->inventario->obtenerLote(
                        $detalle->producto,
                        $transferencia->sucursal_destino_id,
                        $detalle->lote->numero_lote,
                        $detalle->lote->fecha_vencimiento?->toDateString(),
                    )->id;
                }

                $this->inventario->ingresarCapa($detalle->producto, $transferencia->sucursal_destino_id, $cantidad, $costo, loteId: $loteDestinoId);
                $this->inventario->incrementarStock($transferencia->empresa_id, $detalle->producto_id, $transferencia->sucursal_destino_id, $cantidad);
                $this->inventario->registrarMovimiento(
                    $transferencia->empresa_id,
                    $transferencia->sucursal_destino_id,
                    $detalle->producto_id,
                    'transferencia_entrada',
                    $cantidad,
                    $costo,
                    $transferencia->id,
                    $usuario->id,
                    loteId: $loteDestinoId,
                );
            }

            $transferencia->update(['estado' => 'recibida', 'recibida_en' => now()]);
        });
    }

    /**
     * Anula una transferencia en tránsito devolviendo la mercadería al origen.
     *
     * @throws ErrorDeNegocio
     */
    public function anular(Transferencia $transferencia, Usuario $usuario): void
    {
        if (! in_array($transferencia->estado, ['pendiente', 'en_transito'], true)) {
            throw new ErrorDeNegocio('Solo se pueden anular transferencias en tránsito.');
        }

        DB::transaction(function () use ($transferencia, $usuario) {
            $this->bloquearEnTransito($transferencia);
            $transferencia->load('detalles.producto');

            foreach ($transferencia->detalles as $detalle) {
                $costo = $this->costoDeSalida($transferencia, $detalle->producto_id, $detalle->lote_id);
                $cantidad = (float) $detalle->cantidad;

                // vuelve al origen con su mismo lote (el lote original es de esa sucursal)
                $this->inventario->ingresarCapa($detalle->producto, $transferencia->sucursal_origen_id, $cantidad, $costo, loteId: $detalle->lote_id);
                $this->inventario->incrementarStock($transferencia->empresa_id, $detalle->producto_id, $transferencia->sucursal_origen_id, $cantidad);
                $this->inventario->registrarMovimiento(
                    $transferencia->empresa_id,
                    $transferencia->sucursal_origen_id,
                    $detalle->producto_id,
                    'devolucion',
                    $cantidad,
                    $costo,
                    $transferencia->id,
                    $usuario->id,
                    loteId: $detalle->lote_id,
                );
            }

            $transferencia->update(['estado' => 'anulada']);
        });
    }

    /**
     * Relee la transferencia con FOR UPDATE y exige que siga en tránsito: dos
     * recepciones simultáneas (o recibir y anular a la vez) no pueden duplicar stock.
     *
     * @throws ErrorDeNegocio
     */
    private function bloquearEnTransito(Transferencia $transferencia): void
    {
        $bloqueada = Transferencia::lockForUpdate()->find($transferencia->id);

        if (! $bloqueada || ! in_array($bloqueada->estado, ['pendiente', 'en_transito'], true)) {
            throw new ErrorDeNegocio('Esta transferencia ya fue procesada por otro usuario.');
        }
    }

    /** Costo unitario con el que salió el producto (y lote) del origen, registrado en el kardex. */
    private function costoDeSalida(Transferencia $transferencia, string $productoId, ?string $loteId = null): float
    {
        return (float) MovimientoInventario::query()
            ->where('referencia_id', $transferencia->id)
            ->where('producto_id', $productoId)
            ->where('tipo', 'transferencia_salida')
            ->when($loteId, fn ($q) => $q->where('lote_id', $loteId), fn ($q) => $q->whereNull('lote_id'))
            ->value('costo_unitario');
    }
}
