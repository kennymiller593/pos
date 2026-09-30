<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Presentaciones que quedaron con el mismo nombre del producto (el formulario tenía dos
 * campos "Nombre" y el dueño repetía el del producto): se renombran con la unidad y el
 * factor, igual que hoy las nombra el formulario ("Unidad", "Caja x12"). Idempotente.
 */
class NormalizarPresentaciones extends Command
{
    protected $signature = 'productos:normalizar-presentaciones';

    protected $description = 'Renombra las presentaciones que repiten el nombre del producto (Unidad, Caja x12...)';

    public function handle(): int
    {
        $filas = DB::table('producto_presentaciones as pp')
            ->join('productos as p', 'p.id', '=', 'pp.producto_id')
            ->join('unidades_medida as u', 'u.codigo', '=', 'pp.unidad_codigo')
            ->whereRaw('lower(trim(pp.nombre)) = lower(trim(p.nombre))')
            ->get(['pp.id', 'pp.producto_id', 'pp.factor_conversion', 'u.nombre as unidad']);

        $renombradas = 0;
        foreach ($filas as $fila) {
            $factor = (float) $fila->factor_conversion;
            $nombre = $factor > 0 && $factor != 1 ? "{$fila->unidad} x".rtrim(rtrim(number_format($factor, 4, '.', ''), '0'), '.') : $fila->unidad;

            $ocupado = DB::table('producto_presentaciones')
                ->where('producto_id', $fila->producto_id)->where('nombre', $nombre)->where('id', '!=', $fila->id)->exists();
            if ($ocupado) {
                $this->warn("Presentación {$fila->id}: ya existe otra llamada \"{$nombre}\" en el mismo producto, se deja igual.");

                continue;
            }

            DB::table('producto_presentaciones')->where('id', $fila->id)->update(['nombre' => $nombre]);
            $renombradas++;
        }

        $this->info("Presentaciones renombradas: {$renombradas}");

        return self::SUCCESS;
    }
}
