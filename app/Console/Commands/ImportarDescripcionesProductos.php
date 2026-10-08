<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\Producto;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Trae las descripciones de productos de otro sistema (p. ej. el que usaba el cliente antes de inkaPos).
 *
 * Recibe un CSV con columnas nombre, descripcion (corta) y descripcion_largo (puede venir en HTML).
 * Empareja por nombre (sin mayúsculas, tildes ni espacios raros), arma un texto plano con la frase
 * corta y el texto largo, y lo guarda como descripción del producto (la que sale en la tienda).
 *
 * Solo llena descripciones vacías, salvo --sobrescribir. Sin --ejecutar no escribe nada: muestra qué haría.
 */
class ImportarDescripcionesProductos extends Command
{
    protected $signature = 'productos:importar-descripciones
        {ruc : RUC de la empresa en inkaPos}
        {archivo : CSV con cabecera y columnas nombre, descripcion, descripcion_largo}
        {--ejecutar : Guarda las descripciones. Sin esta opción solo simula y muestra el resultado}
        {--sobrescribir : También reemplaza las descripciones que ya tienen texto}
        {--max=3000 : Largo máximo de la descripción (se corta en un párrafo o una oración)}
        {--detalle : Lista cada producto y lo que se hizo con él}';

    protected $description = 'Importa las descripciones de productos desde otro sistema, emparejando por nombre';

    public function handle(): int
    {
        $empresa = Empresa::where('ruc', $this->argument('ruc'))->first();
        if (! $empresa) {
            $this->error("No hay una empresa con RUC {$this->argument('ruc')}.");

            return self::FAILURE;
        }

        $origen = $this->leerArchivo((string) $this->argument('archivo'));
        if ($origen === null) {
            return self::FAILURE;
        }

        $ejecutar = (bool) $this->option('ejecutar');
        $sobrescribir = (bool) $this->option('sobrescribir');
        $max = max(200, (int) $this->option('max'));

        $this->line(($ejecutar ? 'IMPORTANDO' : 'SIMULACIÓN (no se guarda nada)')." · {$empresa->razon_social} · {$origen->count()} nombres en el archivo");

        $resultado = ['importadas' => [], 'ya_tenian' => [], 'sin_pareja' => [], 'sin_texto' => []];

        $productos = Producto::query()->where('empresa_id', $empresa->id)->orderBy('nombre')->orderBy('id')->get();

        foreach ($productos as $producto) {
            $texto = $origen->get(ImportarImagenesProductos::normalizar($producto->nombre));

            $clave = match (true) {
                $texto === null => 'sin_pareja',
                $texto === '' => 'sin_texto',
                filled($producto->descripcion) && ! $sobrescribir => 'ya_tenian',
                default => 'importadas',
            };

            if ($clave === 'importadas') {
                $texto = self::recortar($texto, $max);
                if ($ejecutar) {
                    $producto->update(['descripcion' => $texto]);
                }
            }

            $resultado[$clave][] = $producto->nombre.($this->option('detalle') && $clave === 'importadas' ? ' → '.Str::limit(Str::squish($texto), 90) : '');
        }

        $this->informar($resultado, $ejecutar);

        return self::SUCCESS;
    }

    /**
     * El archivo del otro sistema: nombre normalizado => texto ya armado (o '' si ese producto no tenía nada).
     * Si un nombre se repite, se queda el texto más largo.
     *
     * @return Collection<string, string>|null
     */
    private function leerArchivo(string $ruta): ?Collection
    {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            $this->error("No se puede leer el archivo {$ruta}.");

            return null;
        }

        $lector = fopen($ruta, 'r');
        $cabecera = fgetcsv($lector, escape: '');
        $columnas = array_map(fn ($c) => ImportarImagenesProductos::normalizar((string) $c), $cabecera ?: []);
        $indice = fn (string $nombre) => array_search($nombre, $columnas, true);

        if ($indice('nombre') === false) {
            fclose($lector);
            $this->error('El archivo debe tener cabecera con las columnas nombre, descripcion y descripcion_largo.');

            return null;
        }

        $lista = collect();
        while (($fila = fgetcsv($lector, escape: '')) !== false) {
            $nombre = ImportarImagenesProductos::normalizar((string) ($fila[$indice('nombre')] ?? ''));
            if ($nombre === '') {
                continue;
            }

            $corta = $indice('descripcion') !== false ? (string) ($fila[$indice('descripcion')] ?? '') : '';
            $larga = $indice('descripcion largo') !== false ? (string) ($fila[$indice('descripcion largo')] ?? '') : '';
            $texto = self::armar($fila[$indice('nombre')], $corta, $larga);

            if (mb_strlen($texto) > mb_strlen($lista->get($nombre, ''))) {
                $lista[$nombre] = $texto;
            } elseif (! $lista->has($nombre)) {
                $lista[$nombre] = $texto;
            }
        }
        fclose($lector);

        if ($lista->isEmpty()) {
            $this->error('El archivo no tiene filas con nombre de producto.');

            return null;
        }

        return $lista;
    }

    /**
     * La frase corta (si aporta algo que el texto largo no dice) y el texto largo, en texto plano.
     * Un producto cuya "descripción" es solo su nombre repetido queda sin texto.
     */
    public static function armar(string $nombre, string $corta, string $larga): string
    {
        $larga = self::htmlATexto($larga);
        $corta = Str::squish(self::htmlATexto($corta));

        $esElNombre = ImportarImagenesProductos::normalizar($corta) === ImportarImagenesProductos::normalizar($nombre);
        $yaEsta = $corta !== '' && str_contains(ImportarImagenesProductos::normalizar($larga), ImportarImagenesProductos::normalizar($corta));

        if ($corta === '' || mb_strlen($corta) < 10 || $esElNombre || $yaEsta) {
            return $larga;
        }

        // "bioestimulante para el estrés" -> "Bioestimulante para el estrés."
        $corta = mb_strtoupper(mb_substr($corta, 0, 1)).mb_substr($corta, 1);
        if (! preg_match('/[.!?]$/u', $corta)) {
            $corta .= '.';
        }

        return trim($corta.($larga !== '' ? "\n\n".$larga : ''));
    }

    /** HTML de un editor de otro sistema -> texto plano con párrafos y viñetas. */
    public static function htmlATexto(string $html): string
    {
        $texto = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
        $texto = preg_replace('/<\s*li[^>]*>/i', '• ', $texto);
        $texto = preg_replace('/<\s*\/\s*li\s*>/i', "\n", $texto);
        $texto = preg_replace('/<\s*\/\s*(p|div|h[1-6]|tr|ul|ol|table|blockquote)\s*>/i', "\n\n", $texto);
        $texto = strip_tags($texto);
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = str_replace(["\u{A0}", "\r"], [' ', ''], $texto);
        // los emojis que el otro sistema no pudo guardar quedaron como "?" sueltos
        $texto = preg_replace('/(^|\n)[\s?]*\?+\s*/u', '$1', $texto);
        $texto = preg_replace('/[ \t]+/', ' ', $texto);
        $texto = implode("\n", array_map('trim', explode("\n", $texto)));
        $texto = preg_replace('/\n{3,}/', "\n\n", $texto);

        return trim($texto);
    }

    /** Corta un texto largo al final de un párrafo o, si no, de una oración. */
    public static function recortar(string $texto, int $max): string
    {
        if (mb_strlen($texto) <= $max) {
            return $texto;
        }

        $corte = mb_substr($texto, 0, $max);
        $parrafo = mb_strrpos($corte, "\n\n");
        $oracion = mb_strrpos($corte, '. ');
        $donde = $parrafo !== false && $parrafo > $max * 0.5 ? $parrafo : ($oracion !== false ? $oracion + 1 : $max);

        return trim(mb_substr($corte, 0, $donde));
    }

    private function informar(array $resultado, bool $ejecutar): void
    {
        $etiquetas = [
            'importadas' => $ejecutar ? 'Descripciones importadas' : 'Descripciones que se importarían',
            'ya_tenian' => 'Ya tenían descripción (no se tocan; usa --sobrescribir para reemplazarlas)',
            'sin_texto' => 'En el otro sistema no tenían descripción',
            'sin_pareja' => 'Sin producto con ese nombre en el otro sistema',
        ];

        foreach ($etiquetas as $clave => $etiqueta) {
            $this->line("{$etiqueta}: ".count($resultado[$clave]));
            if ($this->option('detalle') || in_array($clave, ['sin_pareja'], true)) {
                foreach ($resultado[$clave] as $nombre) {
                    $this->line("    · {$nombre}");
                }
            }
        }
    }
}
