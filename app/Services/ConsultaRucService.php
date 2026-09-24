<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Consulta de RUC en SUNAT vía Decolecta. Cachea los aciertos 24 h (y los
 * "no existe" 1 h) para no gastar el plan.
 */
class ConsultaRucService
{
    public const NO_EXISTE = 'no_existe';

    public const SIN_SERVICIO = 'sin_servicio';

    /**
     * @return array{ruc: string, razon_social: ?string, direccion: ?string, distrito: ?string, provincia: ?string, departamento: ?string, ubigeo: ?string, estado: ?string, condicion: ?string}|string
     *                                                                                                                                                                                                  Datos normalizados, NO_EXISTE si SUNAT no lo tiene, o SIN_SERVICIO si la API no respondió.
     */
    public function consultar(string $numero): array|string
    {
        if (blank(config('services.decolecta.token'))) {
            return self::SIN_SERVICIO;
        }

        $claveCache = "consulta.ruc.{$numero}";
        $datos = Cache::get($claveCache);

        if ($datos === self::NO_EXISTE) {
            return self::NO_EXISTE;
        }

        if ($datos === null) {
            try {
                $respuesta = Http::withToken(config('services.decolecta.token'))
                    ->acceptJson()
                    ->timeout(10)
                    ->get(config('services.decolecta.url').'/sunat/ruc', ['numero' => $numero]);

                // Decolecta responde 422 cuando el RUC no existe en SUNAT
                if ($respuesta->notFound() || $respuesta->unprocessableEntity()) {
                    Cache::put($claveCache, self::NO_EXISTE, now()->addHour());

                    return self::NO_EXISTE;
                }

                $respuesta->throw();
                $datos = $respuesta->json();
                Cache::put($claveCache, $datos, now()->addDay());
            } catch (ConnectionException) {
                return self::SIN_SERVICIO;
            } catch (\Throwable $e) {
                report($e);

                return self::SIN_SERVICIO;
            }
        }

        // SUNAT devuelve "-" en varios campos (tipico en RUC 10 de personas naturales)
        $limpiar = fn (?string $valor): ?string => in_array($valor = trim((string) $valor), ['', '-'], true) ? null : $valor;
        $ubigeo = $limpiar($datos['ubigeo'] ?? null);

        return [
            'ruc' => $datos['numero_documento'] ?? $numero,
            'razon_social' => $limpiar($datos['razon_social'] ?? null),
            'direccion' => $limpiar($datos['direccion'] ?? null),
            'distrito' => $limpiar($datos['distrito'] ?? null),
            'provincia' => $limpiar($datos['provincia'] ?? null),
            'departamento' => $limpiar($datos['departamento'] ?? null),
            'ubigeo' => preg_match('/^\d{6}$/', (string) $ubigeo) ? $ubigeo : null,
            'estado' => $datos['estado'] ?? null,
            'condicion' => $datos['condicion'] ?? null,
        ];
    }
}
