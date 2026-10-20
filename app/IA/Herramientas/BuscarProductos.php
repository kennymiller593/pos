<?php

namespace App\IA\Herramientas;

use App\Services\BusquedaProductos as Busqueda;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/** Busca en el catálogo de la empresa por nombre, descripción, categoría o marca. */
class BuscarProductos extends CatalogoDeLaEmpresa implements Tool
{
    public const MAXIMO = 5;

    public function name(): string
    {
        return 'buscar_productos';
    }

    public function description(): string
    {
        return 'Busca productos en el catálogo del negocio por palabras clave (nombre, principio activo, descripción, categoría o marca). '
            .'Devuelve hasta '.self::MAXIMO.' productos con presentaciones, precios y stock. Úsala antes de recomendar cualquier producto.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'consulta' => $schema->string()->description('Palabras clave a buscar: la más distintiva primero (marca, variedad, número de modelo, principio activo), por ejemplo "dekalb 7508", "mancozeb" o "paracetamol 500". Evita palabras genéricas como "semilla" o "producto".')->required(),
            'categoria' => $schema->string()->description('Opcional: nombre de una categoría para acotar la búsqueda.'),
        ];
    }

    public function handle(Request $request): string
    {
        $consulta = trim((string) $request->string('consulta'));
        $categoria = trim((string) $request->string('categoria'));
        $palabras = Busqueda::palabras($consulta);

        if ($palabras === []) {
            return 'Indica al menos una palabra para buscar.';
        }

        $base = $this->consulta();
        if ($categoria !== '') {
            $base->whereHas('categoria', fn ($c) => $c->where('nombre', 'ILIKE', '%'.$this->escapar($categoria).'%'));
        }

        // primero con todas las palabras; si no sale nada, se va exigiendo una menos (la más distintiva suele bastar)
        $busqueda = app(Busqueda::class);
        $productos = collect();
        for ($minimo = count($palabras); $minimo >= 1 && $productos->isEmpty(); $minimo--) {
            $productos = $busqueda->aplicar(clone $base, $palabras, $minimo, conDescripcion: true)
                ->limit(self::MAXIMO + 1)->get()->filter(fn ($p) => $p->presentaciones->isNotEmpty());
        }

        if ($productos->isEmpty()) {
            return "No hay productos en el catálogo que coincidan con \"{$consulta}\". Prueba con otras palabras (sinónimo, principio activo, categoría).";
        }

        // si hubo que relajar la búsqueda, el modelo debe saber qué palabra no apareció para no afirmar de más
        $faltantes = Busqueda::faltantes($productos->first(), $palabras);
        $aviso = $faltantes !== []
            ? 'No hubo coincidencia con todas las palabras ("'.implode('", "', $faltantes).'" no aparece); estos productos coinciden con el resto, ordenados por parecido:'."\n\n"
            : '';

        $hayMas = $productos->count() > self::MAXIMO;
        $texto = $productos->take(self::MAXIMO)->map(fn ($p) => $this->describir($p))->implode("\n\n");

        return $aviso.$texto.($hayMas ? "\n\n(Hay más resultados; afina la búsqueda si hace falta.)" : '');
    }

    private function escapar(string $texto): string
    {
        return addcslashes($texto, '%_\\');
    }
}
