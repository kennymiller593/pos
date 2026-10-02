<?php

namespace App\Console\Commands;

use App\Services\ImagenProductoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pasa a la nube las fotos de productos que ya estaban en el servidor y actualiza su dirección.
 * Se puede repetir: solo toma las que siguen apuntando a /storage/productos/.
 * Los archivos locales no se borran salvo que se pida (--borrar-locales), para poder volver atrás.
 */
class SubirImagenesANube extends Command
{
    protected $signature = 'productos:imagenes-a-nube {--borrar-locales : Borra del servidor cada foto ya subida}';

    protected $description = 'Sube a la nube las fotos de productos guardadas en el servidor';

    public function handle(ImagenProductoService $imagenes): int
    {
        if (! $imagenes->enNube()) {
            $this->warn('Las fotos en la nube no están configuradas (IMAGENES_BUCKET e IMAGENES_URL en .env): no se hizo nada.');

            return self::SUCCESS;
        }

        $local = Storage::disk('public');
        $subidas = 0;
        $faltantes = 0;
        $hechas = []; // empresa + ruta local => dirección nueva (un mismo archivo puede estar en varios productos)
        $rutasSubidas = [];

        $productos = DB::table('productos')
            ->where('imagen_url', 'like', '/storage/productos/%')
            ->get(['id', 'empresa_id', 'imagen_url']);

        foreach ($productos as $producto) {
            $ruta = Str::after($producto->imagen_url, '/storage/');
            $clave = "{$producto->empresa_id}|{$ruta}"; // cada empresa recibe su copia en su carpeta

            if (! isset($hechas[$clave])) {
                if (! $local->exists($ruta)) {
                    $faltantes++;

                    continue;
                }

                try {
                    $hechas[$clave] = $imagenes->subir($local->get($ruta), strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) ?: 'jpg', $producto->empresa_id);
                    $rutasSubidas[$ruta] = true;
                } catch (\Throwable $e) {
                    report($e);
                    $this->error("No se pudo subir {$ruta}: {$e->getMessage()}");

                    return self::FAILURE;
                }

                $subidas++;
            }

            DB::table('productos')->where('id', $producto->id)->update(['imagen_url' => $hechas[$clave]]);
        }

        if ($this->option('borrar-locales')) {
            $local->delete(array_keys($rutasSubidas));
        }

        $this->info("Fotos subidas a la nube: {$subidas}. Productos actualizados: ".($productos->count() - $faltantes).'.'
            .($faltantes ? " Sin archivo en el servidor (no se tocaron): {$faltantes}." : ''));

        return self::SUCCESS;
    }
}
