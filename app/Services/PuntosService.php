<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\MovimientoPuntos;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Programa de puntos: el cliente gana puntos al comprar y los canjea como descuento.
 *
 * Reglas (las fija cada empresa): por cada puntos_soles_por_punto de compra gana 1 punto,
 * y cada punto vale puntos_valor soles al canjear. El saldo nunca baja de cero.
 * Todo cambio pasa por mover(), que bloquea al cliente y deja el movimiento con su saldo.
 */
class PuntosService
{
    /** Reglas del programa para la pantalla, o null si la empresa no lo usa. */
    public function reglas(Empresa $empresa): ?array
    {
        if (! $empresa->puntos_activo) {
            return null;
        }

        return [
            'soles_por_punto' => (float) $empresa->puntos_soles_por_punto,
            'valor' => (float) $empresa->puntos_valor,
            'minimo_canje' => (int) $empresa->puntos_minimo_canje,
        ];
    }

    /** Puntos que da una compra de este importe. */
    public function puntosPorCompra(Empresa $empresa, float $total): int
    {
        $solesPorPunto = (float) $empresa->puntos_soles_por_punto;

        if (! $empresa->puntos_activo || $solesPorPunto <= 0 || $total <= 0) {
            return 0;
        }

        // el epsilon evita que 30 / 0.1 quede en 299.999 y pierda un punto
        return (int) floor($total / $solesPorPunto + 1e-9);
    }

    /**
     * El canje se cobra como descuento en las líneas de la venta: aquí se comprueba que el cliente
     * tenga esos puntos y que el descuento de la venta los cubra (no se queman puntos sin descuento).
     *
     * @throws ErrorDeNegocio
     */
    public function validarCanje(Empresa $empresa, ?Cliente $cliente, int $puntos, float $descuentos, bool $bloquear = false): void
    {
        if ($puntos <= 0) {
            return;
        }

        if (! $empresa->puntos_activo) {
            throw new ErrorDeNegocio('El programa de puntos no está activo.');
        }

        if (! $cliente) {
            throw new ErrorDeNegocio('Para canjear puntos elige al cliente.');
        }

        $saldo = $bloquear
            ? (int) Cliente::whereKey($cliente->id)->lockForUpdate()->value('puntos')
            : (int) $cliente->puntos;

        if ($puntos > $saldo) {
            throw new ErrorDeNegocio("{$cliente->nombre} solo tiene {$saldo} puntos.");
        }

        if ($puntos < (int) $empresa->puntos_minimo_canje) {
            throw new ErrorDeNegocio("Se canjea desde {$empresa->puntos_minimo_canje} puntos.");
        }

        if (round($puntos * (float) $empresa->puntos_valor, 2) > round($descuentos, 2) + 0.005) {
            throw new ErrorDeNegocio('El descuento de la venta no cubre los puntos canjeados. Vuelve a aplicar el canje.');
        }
    }

    /**
     * Puntos de una venta recién registrada: primero se descuentan los canjeados y luego se suman
     * los que gana por lo que pagó. Debe llamarse dentro de la transacción de la venta.
     */
    public function porVenta(Comprobante $comprobante, ?Cliente $cliente, int $canjeados, Usuario $usuario): void
    {
        if (! $cliente) {
            return;
        }

        $numero = $this->numero($comprobante);

        if ($canjeados > 0) {
            $this->mover($cliente, -$canjeados, 'canje', "Canje en {$numero}", $comprobante, $usuario);
        }

        $ganados = $this->puntosPorCompra($comprobante->empresa, (float) $comprobante->total);
        if ($ganados > 0) {
            $this->mover($cliente, $ganados, 'ganado', "Compra {$numero}", $comprobante, $usuario);
        }
    }

    /**
     * Anulación: la venta deja de existir, así que se deshace todo lo que movió
     * (se quitan los puntos que dio y se devuelven los que se canjearon en ella).
     */
    public function porAnulacion(Comprobante $comprobante, Usuario $usuario): void
    {
        $neto = (int) MovimientoPuntos::where('comprobante_id', $comprobante->id)->sum('puntos');
        $clienteId = MovimientoPuntos::where('comprobante_id', $comprobante->id)->value('cliente_id');

        if ($neto === 0 || ! $clienteId) {
            return;
        }

        // withTrashed: aunque el cliente se haya eliminado despues, su saldo se corrige igual
        $cliente = Cliente::withTrashed()->find($clienteId);
        if ($cliente) {
            $this->mover($cliente, -$neto, 'anulacion', "Anulación de {$this->numero($comprobante)}", $comprobante, $usuario);
        }
    }

    /**
     * Nota de crédito: se quitan los puntos que dio la parte devuelta, en proporción al importe.
     * Los puntos canjeados en la venta no se devuelven (el reembolso ya es por lo que pagó).
     */
    public function porNotaCredito(Comprobante $nota, Comprobante $original, Usuario $usuario): void
    {
        $ganados = (int) MovimientoPuntos::where('comprobante_id', $original->id)->where('tipo', 'ganado')->sum('puntos');
        $yaQuitados = -(int) MovimientoPuntos::where('comprobante_id', $original->id)->where('tipo', 'devolucion')->sum('puntos');
        $clienteId = MovimientoPuntos::where('comprobante_id', $original->id)->where('tipo', 'ganado')->value('cliente_id');

        if ($ganados <= 0 || ! $clienteId || (float) $original->total <= 0) {
            return;
        }

        $quitar = min($ganados - $yaQuitados, (int) round($ganados * (float) $nota->total / (float) $original->total));
        $cliente = Cliente::withTrashed()->find($clienteId);

        if ($quitar > 0 && $cliente) {
            // se enlaza a la venta original: si luego se anula, la cuenta de esa venta cuadra
            $this->mover($cliente, -$quitar, 'devolucion', "Nota de crédito {$this->numero($nota)} ({$this->numero($original)})", $original, $usuario);
        }
    }

    /**
     * Suma o resta puntos a mano (regalo, corrección, premio entregado fuera de una venta).
     *
     * @throws ErrorDeNegocio
     */
    public function ajustar(Cliente $cliente, int $puntos, string $concepto, Usuario $usuario): MovimientoPuntos
    {
        if ($puntos === 0) {
            throw new ErrorDeNegocio('Indica cuántos puntos sumar o restar.');
        }

        return DB::transaction(function () use ($cliente, $puntos, $concepto, $usuario) {
            $saldo = (int) Cliente::whereKey($cliente->id)->lockForUpdate()->value('puntos');

            if ($saldo + $puntos < 0) {
                throw new ErrorDeNegocio("No se pueden restar {$this->abs($puntos)} puntos: {$cliente->nombre} tiene {$saldo}.");
            }

            return $this->mover($cliente, $puntos, 'ajuste', $concepto, null, $usuario);
        });
    }

    /**
     * Aplica el cambio con el cliente bloqueado. Si se pide quitar más de lo que tiene
     * (anuló una venta cuyos puntos ya gastó), se quita lo que haya: el saldo no baja de cero.
     */
    private function mover(Cliente $cliente, int $puntos, string $tipo, string $concepto, ?Comprobante $comprobante, ?Usuario $usuario): ?MovimientoPuntos
    {
        return DB::transaction(function () use ($cliente, $puntos, $tipo, $concepto, $comprobante, $usuario) {
            $saldo = (int) Cliente::withTrashed()->whereKey($cliente->id)->lockForUpdate()->value('puntos');
            $nuevo = max(0, $saldo + $puntos);
            $aplicado = $nuevo - $saldo;

            if ($aplicado === 0) {
                return null;
            }

            Cliente::withTrashed()->whereKey($cliente->id)->update(['puntos' => $nuevo]);
            $cliente->puntos = $nuevo;
            $cliente->syncOriginalAttribute('puntos');

            return MovimientoPuntos::create([
                'empresa_id' => $cliente->empresa_id,
                'cliente_id' => $cliente->id,
                'comprobante_id' => $comprobante?->id,
                'usuario_id' => $usuario?->id,
                'tipo' => $tipo,
                'puntos' => $aplicado,
                'saldo' => $nuevo,
                'concepto' => mb_substr($concepto, 0, 200),
            ]);
        });
    }

    private function numero(Comprobante $comprobante): string
    {
        return "{$comprobante->serie}-".str_pad((string) $comprobante->correlativo, 6, '0', STR_PAD_LEFT);
    }

    private function abs(int $puntos): int
    {
        return abs($puntos);
    }
}
