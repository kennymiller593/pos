<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\Sucursal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * QR de cobro de las billeteras (Yape, Plin): el que el cajero le muestra al cliente en el POS.
 * Cada empresa sube el suyo; una sucursal puede tener uno propio, que manda sobre el de la empresa.
 */
class CobroQrService
{
    /** Billeteras que cobran con QR (códigos del catálogo de medios de pago). */
    public const BILLETERAS = ['yape' => 'Yape', 'plin' => 'Plin'];

    private const CARPETA = 'cobros-qr';

    /** Lado mayor de la imagen guardada: de sobra para leerla desde otra pantalla. */
    private const LADO = 900;

    /**
     * Lo que usa una sucursal: su propio QR si lo tiene, si no el de la empresa.
     *
     * @return array<string, array{qr_url: ?string, numero: ?string, titular: ?string}|null>
     */
    public function de(Empresa $empresa, ?Sucursal $sucursal = null): array
    {
        $resultado = [];
        foreach (array_keys(self::BILLETERAS) as $medio) {
            $resultado[$medio] = $this->limpio($sucursal?->cobros_qr[$medio] ?? null)
                ?? $this->limpio($empresa->cobros_qr[$medio] ?? null);
        }

        return $resultado;
    }

    /**
     * Lo guardado tal cual en un ámbito (empresa o sucursal), sin heredar nada.
     *
     * @return array<string, array{qr_url: ?string, numero: ?string, titular: ?string}|null>
     */
    public function propios(Empresa|Sucursal $ambito): array
    {
        $resultado = [];
        foreach (array_keys(self::BILLETERAS) as $medio) {
            $resultado[$medio] = $this->limpio($ambito->cobros_qr[$medio] ?? null);
        }

        return $resultado;
    }

    /**
     * Guarda los datos de una billetera en un ámbito. Si queda todo vacío, se quita
     * (en una sucursal eso significa "usar el de la empresa").
     *
     * @param  array{numero?: ?string, titular?: ?string}  $datos
     */
    public function guardar(Empresa|Sucursal $ambito, string $medio, array $datos, ?UploadedFile $imagen, bool $quitarImagen): void
    {
        $config = $ambito->cobros_qr ?? [];
        $actual = $config[$medio] ?? [];
        $qr = $actual['qr_url'] ?? null;

        if ($imagen) {
            $nueva = $this->guardarImagen($imagen);
            $this->eliminarImagen($qr);
            $qr = $nueva;
        } elseif ($quitarImagen) {
            $this->eliminarImagen($qr);
            $qr = null;
        }

        $entrada = $this->limpio([
            'qr_url' => $qr,
            'numero' => $datos['numero'] ?? null,
            'titular' => $datos['titular'] ?? null,
        ]);

        if ($entrada) {
            $config[$medio] = $entrada;
        } else {
            unset($config[$medio]);
        }

        $ambito->update(['cobros_qr' => $config ?: null]);
    }

    /** Reduce la imagen (sin agrandarla) y la guarda como PNG para que el QR no pierda nitidez. */
    private function guardarImagen(UploadedFile $archivo): string
    {
        $contenido = (string) file_get_contents($archivo->getRealPath());
        $extension = strtolower($archivo->guessExtension() ?: 'png');

        $origen = function_exists('imagecreatefromstring') ? @imagecreatefromstring($contenido) : false;
        if ($origen !== false) {
            $w = imagesx($origen);
            $h = imagesy($origen);
            $factor = min(1, self::LADO / max($w, $h));
            $nuevoAncho = max(1, (int) round($w * $factor));
            $nuevoAlto = max(1, (int) round($h * $factor));

            $lienzo = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
            imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255)); // transparente -> fondo blanco
            imagecopyresampled($lienzo, $origen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $w, $h);
            imagedestroy($origen);

            ob_start();
            imagepng($lienzo, null, 9);
            $contenido = (string) ob_get_clean();
            imagedestroy($lienzo);
            $extension = 'png';
        }

        $ruta = self::CARPETA.'/'.Str::uuid().'.'.$extension;
        Storage::disk('public')->put($ruta, $contenido);

        return Storage::url($ruta);
    }

    /** Borra el archivo solo si fue subido a nuestro almacenamiento. */
    private function eliminarImagen(?string $url): void
    {
        if ($url && str_starts_with($url, '/storage/'.self::CARPETA.'/')) {
            Storage::disk('public')->delete(substr($url, strlen('/storage/')));
        }
    }

    /** @return array{qr_url: ?string, numero: ?string, titular: ?string}|null null si no hay nada que mostrar */
    private function limpio(mixed $entrada): ?array
    {
        if (! is_array($entrada)) {
            return null;
        }

        $limpio = [
            'qr_url' => filled($entrada['qr_url'] ?? null) ? (string) $entrada['qr_url'] : null,
            'numero' => filled($entrada['numero'] ?? null) ? (string) $entrada['numero'] : null,
            'titular' => filled($entrada['titular'] ?? null) ? trim((string) $entrada['titular']) : null,
        ];

        return $limpio['qr_url'] || $limpio['numero'] ? $limpio : null;
    }
}
