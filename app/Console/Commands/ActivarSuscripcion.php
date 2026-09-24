<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\Plan;
use App\Services\SuscripcionService;
use Illuminate\Console\Command;

/**
 * Activación manual de planes mientras no hay pasarela de pago:
 *   php artisan suscripcion:activar 20123456786 negocio --meses=12 --nota="Pago Yape 24/09"
 */
class ActivarSuscripcion extends Command
{
    protected $signature = 'suscripcion:activar
        {ruc : RUC de la empresa}
        {plan : Código del plan (emprendedor, negocio, empresa...)}
        {--meses=1 : Meses a activar}
        {--nota= : Referencia del pago u observación}';

    protected $description = 'Activa o renueva el plan de una empresa por N meses';

    public function handle(SuscripcionService $suscripciones): int
    {
        $empresa = Empresa::where('ruc', $this->argument('ruc'))->first();
        $plan = Plan::where('codigo', $this->argument('plan'))->where('activo', true)->first();

        if (! $empresa) {
            $this->error('No existe una empresa con ese RUC.');

            return self::FAILURE;
        }

        if (! $plan) {
            $this->error('Plan no encontrado. Disponibles: '.Plan::where('activo', true)->pluck('codigo')->implode(', '));

            return self::FAILURE;
        }

        $suscripcion = $suscripciones->activar($empresa, $plan, max(1, (int) $this->option('meses')), $this->option('nota'));

        $this->info("{$empresa->razon_social}: plan {$plan->nombre} activo del {$suscripcion->fecha_inicio->toDateString()} al {$suscripcion->fecha_fin->toDateString()}.");

        return self::SUCCESS;
    }
}
