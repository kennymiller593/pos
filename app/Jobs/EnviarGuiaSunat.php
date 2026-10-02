<?php

namespace App\Jobs;

use App\Models\GuiaRemision;
use App\Services\GuiaRemisionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Envía una guía de remisión a SUNAT fuera del ciclo de la petición.
 * Si el envío falla queda en "pendiente" y se reintenta desde Guías o con sunat:sincronizar.
 */
class EnviarGuiaSunat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $guiaId) {}

    /** @return array<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(GuiaRemisionService $guias): void
    {
        $guia = GuiaRemision::find($this->guiaId);

        if ($guia && $guia->estado === 'emitida') {
            $guias->enviar($guia); // nunca lanza: los fallos quedan en estado pendiente
        }
    }
}
