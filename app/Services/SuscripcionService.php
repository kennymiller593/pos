<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use App\Models\Usuario;

/**
 * Estado y límites de la suscripción de cada empresa. Sin pasarela de pago
 * todavía: la prueba se crea al registrarse y los planes se activan o
 * renuevan con `php artisan suscripcion:activar` (o desde la BD).
 */
class SuscripcionService
{
    public const DIAS_PRUEBA = 14;

    /** Días de gracia tras el vencimiento antes de bloquear el acceso. */
    public const DIAS_GRACIA = 3;

    /** Suscripción que hoy da acceso (activa y dentro de fecha + gracia), o null. */
    public function vigente(Empresa $empresa): ?Suscripcion
    {
        return Suscripcion::query()
            ->where('empresa_id', $empresa->id)
            ->where('estado', 'activa')
            ->where('fecha_fin', '>=', now()->subDays(self::DIAS_GRACIA)->toDateString())
            ->with('plan')
            ->orderByDesc('fecha_fin')
            ->first();
    }

    /** Última suscripción registrada aunque haya vencido (para mostrar el estado). */
    public function ultima(Empresa $empresa): ?Suscripcion
    {
        return Suscripcion::query()
            ->where('empresa_id', $empresa->id)
            ->with('plan')
            ->orderByDesc('fecha_fin')
            ->first();
    }

    /** Crea la prueba gratuita de una empresa recién registrada. */
    public function iniciarPrueba(Empresa $empresa): Suscripcion
    {
        $plan = Plan::where('codigo', 'prueba')->firstOrFail();

        return Suscripcion::create([
            'empresa_id' => $empresa->id,
            'plan_id' => $plan->id,
            'fecha_inicio' => now()->toDateString(),
            'fecha_fin' => now()->addDays(self::DIAS_PRUEBA)->toDateString(),
            'estado' => 'activa',
            'es_prueba' => true,
        ]);
    }

    /**
     * Activa (o renueva) un plan por N meses. La BD solo admite una suscripción
     * activa por empresa: renovar el mismo plan extiende la vigente desde su
     * fecha de fin; cambiar de plan (o salir de la prueba) cierra la anterior
     * y abre una nueva desde hoy.
     */
    public function activar(Empresa $empresa, Plan $plan, int $meses = 1, ?string $nota = null): Suscripcion
    {
        $vigente = $this->vigente($empresa);

        if ($vigente && ! $vigente->es_prueba && $vigente->plan_id === $plan->id && $vigente->fecha_fin->isFuture()) {
            $vigente->update([
                'fecha_fin' => $vigente->fecha_fin->copy()->addMonthsNoOverflow($meses)->toDateString(),
                'nota' => trim(implode(' | ', array_filter([$vigente->nota, $nota]))) ?: null,
            ]);

            return $vigente->fresh('plan');
        }

        // la prueba (u otro plan) deja de regir en cuanto entra el nuevo
        Suscripcion::query()
            ->where('empresa_id', $empresa->id)
            ->where('estado', 'activa')
            ->update(['estado' => 'vencida']);

        return Suscripcion::create([
            'empresa_id' => $empresa->id,
            'plan_id' => $plan->id,
            'fecha_inicio' => now()->toDateString(),
            'fecha_fin' => now()->addMonthsNoOverflow($meses)->toDateString(),
            'estado' => 'activa',
            'es_prueba' => false,
            'nota' => $nota,
        ]);
    }

    /** Resumen para el frontend (banner y pantalla de suscripción). */
    public function resumen(Empresa $empresa): array
    {
        $suscripcion = $this->vigente($empresa) ?? $this->ultima($empresa);

        if (! $suscripcion) {
            return ['estado' => 'sin_plan', 'plan' => null, 'fecha_fin' => null, 'dias_restantes' => 0, 'es_prueba' => false, 'vigente' => false];
        }

        $diasRestantes = (int) now()->startOfDay()->diffInDays($suscripcion->fecha_fin->startOfDay(), false);
        $vigente = $suscripcion->estado === 'activa' && $diasRestantes >= -self::DIAS_GRACIA;

        return [
            'estado' => $vigente ? ($diasRestantes < 0 ? 'en_gracia' : 'activa') : 'vencida',
            'plan' => $suscripcion->plan?->nombre,
            'plan_codigo' => $suscripcion->plan?->codigo,
            'fecha_fin' => $suscripcion->fecha_fin->toDateString(),
            'dias_restantes' => $diasRestantes,
            'es_prueba' => (bool) $suscripcion->es_prueba,
            'vigente' => $vigente,
        ];
    }

    /**
     * Comprueba un límite del plan antes de crear un usuario, una sucursal o un comprobante.
     *
     * @param  'usuarios'|'sucursales'|'comprobantes'  $recurso
     *
     * @throws ErrorDeNegocio
     */
    public function verificarLimite(Empresa $empresa, string $recurso): void
    {
        $plan = $this->vigente($empresa)?->plan;

        if (! $plan) {
            throw new ErrorDeNegocio('Tu suscripción no está vigente. Renueva tu plan para seguir operando.');
        }

        [$maximo, $actual, $mensaje] = match ($recurso) {
            'usuarios' => [
                $plan->max_usuarios,
                Usuario::where('empresa_id', $empresa->id)->where('activo', true)->count(),
                'usuarios activos',
            ],
            'sucursales' => [
                $plan->max_sucursales,
                Sucursal::where('empresa_id', $empresa->id)->where('activo', true)->count(),
                'sucursales activas',
            ],
            'comprobantes' => [
                $plan->max_comprobantes_mes,
                Comprobante::where('empresa_id', $empresa->id)
                    ->where('fecha_emision', '>=', now()->startOfMonth()->toDateString())
                    ->count(),
                'comprobantes este mes',
            ],
        };

        if ($maximo !== null && $actual >= $maximo) {
            throw new ErrorDeNegocio("Tu plan {$plan->nombre} permite hasta {$maximo} {$mensaje} y ya llegaste al límite. Cambia de plan en Suscripción.");
        }
    }
}
