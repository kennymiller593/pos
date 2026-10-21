<?php

namespace App\Console\Commands;

use App\Jobs\GenerarMiniaturaProducto;
use App\Models\Producto;
use Illuminate\Console\Command;

/** Crea las miniaturas de las fotos que se subieron antes de que existieran (o que fallaron). */
class GenerarMiniaturasProductos extends Command
{
    protected $signature = 'productos:miniaturas {--empresa= : Solo los productos de esta empresa (id)}';

    protected $description = 'Genera la miniatura (400x300) de las fotos de productos que aún no la tienen';

    public function handle(): int
    {
        $consulta = Producto::query()
            ->whereNotNull('imagen_url')
            ->whereNull('imagen_miniatura')
            ->when($this->option('empresa'), fn ($q, $empresa) => $q->where('empresa_id', $empresa));

        $total = (clone $consulta)->count();
        $this->info("Fotos sin miniatura: {$total}");

        $barra = $this->output->createProgressBar($total);
        $consulta->select(['id', 'imagen_url'])->orderBy('id')->chunkById(100, function ($productos) use ($barra) {
            foreach ($productos as $producto) {
                GenerarMiniaturaProducto::dispatchSync($producto->id, $producto->imagen_url);
                $barra->advance();
            }
        });
        $barra->finish();
        $this->newLine();

        $this->info('Quedan sin miniatura: '.(clone $consulta)->count().' (fotos externas o que no se pudieron leer)');

        return self::SUCCESS;
    }
}
