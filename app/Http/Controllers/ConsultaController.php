<?php

namespace App\Http\Controllers;

use App\Models\Ubigeo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ConsultaController extends Controller
{
    /**
     * Consulta un RUC en SUNAT via Decolecta. Cachea los aciertos 24h para no gastar el plan.
     */
    public function ruc(string $numero): JsonResponse
    {
        $claveCache = "consulta.ruc.{$numero}";
        $datos = Cache::get($claveCache);

        if ($datos === null) {
            try {
                $respuesta = Http::withToken(config('services.decolecta.token'))
                    ->acceptJson()
                    ->timeout(10)
                    ->get(config('services.decolecta.url') . '/sunat/ruc', ['numero' => $numero]);

                // Decolecta responde 422 cuando el RUC no existe en SUNAT
                if ($respuesta->notFound() || $respuesta->unprocessableEntity()) {
                    return response()->json(['message' => 'No se encontró el RUC en SUNAT.'], 404);
                }

                $respuesta->throw();
                $datos = $respuesta->json();
                Cache::put($claveCache, $datos, now()->addDay());
            } catch (ConnectionException) {
                return response()->json(['message' => 'No se pudo conectar con SUNAT. Intenta de nuevo.'], 503);
            } catch (\Throwable) {
                return response()->json(['message' => 'La consulta a SUNAT falló. Completa los datos manualmente.'], 502);
            }
        }

        // SUNAT devuelve "-" en varios campos (tipico en RUC 10 de personas naturales)
        $limpiar = fn (?string $valor): ?string => in_array($valor = trim((string) $valor), ['', '-'], true) ? null : $valor;
        $ubigeo = $limpiar($datos['ubigeo'] ?? null);

        return response()->json([
            'ruc' => $datos['numero_documento'] ?? $numero,
            'razon_social' => $limpiar($datos['razon_social'] ?? null),
            'direccion' => $limpiar($datos['direccion'] ?? null),
            'distrito' => $limpiar($datos['distrito'] ?? null),
            'provincia' => $limpiar($datos['provincia'] ?? null),
            'departamento' => $limpiar($datos['departamento'] ?? null),
            'ubigeo' => preg_match('/^\d{6}$/', (string) $ubigeo) ? $ubigeo : null,
            'estado' => $datos['estado'] ?? null,
            'condicion' => $datos['condicion'] ?? null,
        ]);
    }

    /** Consulta un DNI en RENIEC via Decolecta. Cachea los aciertos 24h. */
    public function dni(string $numero): JsonResponse
    {
        $claveCache = "consulta.dni.{$numero}";
        $datos = Cache::get($claveCache);

        if ($datos === null) {
            try {
                $respuesta = Http::withToken(config('services.decolecta.token'))
                    ->acceptJson()
                    ->timeout(10)
                    ->get(config('services.decolecta.url') . '/reniec/dni', ['numero' => $numero]);

                if ($respuesta->notFound() || $respuesta->unprocessableEntity()) {
                    return response()->json(['message' => 'No se encontró el DNI en RENIEC.'], 404);
                }

                $respuesta->throw();
                $datos = $respuesta->json();
                Cache::put($claveCache, $datos, now()->addDay());
            } catch (ConnectionException) {
                return response()->json(['message' => 'No se pudo conectar con RENIEC. Intenta de nuevo.'], 503);
            } catch (\Throwable) {
                return response()->json(['message' => 'La consulta a RENIEC falló. Completa los datos manualmente.'], 502);
            }
        }

        return response()->json([
            'dni' => $datos['document_number'] ?? $numero,
            'nombre_completo' => $datos['full_name'] ?? trim(($datos['first_name'] ?? '') . ' ' . ($datos['first_last_name'] ?? '') . ' ' . ($datos['second_last_name'] ?? '')),
        ]);
    }

    /** Autocompletado de ubigeos: por texto (distrito/provincia/departamento) o codigo exacto. */
    public function ubigeos(Request $request): JsonResponse
    {
        $codigo = trim((string) $request->query('codigo', ''));
        if ($codigo !== '') {
            $ubigeo = Ubigeo::find($codigo);

            return response()->json($ubigeo ? [$this->formatearUbigeo($ubigeo)] : []);
        }

        $buscar = trim((string) $request->query('buscar', ''));
        if (mb_strlen($buscar) < 2) {
            return response()->json([]);
        }

        $resultados = Ubigeo::query()
            ->where(fn ($q) => $q
                ->where('distrito', 'ilike', "%{$buscar}%")
                ->orWhere('provincia', 'ilike', "%{$buscar}%")
                ->orWhere('departamento', 'ilike', "%{$buscar}%")
                ->orWhere('codigo', 'like', "{$buscar}%"))
            ->orderBy('departamento')
            ->orderBy('provincia')
            ->orderBy('distrito')
            ->limit(10)
            ->get();

        return response()->json($resultados->map(fn ($u) => $this->formatearUbigeo($u))->values());
    }

    private function formatearUbigeo(Ubigeo $ubigeo): array
    {
        $nombre = mb_convert_case(
            mb_strtolower("{$ubigeo->distrito}, {$ubigeo->provincia}, {$ubigeo->departamento}"),
            MB_CASE_TITLE,
            'UTF-8',
        );

        return ['codigo' => trim($ubigeo->codigo), 'etiqueta' => $nombre];
    }
}
