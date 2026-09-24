<?php

namespace App\Jobs;

use App\Mail\ComprobanteEmitido;
use App\Models\Comprobante;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Genera el PDF y manda el comprobante por correo fuera del ciclo de la
 * petición (dispatchAfterResponse), para que la caja no espere al SMTP.
 */
class EnviarComprobantePorCorreo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $comprobanteId, public readonly string $email) {}

    /** @return array<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $comprobante = Comprobante::with(['empresa', 'sunat'])->find($this->comprobanteId);

        if ($comprobante) {
            Mail::to($this->email)->send(new ComprobanteEmitido($comprobante));
        }
    }
}
