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
    public const ANCHO = 1920;

    public const ALTO = 1200;

    /** Minutos que dura un enlace de vista previa. */
    public const MINUTOS_PREVIA = 30;

    private const CACHE = 'public, max-age=31536000, immutable';

    public function __construct(private readonly ImagenProductoService $imagenes) {}

    /** Guarda la foto de portada (ya optimizada) y devuelve su dirección. */
    public function guardar(UploadedFile $archivo, Empresa $empresa): string
    {
        [$contenido, $extension] = $this->preparar($archivo);

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
    private function preparar(UploadedFile $archivo): array
    {
        $original = (string) file_get_contents($archivo->getRealPath());
        $imagen = function_exists('imagecreatefromstring') ? @imagecreatefromstring($original) : false;

        if (! $imagen) {
            return [$original, strtolower($archivo->guessExtension() ?: 'jpg')];
        }

        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $factor = min(1, self::ANCHO / $ancho, self::ALTO / $alto);

        if ($factor < 1) {
            $reducida = imagecreatetruecolor(max(1, (int) round($ancho * $factor)), max(1, (int) round($alto * $factor)));
            imagefill($reducida, 0, 0, imagecolorallocate($reducida, 255, 255, 255));
            imagecopyresampled($reducida, $imagen, 0, 0, 0, 0, imagesx($reducida), imagesy($reducida), $ancho, $alto);
            imagedestroy($imagen);
            $imagen = $reducida;
        }

        ob_start();
        $webp = function_exists('imagewebp') && imagewebp($imagen, null, 80);
        if (! $webp) {
            ob_clean();
            imagejpeg($imagen, null, 82);
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
