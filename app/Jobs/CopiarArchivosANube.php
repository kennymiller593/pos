<?php

namespace App\Jobs;

use App\Console\Commands\RespaldarEnNube;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Copia a la nube, apenas se guardan, los XML y CDR de SUNAT (comprobantes, bajas y guías).
 * El original queda en el servidor y el respaldo nocturno (respaldo:nube) completa lo que
 * aquí fallara: la emisión nunca depende de que la nube responda.
 */
class CopiarArchivosANube implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @param  list<string>  $rutas  rutas en el disco local (storage/app/private), p. ej. sunat/{empresa}/... */
    public function __construct(public readonly array $rutas) {}

    /** Encola la copia solo si el respaldo en la nube está configurado. */
    public static function encolar(array $rutas): void
    {
        $rutas = array_values(array_filter($rutas));

        if ($rutas !== [] && RespaldarEnNube::configurado()) {
            static::dispatch($rutas);
        }
    }

    /** @return array<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $local = Storage::disk('local');
        $nube = Storage::disk('respaldo');

        foreach ($this->rutas as $ruta) {
            if (! $local->exists($ruta)) {
                continue;
            }

            $flujo = $local->readStream($ruta);
            try {
                $nube->writeStream(RespaldarEnNube::PREFIJO_PRIVADO.'/'.$ruta, $flujo);
            } finally {
                if (is_resource($flujo)) {
                    fclose($flujo);
                }
            }
        }
    }
}
