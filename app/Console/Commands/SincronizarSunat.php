<?php

namespace App\Console\Commands;

use App\Models\Comprobante;
use App\Models\ComprobanteSunat;
use App\Models\GuiaRemision;
use App\Services\GuiaRemisionService;
use App\Services\SunatService;
use App\Services\VentaService;
use Illuminate\Console\Command;

/**
 * Reintenta los envíos a SUNAT que quedaron pendientes (SUNAT caído, sin red,
 * certificado recién cargado) y confirma las bajas en proceso. Corre cada
 * pocos minutos desde el scheduler; el envío inmediato tras la venta sigue
 * siendo el job EnviarComprobanteSunat.
 */
class SincronizarSunat extends Command
{
    protected $signature = 'sunat:sincronizar {--limite=50 : Máximo de comprobantes a reenviar por corrida}';

    protected $description = 'Reenvía a SUNAT los comprobantes pendientes y confirma las bajas en proceso';

    /** Tras este número de intentos fallidos se deja de reintentar solo (queda el reenvío manual). */
    private const MAX_INTENTOS = 20;

    public function handle(SunatService $sunat, VentaService $ventas, GuiaRemisionService $guias): int
    {
        $reenviados = $this->reenviarPendientes($sunat, (int) $this->option('limite'));
        $bajas = $this->confirmarBajas($ventas);
        $guiasResueltas = $this->sincronizarGuias($guias, (int) $this->option('limite'));

        $this->info("Reenviados: {$reenviados['ok']} aceptados, {$reenviados['rechazados']} rechazados, {$reenviados['pendientes']} siguen pendientes. Bajas confirmadas: {$bajas}. Guías resueltas: {$guiasResueltas}.");

        return self::SUCCESS;
    }

    /** @return array{ok:int, rechazados:int, pendientes:int} */
    private function reenviarPendientes(SunatService $sunat, int $limite): array
    {
        $resultado = ['ok' => 0, 'rechazados' => 0, 'pendientes' => 0];

        $comprobantes = Comprobante::query()
            ->whereIn('tipo_comprobante_codigo', ['01', '03', '07'])
            ->where('estado', 'emitido')
            ->where(fn ($q) => $q
                ->whereDoesntHave('sunat')
                ->orWhereHas('sunat', fn ($s) => $s->where('estado', 'pendiente')->where('intentos', '<', self::MAX_INTENTOS)))
            ->with(['sunat', 'empresa'])
            ->orderBy('creado_en')
            ->limit($limite)
            ->get();

        foreach ($comprobantes as $comprobante) {
            if (! $this->tocaReintentar($comprobante->sunat)) {
                continue;
            }

            $registro = $sunat->emitir($comprobante);

            match ($registro->estado) {
                'aceptado', 'observado' => $resultado['ok']++,
                'rechazado' => $resultado['rechazados']++,
                default => $resultado['pendientes']++,
            };
        }

        return $resultado;
    }

    /**
     * Espera creciente entre intentos (5 min, 10, 20... hasta 6 h) para no
     * martillar a SUNAT cuando está caído.
     */
    private function tocaReintentar(?ComprobanteSunat $registro): bool
    {
        if (! $registro || ! $registro->enviado_en) {
            return true;
        }

        $intentos = max(1, (int) $registro->intentos);
        $esperaMinutos = min(360, 5 * (2 ** ($intentos - 1)));

        return $registro->enviado_en->addMinutes($esperaMinutos)->isPast();
    }

    /**
     * Guías de remisión: consulta los tickets en proceso y reenvía las pendientes
     * (con la misma espera creciente entre intentos). Devuelve cuántas quedaron resueltas.
     */
    private function sincronizarGuias(GuiaRemisionService $guias, int $limite): int
    {
        $resueltas = 0;

        $pendientes = GuiaRemision::query()
            ->where('estado', 'emitida')
            ->where(fn ($q) => $q
                ->where('estado_sunat', 'en_proceso')
                ->orWhere(fn ($p) => $p->where('estado_sunat', 'pendiente')->where('intentos', '<', self::MAX_INTENTOS)))
            ->whereHas('empresa', fn ($e) => $e->where('facturacion_electronica', true))
            ->with('empresa')
            ->orderBy('creado_en')
            ->limit($limite)
            ->get();

        foreach ($pendientes as $guia) {
            if ($guia->estado_sunat === 'en_proceso') {
                $guias->consultar($guia);
            } else {
                $esperaMinutos = min(360, 5 * (2 ** (max(1, (int) $guia->intentos) - 1)));
                if ($guia->enviado_en && ! $guia->enviado_en->addMinutes($esperaMinutos)->isPast()) {
                    continue;
                }
                $guias->enviar($guia);
            }

            if (in_array($guia->estado_sunat, ['aceptado', 'observado', 'rechazado'], true)) {
                $resueltas++;
            }
        }

        return $resueltas;
    }

    private function confirmarBajas(VentaService $ventas): int
    {
        $confirmadas = 0;

        $pendientes = ComprobanteSunat::query()
            ->where('estado', 'baja_pendiente')
            ->whereNotNull('ticket')
            ->with('comprobante.empresa')
            ->get();

        foreach ($pendientes as $registro) {
            if (! $registro->comprobante) {
                continue;
            }

            if ($ventas->confirmarBajaPendiente($registro->comprobante)->estado === 'baja') {
                $confirmadas++;
            }
        }

        return $confirmadas;
    }
}
