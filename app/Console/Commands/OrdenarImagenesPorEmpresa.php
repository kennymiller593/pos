<?php

namespace App\Console\Commands;

use App\Services\ImagenProductoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Mueve las fotos que quedaron en la carpeta común de la nube (productos/) a la carpeta de su
 * empresa (empresas/{id}/productos/) y actualiza la dirección de cada producto.
 *
 * Orden seguro: copiar -> actualizar el producto -> borrar el original recién al final,
 * así ninguna foto deja de verse mientras corre. Se puede repetir sin daño.
 */
class OrdenarImagenesPorEmpresa extends Command
{
    protected $signature = 'productos:imagenes-por-empresa';

    protected $description = 'Mueve las fotos de productos en la nube a una carpeta por empresa';

    public function handle(ImagenProductoService $imagenes): int
    {
        if (! $imagenes->enNube()) {
            $this->warn('Las fotos en la nube no están configuradas (IMAGENES_BUCKET e IMAGENES_URL en .env): no se hizo nada.');

            return self::SUCCESS;
        }

        $nube = Storage::disk(ImagenProductoService::DISCO);
        $base = $imagenes->baseSinEmpresa(); // https://img.../productos/

        $productos = DB::table('productos')
            ->where('imagen_url', 'like', $base.'%')
            ->get(['id', 'empresa_id', 'imagen_url']);

        $copiadas = [];   // empresa|archivo => dirección nueva
        $originales = []; // rutas viejas ya copiadas
        $faltantes = 0;

        foreach ($productos as $producto) {
            $archivo = Str::after($producto->imagen_url, $base);
            $origen = 'productos/'.$archivo;
            $clave = "{$producto->empresa_id}|{$archivo}";

            if (! isset($copiadas[$clave])) {
                $destino = $imagenes->carpeta($producto->empresa_id).'/'.$archivo;

                try {
                    if (! $nube->exists($destino)) {
                        if (! $nube->exists($origen)) {
                            $faltantes++;

                            continue;
                        }
                        $nube->copy($origen, $destino);
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $this->error("No se pudo mover {$origen}: {$e->getMessage()}");

                    return self::FAILURE;
                }

                $copiadas[$clave] = $imagenes->direccion($destino);
                $originales[$origen] = true;
            }

            DB::table('productos')->where('id', $producto->id)->update(['imagen_url' => $copiadas[$clave]]);
        }

        // los originales se borran cuando ya ningún producto apunta a ellos
        $borradas = 0;
        foreach (array_keys($originales) as $origen) {
            if (! DB::table('productos')->where('imagen_url', $imagenes->direccion($origen))->exists()) {
                $nube->delete($origen);
                $borradas++;
            }
        }

        $this->info('Fotos movidas a la carpeta de su empresa: '.count($copiadas).'. Productos actualizados: '.($productos->count() - $faltantes).'.'
            .($faltantes ? " Sin archivo en la nube (no se tocaron): {$faltantes}." : ''));

        return self::SUCCESS;
    }
}
