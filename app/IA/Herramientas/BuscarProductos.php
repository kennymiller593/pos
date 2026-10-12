<?php

namespace App\IA\Herramientas;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
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
            'consulta' => $schema->string()->description('Palabras clave a buscar, por ejemplo "insecticida gusano cogollero" o "paracetamol 500".')->required(),
            'categoria' => $schema->string()->description('Opcional: nombre de una categoría para acotar la búsqueda.'),
        ];
    }

    public function handle(Request $request): string
    {
        $consulta = trim((string) $request->string('consulta'));
        $categoria = trim((string) $request->string('categoria'));
        $palabras = collect(preg_split('/\s+/', Str::ascii(mb_strtolower($consulta))))
            ->map(fn ($p) => trim($p, ' ,.;:'))
            ->filter(fn ($p) => mb_strlen($p) >= 2)
            ->unique()
            ->take(6);

        if ($palabras->isEmpty()) {
            return 'Indica al menos una palabra para buscar.';
        }

        $q = $this->consulta();
        if ($categoria !== '') {
            $q->whereHas('categoria', fn ($c) => $c->where('nombre', 'ILIKE', '%'.$this->escapar($categoria).'%'));
        }

        // cada palabra debe aparecer en alguno de los campos (nombre, descripción, categoría o marca)
        foreach ($palabras as $palabra) {
            $patron = '%'.$this->escapar($palabra).'%';
            $q->where(function ($w) use ($patron) {
                $w->where('productos.nombre', 'ILIKE', $patron)
                    ->orWhere('productos.descripcion', 'ILIKE', $patron)
                    ->orWhereHas('categoria', fn ($c) => $c->where('nombre', 'ILIKE', $patron))
                    ->orWhereHas('marca', fn ($m) => $m->where('nombre', 'ILIKE', $patron));
            });
        }

        $productos = $q->orderBy('nombre')->limit(self::MAXIMO + 1)->get()->filter(fn ($p) => $p->presentaciones->isNotEmpty());

        // si con todas las palabras no hay nada, se relaja a "cualquiera de las palabras"
        if ($productos->isEmpty() && $palabras->count() > 1) {
            $q = $this->consulta()->where(function ($w) use ($palabras) {
                foreach ($palabras as $palabra) {
                    $patron = '%'.$this->escapar($palabra).'%';
                    $w->orWhere('productos.nombre', 'ILIKE', $patron)->orWhere('productos.descripcion', 'ILIKE', $patron);
                }
            });
            $productos = $q->orderBy('nombre')->limit(self::MAXIMO + 1)->get()->filter(fn ($p) => $p->presentaciones->isNotEmpty());
            $aviso = "No hubo coincidencia exacta con \"{$consulta}\"; estos productos coinciden parcialmente:\n\n";
        }

        if ($productos->isEmpty()) {
            return "No hay productos en el catálogo que coincidan con \"{$consulta}\". Prueba con otras palabras (sinónimo, principio activo, categoría).";
        }

        $hayMas = $productos->count() > self::MAXIMO;
        $texto = $productos->take(self::MAXIMO)->map(fn ($p) => $this->describir($p))->implode("\n\n");

        return ($aviso ?? '').$texto.($hayMas ? "\n\n(Hay más resultados; afina la búsqueda si hace falta.)" : '');
    }

    private function escapar(string $texto): string
    {
        return addcslashes($texto, '%_\\');
    }
}
