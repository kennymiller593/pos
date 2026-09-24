<?php

namespace App\Console\Commands;

use App\Models\Suscripcion;
use App\Services\SuscripcionService;
use Illuminate\Console\Command;

/**
 * Marca como vencidas las suscripciones cuya fecha de fin (más la gracia) ya
 * pasó. El acceso se bloquea igual sin este comando (se calcula al vuelo);
 * esto solo deja el historial ordenado.
 */
class VencerSuscripciones extends Command
{
    protected $signature = 'suscripciones:vencer';

    protected $description = 'Marca como vencidas las suscripciones que pasaron su fecha de fin';

    public function handle(): int
    {
        $vencidas = Suscripcion::query()
            ->where('estado', 'activa')
            ->where('fecha_fin', '<', now()->subDays(SuscripcionService::DIAS_GRACIA)->toDateString())
            ->update(['estado' => 'vencida']);

        $this->info("Suscripciones marcadas como vencidas: {$vencidas}.");

        return self::SUCCESS;
    }
}
