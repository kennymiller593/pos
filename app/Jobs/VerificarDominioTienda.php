<?php

namespace App\Jobs;

use App\Models\Empresa;
use App\Services\DominioTiendaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sigue la emisión del certificado de un dominio propio: pregunta a Cloudflare cada minuto
 * hasta que esté activo, falle, o pase media hora (entonces el dueño tiene que volver a verificar).
 */
class VerificarDominioTienda implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const INTENTOS = 30;

    public function __construct(public readonly string $empresaId, public readonly int $intento = 1) {}

    public function handle(DominioTiendaService $dominios): void
    {
        $empresa = Empresa::find($this->empresaId);

        if (! $empresa || $dominios->revisar($empresa)) {
            return;
        }

        if ($this->intento < self::INTENTOS) {
            self::dispatch($this->empresaId, $this->intento + 1)->delay(now()->addMinute());

            return;
        }

        $empresa->forceFill([
            'tienda_dominio_estado' => 'error',
            'tienda_dominio_detalle' => 'El certificado está tardando más de lo normal. Revisa que el CNAME siga apuntando a '.config('tienda.origen').' y vuelve a verificar.',
        ])->save();
    }
}
