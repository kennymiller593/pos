<?php

namespace App\Console\Commands;

use App\Services\ImagenProductoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pasa por ImagenProductoService las fotos de productos subidas antes de que existiera
 * la optimizacion (4:3, fondo blanco, max 800x600). Es idempotente: las que ya tienen
 * el formato final se dejan igual, asi que puede correr en cada despliegue.
 */
class OptimizarImagenesProductos extends Command
{
    protected $signature = 'productos:optimizar-imagenes';

    protected $description = 'Optimiza las fotos de productos ya subidas (proporción 4:3, máx. 800x600, WebP)';

    public function handle(ImagenProductoService $imagenes): int
    {
        if (! function_exists('imagecreatefromstring')) {
            $this->warn('El servidor no tiene la extensión GD de PHP: no se optimizaron imágenes.');

            return self::SUCCESS;
        }

        $disco = Storage::disk('public');
        $optimizadas = 0;
        $hechas = []; // ruta original => url nueva (un mismo archivo puede estar en varios productos)

        $productos = DB::table('productos')
            ->whereNotNull('imagen_url')
            ->where('imagen_url', 'like', '/storage/productos/%')
            ->get(['id', 'imagen_url']);

        foreach ($productos as $producto) {
            $ruta = Str::after($producto->imagen_url, '/storage/');
            if (isset($hechas[$ruta])) {
                DB::table('productos')->where('id', $producto->id)->update(['imagen_url' => $hechas[$ruta]]);

                continue;
            }
            if (! $disco->exists($ruta)) {
                continue;
            }

            $contenido = $disco->get($ruta);
            if ($imagenes->yaOptimizada($contenido)) {
                continue;
            }

            $resultado = $imagenes->optimizar($contenido);
            if ($resultado === null) {
                $this->warn("No se pudo procesar {$ruta}");

                continue;
            }

            [$binario, $extension] = $resultado;
            $nueva = 'productos/'.Str::uuid().'.'.$extension;
            $disco->put($nueva, $binario);
            $hechas[$ruta] = Storage::url($nueva);
            DB::table('productos')->where('id', $producto->id)->update(['imagen_url' => $hechas[$ruta]]);
            $optimizadas++;
        }

        $disco->delete(array_keys($hechas));

        $this->info("Imágenes de productos optimizadas: {$optimizadas}");

        return self::SUCCESS;
    }
}
