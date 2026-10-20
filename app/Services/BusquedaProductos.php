<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Búsqueda de productos por palabras, con tolerancia y orden por relevancia. La comparten la tienda
 * en línea y el chatbot, para que lo que encuentra una lo encuentre el otro.
 *
 * - Sin tildes ni mayúsculas en los dos lados (normalizar_texto en la BD, script 030).
 * - "dk7508" se parte en "dk" y "7508": el número de una variedad o modelo encuentra "DEKALB-7508".
 * - Palabras de relleno ("de", "para", "quiero"...) no cuentan si hay otras.
 * - Una palabra con error de tipeo ("dekalv") también coincide, por similitud de trigramas.
 * - Primero lo que coincide con más palabras; entre iguales, lo que coincide en el nombre pesa más que en
 *   la marca, la categoría o la descripción.
 */
class BusquedaProductos
{
    public const MAX_PALABRAS = 8;

    /** Palabras que no distinguen un producto de otro: se ignoran si la búsqueda tiene algo más. */
    private const RELLENO = [
        'de', 'del', 'la', 'el', 'las', 'los', 'un', 'una', 'unos', 'unas', 'para', 'por', 'con', 'sin', 'y', 'o', 'a', 'al', 'en', 'lo',
        'que', 'x', 'mi', 'me', 'tu', 'su', 'hay', 'tiene', 'tienen', 'tienes', 'quiero', 'necesito', 'busco', 'precio', 'precios', 'algo',
        'producto', 'productos', 'venden', 'vende', 'cuanto', 'cuesta', 'vale',
    ];

    /** Puntos por dónde coincide una palabra; coincidir en el nombre vale más que en la descripción. */
    private const PUNTOS = ['nombre' => 10, 'codigo' => 8, 'barras' => 8, 'marca' => 6, 'categoria' => 4, 'descripcion' => 2, 'parecido' => 5];

    /**
     * Las palabras con que se busca: en minúsculas, sin tildes, letras y números separados, sin relleno.
     *
     * @return list<string>
     */
    public static function palabras(string $texto): array
    {
        $texto = Str::ascii(mb_strtolower(trim($texto)));
        $palabras = [];

        foreach (preg_split('/[\s,;:()\[\]{}"\'¿?¡!\/\\\\-]+/', $texto) ?: [] as $trozo) {
            if ($trozo === '') {
                continue;
            }
            // "dk7508" -> "dk", "7508"; "1.5l" -> "1.5", "l"
            foreach (preg_split('/(?<=[a-z])(?=[0-9])|(?<=[0-9])(?=[a-z])/', $trozo) ?: [] as $palabra) {
                // una sola letra o cifra no dice nada; un símbolo suelto ("%") sí se busca tal cual
                if (strlen($palabra) >= 2 || ! ctype_alnum($palabra)) {
                    $palabras[] = $palabra;
                }
            }
        }

        $palabras = array_values(array_unique($palabras));
        $utiles = array_values(array_filter($palabras, fn ($p) => ! in_array($p, self::RELLENO, true)));

        return array_slice($utiles !== [] ? $utiles : $palabras, 0, self::MAX_PALABRAS);
    }

    /**
     * Deja en la consulta solo los productos que coinciden con al menos $minimo palabras y los ordena por
     * relevancia. Agrega las columnas `coincidencias` (cuántas palabras coinciden) y `relevancia`.
     *
     * @param  list<string>  $palabras
     */
    public function aplicar(Builder $consulta, array $palabras, ?int $minimo = null, bool $conDescripcion = false): Builder
    {
        if ($palabras === []) {
            return $consulta->whereRaw('false');
        }

        $minimo = max(1, min($minimo ?? count($palabras), count($palabras)));
        [$coincide, $puntos, $ligaduras] = [[], [], []];

        foreach ($palabras as $palabra) {
            $condiciones = $this->condiciones($palabra, $conDescripcion);
            $coincide[] = '('.implode(' OR ', array_column($condiciones, 0)).')';
            $puntos[] = implode(' + ', array_map(fn ($c) => "CASE WHEN {$c[0]} THEN {$c[2]} ELSE 0 END", $condiciones));
            // la misma palabra aparece una vez en el "coincide" y otra en los puntos
            foreach ($condiciones as $c) {
                $ligaduras['coincide'][] = $c[1];
            }
            foreach ($condiciones as $c) {
                $ligaduras['puntos'][] = $c[1];
            }
        }

        $coincidencias = '('.implode(' + ', array_map(fn ($c) => "CASE WHEN {$c} THEN 1 ELSE 0 END", $coincide)).')';
        // desempate: cuánto se parece el nombre completo a lo buscado
        $relevancia = '('.implode(' + ', $puntos).' + similarity(normalizar_texto(productos.nombre), ?) * 10)';
        $ligaduras['puntos'][] = implode(' ', $palabras);

        if ($consulta->getQuery()->columns === null) {
            $consulta->select('productos.*');
        }

        return $consulta
            ->selectRaw("{$coincidencias} AS coincidencias, {$relevancia} AS relevancia", [...$ligaduras['coincide'], ...$ligaduras['puntos']])
            ->whereRaw("{$coincidencias} >= ?", [...$ligaduras['coincide'], $minimo])
            ->orderByRaw("{$coincidencias} DESC, {$relevancia} DESC", [...$ligaduras['coincide'], ...$ligaduras['puntos']]);
    }

    /**
     * Con cuántas palabras hay que exigir coincidencia para que salga algo: todas si se puede; si no, una menos.
     *
     * @param  list<string>  $palabras
     */
    public function minimo(Builder $consulta, array $palabras, bool $conDescripcion = false): int
    {
        $total = count($palabras);
        if ($total < 2) {
            return $total;
        }

        return $this->aplicar(clone $consulta, $palabras, $total, $conDescripcion)->toBase()->exists() ? $total : $total - 1;
    }

    /**
     * Qué palabras de la búsqueda no aparecen en un producto ya consultado con aplicar().
     *
     * @param  list<string>  $palabras
     * @return list<string>
     */
    public static function faltantes(object $producto, array $palabras): array
    {
        $texto = Str::ascii(mb_strtolower(implode(' ', array_filter([
            $producto->nombre, $producto->codigo_interno ?? null, $producto->marca?->nombre, $producto->categoria?->nombre, $producto->descripcion ?? null,
        ]))));

        return array_values(array_filter($palabras, fn ($p) => ! str_contains($texto, $p)));
    }

    /**
     * Dónde puede coincidir una palabra: [sql, ligadura, puntos].
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function condiciones(string $palabra, bool $conDescripcion): array
    {
        $patron = '%'.addcslashes($palabra, '%_\\').'%';

        $condiciones = [
            ['normalizar_texto(productos.nombre) LIKE ?', $patron, self::PUNTOS['nombre']],
            ['lower(productos.codigo_interno) LIKE ?', $patron, self::PUNTOS['codigo']],
            ['EXISTS (SELECT 1 FROM producto_presentaciones pp WHERE pp.producto_id = productos.id AND pp.activo AND pp.codigo_barras = ?)', $palabra, self::PUNTOS['barras']],
            ['EXISTS (SELECT 1 FROM marcas m WHERE m.id = productos.marca_id AND normalizar_texto(m.nombre) LIKE ?)', $patron, self::PUNTOS['marca']],
            ['EXISTS (SELECT 1 FROM categorias c WHERE c.id = productos.categoria_id AND normalizar_texto(c.nombre) LIKE ?)', $patron, self::PUNTOS['categoria']],
        ];

        if ($conDescripcion) {
            $condiciones[] = ['normalizar_texto(productos.descripcion) LIKE ?', $patron, self::PUNTOS['descripcion']];
        }

        // error de tipeo: una palabra de 4 letras o más que se parece mucho a alguna del nombre
        if (strlen($palabra) >= 4 && ctype_alpha($palabra)) {
            $condiciones[] = ['word_similarity(?, normalizar_texto(productos.nombre)) >= 0.6', $palabra, self::PUNTOS['parecido']];
        }

        return $condiciones;
    }
}
