<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Normaliza las fotos de productos para que todas se vean parejas en el POS y los listados:
 * lienzo 4:3 (la proporcion de las tarjetas del POS) con la foto completa centrada sobre
 * fondo blanco, orientacion EXIF corregida y como maximo 800x600. Se guarda en WebP (o JPEG).
 *
 * Una foto muy alta (una botella) o muy ancha (una manguera) ya no se recorta en la tarjeta:
 * se ve entera, con margen blanco a los lados o arriba/abajo.
 * Si el servidor no tiene GD, la imagen se guarda tal cual llega (el navegador ya la optimiza).
 */
class ImagenProductoService
{
    public const ANCHO = 800;

    public const ALTO = 600;

    private const CARPETA = 'productos';

    /** Guarda la imagen subida ya optimizada y devuelve su ruta en el disco public. */
    public function guardar(UploadedFile $archivo): string
    {
        $optimizada = $this->optimizar((string) file_get_contents($archivo->getRealPath()));

        if ($optimizada === null) {
            return $archivo->store(self::CARPETA, 'public');
        }

        [$contenido, $extension] = $optimizada;
        $ruta = self::CARPETA.'/'.Str::uuid().'.'.$extension;
        Storage::disk('public')->put($ruta, $contenido);

        return $ruta;
    }

    /** true si la imagen ya tiene el formato final (para no reprocesarla). */
    public function yaOptimizada(string $contenido): bool
    {
        $info = @getimagesizefromstring($contenido);
        if (! $info) {
            return false;
        }
        [$ancho, $alto] = $info;

        return $ancho <= self::ANCHO && abs($ancho * 3 - $alto * 4) <= 4
            && in_array($info['mime'] ?? '', ['image/webp', 'image/jpeg'], true);
    }

    /**
     * @return array{0: string, 1: string}|null  [binario, extension] o null si no se pudo procesar
     */
    public function optimizar(string $contenido): ?array
    {
        if (! function_exists('imagecreatefromstring')) {
            Log::warning('ImagenProductoService: el servidor no tiene la extension GD; se guarda la imagen original.');

            return null;
        }

        $origen = @imagecreatefromstring($contenido);
        if ($origen === false) {
            return null;
        }

        $origen = $this->corregirOrientacion($origen, $contenido);
        $w = imagesx($origen);
        $h = imagesy($origen);

        // la caja 4:3 mas chica que contiene la foto; se reduce a 800x600 si es mas grande (nunca se agranda)
        $cajaAncho = max($w, $h * 4 / 3);
        $factor = min(1, self::ANCHO / $cajaAncho);
        $lienzoAncho = max(4, (int) round($cajaAncho * $factor));
        $lienzoAlto = max(3, (int) round($lienzoAncho * 3 / 4));
        $nuevoAncho = max(1, (int) round($w * $factor));
        $nuevoAlto = max(1, (int) round($h * $factor));

        $lienzo = imagecreatetruecolor($lienzoAncho, $lienzoAlto);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255)); // PNG transparente -> fondo blanco
        imagealphablending($lienzo, true);
        imagecopyresampled(
            $lienzo, $origen,
            intdiv($lienzoAncho - $nuevoAncho, 2), intdiv($lienzoAlto - $nuevoAlto, 2), 0, 0,
            $nuevoAncho, $nuevoAlto, $w, $h,
        );
        imagedestroy($origen);

        ob_start();
        $webp = function_exists('imagewebp') && imagewebp($lienzo, null, 82);
        if (! $webp) {
            ob_clean();
            imagejpeg($lienzo, null, 85);
        }
        $binario = (string) ob_get_clean();
        imagedestroy($lienzo);

        return [$binario, $webp ? 'webp' : 'jpg'];
    }

    /** Las fotos de celular vienen "echadas" con una marca EXIF de rotacion: se enderezan. */
    private function corregirOrientacion(\GdImage $imagen, string $contenido): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($contenido, "\xFF\xD8")) {
            return $imagen;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contenido));
        $orientacion = (int) ($exif['Orientation'] ?? 1);

        $rotada = match ($orientacion) {
            3 => imagerotate($imagen, 180, 0),
            6 => imagerotate($imagen, -90, 0),
            8 => imagerotate($imagen, 90, 0),
            default => null,
        };

        if ($rotada === null || $rotada === false) {
            return $imagen;
        }
        imagedestroy($imagen);

        return $rotada;
    }
}
