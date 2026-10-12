<?php

namespace App\IA\Herramientas;

use App\Models\Categoria;
use App\Models\Empresa;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/** Las categorías del catálogo con cuántos productos tiene cada una: le da al agente el mapa del negocio. */
class ListarCategorias implements Tool
{
    public function __construct(private readonly Empresa $empresa) {}

    public function name(): string
    {
        return 'listar_categorias';
    }

    public function description(): string
    {
        return 'Lista las categorías del catálogo del negocio con la cantidad de productos de cada una. Útil para saber qué tipos de productos vende el negocio.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $categorias = Categoria::query()
            ->where('empresa_id', $this->empresa->id)
            ->withCount(['productos' => fn ($q) => $q->where('activo', true)])
            ->orderBy('nombre')
            ->get()
            ->filter(fn ($c) => $c->productos_count > 0);

        if ($categorias->isEmpty()) {
            return 'El catálogo no tiene categorías con productos.';
        }

        return $categorias->map(fn ($c) => "- {$c->nombre} ({$c->productos_count} productos)")->implode("\n");
    }
}
