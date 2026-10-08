<?php

namespace App\Services;

use App\Models\Empresa;
use App\Support\Tienda;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lo que personaliza la portada de una tienda: la foto que sube el dueño y la vista previa
 * de sus cambios antes de guardarlos.
 */
class PortadaTiendaService
{
    /** La foto de portada se ve a todo el ancho: se guarda como máximo a este tamaño. */
    public const ANCHO = 1600;

    public const ALTO = 1200;

    /** La misma foto para el celular, donde 900 px de ancho sobran. */
    public const ANCHO_MOVIL = 900;

    /** Minutos que dura un enlace de vista previa. */
    public const MINUTOS_PREVIA = 30;

    private const CACHE = 'public, max-age=31536000, immutable';

    public function __construct(private readonly ImagenProductoService $imagenes) {}

    /** Guarda una foto (banner) optimizada y devuelve su dirección. */
    public function guardar(UploadedFile $archivo, Empresa $empresa): string
    {
        return $this->subir($this->preparar($archivo), $empresa);
    }

    /**
     * La foto de portada en dos tamaños: grande (pantallas anchas) y para el celular.
     *
     * @return array{0: string, 1: string} direcciones de la grande y de la móvil
     */
    public function guardarPortada(UploadedFile $archivo, Empresa $empresa): array
    {
        return [
            $this->subir($this->preparar($archivo, self::ANCHO, self::ALTO, 72), $empresa),
            $this->subir($this->preparar($archivo, self::ANCHO_MOVIL, self::ALTO, 70), $empresa),
        ];
    }

    /** @param  array{0: string, 1: string}  $preparada  binario y extensión */
    private function subir(array $preparada, Empresa $empresa): string
    {
        [$contenido, $extension] = $preparada;

        $carpeta = $this->imagenes->enNube() ? "empresas/{$empresa->id}/tienda" : 'tienda';
        $ruta = $carpeta.'/'.Str::uuid().'.'.$extension;

        Storage::disk($this->imagenes->enNube() ? ImagenProductoService::DISCO : 'public')->put($ruta, $contenido, [
            'CacheControl' => self::CACHE,
            'ContentType' => ['webp' => 'image/webp', 'png' => 'image/png'][$extension] ?? 'image/jpeg',
        ]);

        return $this->imagenes->direccion($ruta);
    }

    /**
     * La foto lista para incrustarse en la página (para la vista previa): no se guarda en ningún
     * lado, así una foto que el dueño probó y descartó no queda ocupando espacio.
     */
    public function incrustada(UploadedFile $archivo): string
    {
        [$contenido, $extension] = $this->preparar($archivo);

        return 'data:'.(['webp' => 'image/webp', 'png' => 'image/png'][$extension] ?? 'image/jpeg').';base64,'.base64_encode($contenido);
    }

    /** Lado del ícono de la pestaña: cuadrado, suficiente para pestañas, favoritos y pantalla de inicio del celular. */
    public const ICONO = 256;

    /** Guarda el ícono de la pestaña como PNG cuadrado y devuelve su dirección. */
    public function guardarIcono(UploadedFile $archivo, Empresa $empresa): string
    {
        $carpeta = $this->imagenes->enNube() ? "empresas/{$empresa->id}/tienda" : 'tienda';
        $ruta = $carpeta.'/'.Str::uuid().'.png';

        Storage::disk($this->imagenes->enNube() ? ImagenProductoService::DISCO : 'public')->put($ruta, $this->prepararIcono($archivo), [
            'CacheControl' => self::CACHE,
            'ContentType' => 'image/png',
        ]);

        return $this->imagenes->direccion($ruta);
    }

    /** El ícono listo para incrustarse en la página (vista previa, sin guardarlo). */
    public function iconoIncrustado(UploadedFile $archivo): string
    {
        return 'data:image/png;base64,'.base64_encode($this->prepararIcono($archivo));
    }

    /**
     * Cualquier imagen pasa a un PNG cuadrado de 256 px: se encaja entera (sin recortar) sobre
     * fondo transparente, así un logo apaisado no se deforma.
     */
    private function prepararIcono(UploadedFile $archivo): string
    {
        $original = (string) file_get_contents($archivo->getRealPath());
        $imagen = function_exists('imagecreatefromstring') ? @imagecreatefromstring($original) : false;

        if (! $imagen) {
            return $original;
        }

        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $factor = min(self::ICONO / $ancho, self::ICONO / $alto);
        $nuevoAncho = max(1, (int) round($ancho * $factor));
        $nuevoAlto = max(1, (int) round($alto * $factor));

        $lienzo = imagecreatetruecolor(self::ICONO, self::ICONO);
        imagealphablending($lienzo, false);
        imagesavealpha($lienzo, true);
        imagefill($lienzo, 0, 0, imagecolorallocatealpha($lienzo, 0, 0, 0, 127));
        imagecopyresampled($lienzo, $imagen, (int) ((self::ICONO - $nuevoAncho) / 2), (int) ((self::ICONO - $nuevoAlto) / 2), 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
        imagedestroy($imagen);

        ob_start();
        imagepng($lienzo, null, 6);
        imagedestroy($lienzo);

        return (string) ob_get_clean();
    }

    /** Borra una foto de portada guardada por nosotros. */
    public function eliminar(?string $url): void
    {
        $this->imagenes->eliminar($url);
    }

    /**
     * Reduce la foto a lo que cabe en una pantalla grande, sin recortarla ni agrandarla,
     * y la pasa a WebP. Una foto del celular de 6 MB queda en unos cientos de KB.
     *
     * @return array{0: string, 1: string} binario y extensión
     */
    private function preparar(UploadedFile $archivo, int $maxAncho = self::ANCHO, int $maxAlto = self::ALTO, int $calidad = 80): array
    {
        $original = (string) file_get_contents($archivo->getRealPath());
        $imagen = function_exists('imagecreatefromstring') ? @imagecreatefromstring($original) : false;

        if (! $imagen) {
            return [$original, strtolower($archivo->guessExtension() ?: 'jpg')];
        }

        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $factor = min(1, $maxAncho / $ancho, $maxAlto / $alto);

        if ($factor < 1) {
            $reducida = imagecreatetruecolor(max(1, (int) round($ancho * $factor)), max(1, (int) round($alto * $factor)));
            imagefill($reducida, 0, 0, imagecolorallocate($reducida, 255, 255, 255));
            imagecopyresampled($reducida, $imagen, 0, 0, 0, 0, imagesx($reducida), imagesy($reducida), $ancho, $alto);
            imagedestroy($imagen);
            $imagen = $reducida;
        }

        ob_start();
        $webp = function_exists('imagewebp') && imagewebp($imagen, null, $calidad);
        if (! $webp) {
            ob_clean();
            imagejpeg($imagen, null, $calidad + 2);
        }
        $binario = (string) ob_get_clean();
        imagedestroy($imagen);

        return [$binario, $webp ? 'webp' : 'jpg'];
    }

    // ---------------- vista previa ----------------

    /**
     * Guarda un borrador de la tienda por unos minutos y devuelve el enlace para verlo.
     * Solo quien tenga el enlace lo ve: los visitantes siguen viendo la tienda tal como está guardada.
     */
    public function crearVistaPrevia(Empresa $empresa, string $slug, array $config): string
    {
        $clave = Str::random(40);

        Cache::put($this->llave($clave), ['empresa_id' => $empresa->id, 'slug' => $slug, 'config' => $config], now()->addMinutes(self::MINUTOS_PREVIA));

        return Tienda::url($slug).'?previa='.$clave;
    }

    /** El borrador de esa clave, si existe y es de la tienda de esa dirección. */
    public function vistaPrevia(?string $clave, string $slug): ?array
    {
        if (blank($clave) || ! preg_match('/^[A-Za-z0-9]{40}$/', (string) $clave)) {
            return null;
        }

        $borrador = Cache::get($this->llave($clave));

        return is_array($borrador) && ($borrador['slug'] ?? null) === $slug ? $borrador : null;
    }

    private function llave(string $clave): string
    {
        return "tienda:previa:{$clave}";
    }
}
