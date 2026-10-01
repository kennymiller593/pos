<?php

namespace App\Jobs;

use App\Mail\CotizacionEnviada;
use App\Models\Cotizacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Genera el PDF y manda la cotización por correo fuera del ciclo de la
 * petición (dispatchAfterResponse), para no hacer esperar al vendedor.
 */
class EnviarCotizacionPorCorreo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $cotizacionId, public readonly string $email) {}

    /** @return array<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $cotizacion = Cotizacion::with('empresa')->find($this->cotizacionId);

        if ($cotizacion) {
            Mail::to($this->email)->send(new CotizacionEnviada($cotizacion));
        }
    }
}
