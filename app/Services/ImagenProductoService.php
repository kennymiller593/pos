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

    /** Disco de las fotos en la nube (si no está configurado, van al disco public del servidor). */
    public const DISCO = 'imagenes';

    /** Cada foto tiene un nombre único que nunca cambia: el navegador y la CDN la pueden guardar un año. */
    private const CACHE = 'public, max-age=31536000, immutable';

    /** Guarda la imagen subida ya optimizada y devuelve la dirección con que se muestra (imagen_url). */
    public function guardar(UploadedFile $archivo, string $empresaId): string
    {
        $original = (string) file_get_contents($archivo->getRealPath());
        $optimizada = $this->optimizar($original);

        [$contenido, $extension] = $optimizada ?? [$original, strtolower($archivo->guessExtension() ?: 'jpg')];

        return $this->subir($contenido, $extension, $empresaId);
    }

    /** Sube un binario ya listo y devuelve su dirección. */
    public function subir(string $contenido, string $extension, string $empresaId): string
    {
        $ruta = $this->carpeta($empresaId).'/'.Str::uuid().'.'.$extension;

        // sin nube configurada se usa el disco publico de siempre
        Storage::disk($this->enNube() ? self::DISCO : 'public')->put($ruta, $contenido, [
            'CacheControl' => self::CACHE,
            'ContentType' => ['webp' => 'image/webp', 'png' => 'image/png'][$extension] ?? 'image/jpeg',
        ]);

        return $this->direccion($ruta);
    }

    /**
     * En la nube cada empresa tiene su carpeta (empresas/{id}/productos): al dar de baja una
     * empresa se borra de una vez, y se puede medir cuánto ocupa. En el servidor siguen juntas.
     */
    public function carpeta(string $empresaId): string
    {
        return $this->enNube() ? "empresas/{$empresaId}/".self::CARPETA : self::CARPETA;
    }

    /** Dirección base de las fotos en la nube que aún no están en la carpeta de su empresa. */
    public function baseSinEmpresa(): string
    {
        return $this->baseNube().'/'.self::CARPETA.'/';
    }

    /** true si las fotos se guardan en la nube (bucket con dirección pública). */
    public function enNube(): bool
    {
        return config('filesystems.disks.'.self::DISCO.'.driver') === 's3' && filled($this->baseNube());
    }

    private function baseNube(): string
    {
        return rtrim((string) config('filesystems.disks.'.self::DISCO.'.url'), '/');
    }

    /** Dirección pública de una ruta del disco: absoluta en la nube, /storage/... en el servidor. */
    public function direccion(string $ruta): string
    {
        return $this->enNube() ? $this->baseNube().'/'.$ruta : '/storage/'.$ruta;
    }

    /**
     * Borra la foto si la guardamos nosotros (en el servidor o en la nube).
     * Una dirección externa pegada a mano no se toca.
     */
    public function eliminar(?string $url): void
    {
        if (blank($url)) {
            return;
        }

        try {
            if (str_starts_with($url, '/storage/')) {
                Storage::disk('public')->delete(substr($url, strlen('/storage/')));
            } elseif ($this->enNube() && str_starts_with($url, $this->baseNube().'/')) {
                Storage::disk(self::DISCO)->delete(substr($url, strlen($this->baseNube()) + 1));
            }
        } catch (\Throwable $e) {
            report($e); // una foto huérfana no debe impedir guardar el producto
        }
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
