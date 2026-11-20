<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\Producto;
use Illuminate\Console\Command;

/**
 * Guarda las direcciones de productos del sistema que la empresa usaba antes de inkaPos,
 * para que la tienda responda /catalogo/{slug-viejo} con un 301 a la página actual del producto
 * (Google ya tenía esas direcciones; sin esto dan 404 y se pierde su posición).
 *
 * Recibe un CSV con cabecera y columnas slug, nombre (el nombre que tenía en el sistema anterior).
 * Empareja por nombre (sin mayúsculas, tildes ni signos), igual que la importación de descripciones.
 * Sin --ejecutar no escribe nada: muestra qué haría.
 */
class CargarSlugsAnteriores extends Command
{
    protected $signature = 'productos:slugs-anteriores
        {ruc : RUC de la empresa en inkaPos}
        {archivo : CSV con cabecera y columnas slug, nombre}
        {--ejecutar : Guarda las direcciones. Sin esta opción solo simula y muestra el resultado}';

    protected $description = 'Guarda las direcciones de productos del sistema anterior para redirigirlas (301) en la tienda';

    public function handle(): int
    {
        $empresa = Empresa::where('ruc', $this->argument('ruc'))->first();
        if (! $empresa) {
            $this->error("No hay una empresa con RUC {$this->argument('ruc')}.");

            return self::FAILURE;
        }

        $filas = $this->leerArchivo((string) $this->argument('archivo'), $descartadas);
        if ($filas === null) {
            return self::FAILURE;
        }
        foreach ($descartadas as $linea) {
            $this->warn("Descartada (dirección con caracteres no válidos o sin nombre): {$linea}");
        }

        $ejecutar = (bool) $this->option('ejecutar');
        $this->line(($ejecutar ? 'GUARDANDO' : 'SIMULACIÓN (no se guarda nada)')." · {$empresa->razon_social} · ".count($filas).' direcciones en el archivo');

        $productos = Producto::query()->where('empresa_id', $empresa->id)->get(['id', 'nombre', 'slug', 'activo', 'en_tienda']);
        $porNombre = $productos->groupBy(fn (Producto $p) => ImportarImagenesProductos::normalizar((string) $p->nombre));
        $actuales = $productos->pluck('slug')->filter()->flip();

        $resultado = ['guardadas' => [], 'vigentes' => [], 'sin_pareja' => [], 'ambiguas' => []];

        foreach ($filas as [$slug, $nombre]) {
            if ($actuales->has($slug)) {
                $resultado['vigentes'][] = $slug;

                continue;
            }

            $pareja = $porNombre->get(ImportarImagenesProductos::normalizar($nombre), collect());
            // con nombres repetidos manda el que se ve en la tienda (un duplicado viejo inactivo no estorba)
            if ($pareja->count() > 1) {
                $pareja = $pareja->filter(fn (Producto $p) => $p->activo && $p->en_tienda)->values();
            }
            if ($pareja->count() !== 1) {
                $resultado[$pareja->isEmpty() ? 'sin_pareja' : 'ambiguas'][] = "{$slug} ({$nombre})";

                continue;
            }

            $producto = $pareja->first();
            if ($ejecutar) {
                Producto::recordarSlugAnterior((string) $empresa->id, (string) $producto->id, $slug);
            }
            $resultado['guardadas'][] = "{$slug} → {$producto->slug}";
        }

        $etiquetas = [
            'guardadas' => $ejecutar ? 'Direcciones guardadas' : 'Direcciones que se guardarían',
            'vigentes' => 'Ya son la dirección actual de un producto (no hace falta)',
            'sin_pareja' => 'Sin producto con ese nombre en inkaPos',
            'ambiguas' => 'Más de un producto con ese nombre (no se guardan)',
        ];
        foreach ($etiquetas as $clave => $etiqueta) {
            $this->line("{$etiqueta}: ".count($resultado[$clave]));
            if (in_array($clave, ['sin_pareja', 'ambiguas'], true) || $this->output->isVerbose()) {
                foreach ($resultado[$clave] as $linea) {
                    $this->line("    · {$linea}");
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>|null  $descartadas  filas que no se pueden usar, para avisarlas
     * @return list<array{0: string, 1: string}>|null filas [slug, nombre]
     */
    private function leerArchivo(string $ruta, ?array &$descartadas = null): ?array
    {
        $descartadas = [];
        if (! is_file($ruta) || ! ($archivo = fopen($ruta, 'r'))) {
            $this->error("No se puede leer {$ruta}.");

            return null;
        }

        $cabecera = array_map(fn ($c) => strtolower(trim((string) $c, " \t\n\r\0\x0B\u{FEFF}")), fgetcsv($archivo, escape: '') ?: []);
        $iSlug = array_search('slug', $cabecera, true);
        $iNombre = array_search('nombre', $cabecera, true);
        if ($iSlug === false || $iNombre === false) {
            fclose($archivo);
            $this->error('El archivo debe tener cabecera con las columnas slug y nombre.');

            return null;
        }

        $filas = [];
        while (($fila = fgetcsv($archivo, escape: '')) !== false) {
            $slug = strtolower(trim((string) ($fila[$iSlug] ?? '')));
            $nombre = trim((string) ($fila[$iNombre] ?? ''));
            if (preg_match('/^[a-z0-9_-]+$/', $slug) && $nombre !== '') {
                $filas[$slug] = [$slug, $nombre];
            } elseif ($slug !== '' || $nombre !== '') {
                $descartadas[] = "{$slug} ({$nombre})";
            }
        }
        fclose($archivo);

        return array_values($filas);
    }
}
