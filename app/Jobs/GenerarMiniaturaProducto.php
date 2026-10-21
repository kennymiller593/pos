<?php

namespace App\Jobs;

use App\Models\Producto;
use App\Services\ImagenProductoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Crea la miniatura de la foto de un producto (la que se ve en las tarjetas de la tienda). */
class GenerarMiniaturaProducto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public readonly string $productoId, public readonly string $foto) {}

    public function handle(ImagenProductoService $imagenes): void
    {
        $producto = Producto::withTrashed()->find($this->productoId);

        // la foto cambió otra vez mientras esperaba en la cola: lo hará el trabajo de la foto nueva
        if (! $producto || $producto->imagen_url !== $this->foto) {
            return;
        }

        $miniatura = $imagenes->miniatura($this->foto, (string) $producto->empresa_id);

        if ($miniatura) {
            // sin eventos: no es un cambio del producto, y no debe volver a disparar este trabajo
            Producto::withTrashed()->whereKey($producto->id)->where('imagen_url', $this->foto)->update(['imagen_miniatura' => $miniatura]);
        }
    }
}
