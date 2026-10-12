<?php

namespace App\Services\IA;

use App\IA\SondaIa;
use App\Models\ProveedorIa;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\Provider;
use Throwable;

/** Construye los proveedores con la clave guardada en la base y lleva su estado de salud (el "fusible"). */
class ProveedorIaService
{
    /** Segundos máximos por intento al probar un proveedor. */
    public const TIMEOUT_PRUEBA = 20;

    /** Instancia del proveedor para el SDK, con la clave y la URL guardadas por el superadmin. */
    public function construir(ProveedorIa $proveedor): Provider
    {
        $config = [
            // el prefijo evita chocar con los nombres reservados de config/ai.php
            'name' => 'ia_'.$proveedor->codigo,
            'driver' => $proveedor->driver,
            'key' => (string) $proveedor->api_key,
        ];
        if (filled($proveedor->url)) {
            $config['url'] = rtrim($proveedor->url, '/');
        }

        return Ai::build($config);
    }

    /**
     * Manda una consulta mínima y devuelve cómo le fue.
     *
     * @return array{ok: bool, mensaje: string, ms: int, modelo: ?string, tokens: ?int}
     */
    public function probar(ProveedorIa $proveedor, string $modelo): array
    {
        $inicio = hrtime(true);
        try {
            $respuesta = (new SondaIa)->prompt('Di OK', provider: $this->construir($proveedor), model: $modelo, timeout: self::TIMEOUT_PRUEBA);
            $ms = (int) ((hrtime(true) - $inicio) / 1_000_000);
            $this->registrarExito($proveedor);

            return [
                'ok' => true,
                'mensaje' => 'Respondió "'.trim(mb_substr($respuesta->text, 0, 40)).'"',
                'ms' => $ms,
                'modelo' => $respuesta->meta->model ?: $modelo,
                'tokens' => $respuesta->usage->inputTokens + $respuesta->usage->outputTokens,
            ];
        } catch (Throwable $e) {
            $ms = (int) ((hrtime(true) - $inicio) / 1_000_000);
            $this->registrarFallo($proveedor, $e);

            return ['ok' => false, 'mensaje' => self::resumirError($e), 'ms' => $ms, 'modelo' => $modelo, 'tokens' => null];
        }
    }

    public function registrarExito(ProveedorIa $proveedor): void
    {
        $proveedor->forceFill(['ultimo_ok_en' => now(), 'fallos_seguidos' => 0, 'pausado_hasta' => null])->save();
    }

    /** Anota el fallo; al tercero seguido el proveedor queda en pausa unos minutos y la cadena lo salta. */
    public function registrarFallo(ProveedorIa $proveedor, Throwable $e): void
    {
        $fallos = $proveedor->fallos_seguidos + 1;
        $proveedor->forceFill([
            'ultimo_error' => self::resumirError($e),
            'ultimo_error_en' => now(),
            'fallos_seguidos' => $fallos,
            'pausado_hasta' => $fallos >= ProveedorIa::FALLOS_PARA_PAUSAR ? now()->addMinutes(ProveedorIa::MINUTOS_DE_PAUSA) : $proveedor->pausado_hasta,
        ])->save();
    }

    /** Mensaje corto y sin secretos para guardar y mostrar. */
    public static function resumirError(Throwable $e): string
    {
        $mensaje = $e->getMessage();
        // las excepciones HTTP traen el cuerpo entero; con el estado y el inicio basta
        $mensaje = preg_replace('/\s+/', ' ', $mensaje);
        $mensaje = preg_replace('/(sk-|Bearer\s+)[A-Za-z0-9_\-]{6,}/', '$1•••', $mensaje);

        return mb_substr(class_basename($e).': '.$mensaje, 0, 300);
    }
}
