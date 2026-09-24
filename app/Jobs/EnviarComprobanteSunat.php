<?php

namespace App\Jobs;

use App\Models\Comprobante;
use App\Services\SunatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Envía un comprobante a SUNAT fuera del ciclo de la venta.
 * Se despacha con dispatchAfterResponse() para no demorar el cobro en caja;
 * si el envío falla, queda en "pendiente" y se reenvía desde Comprobantes.
 */
class EnviarComprobanteSunat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $comprobanteId)
    {
    }

    /** @return array<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SunatService $sunat): void
    {
        $comprobante = Comprobante::find($this->comprobanteId);

        if ($comprobante && $comprobante->estado === 'emitido') {
            $sunat->emitir($comprobante); // nunca lanza: los fallos quedan en estado pendiente
        }
    }
}
