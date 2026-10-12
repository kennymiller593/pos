<?php

namespace App\IA\Herramientas;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/** La ficha completa de un producto (descripción entera), cuando la búsqueda la recortó. */
class DetalleProducto extends CatalogoDeLaEmpresa implements Tool
{
    public function name(): string
    {
        return 'detalle_producto';
    }

    public function description(): string
    {
        return 'Devuelve la ficha completa de un producto del catálogo (descripción entera, presentaciones, precios y stock) a partir de su código.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'codigo' => $schema->string()->description('Código del producto tal como lo devolvió buscar_productos.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $codigo = trim((string) $request->string('codigo'));
        $producto = $codigo === '' ? null : $this->consulta()->where('codigo_interno', $codigo)->first();

        if (! $producto) {
            return "No existe un producto con el código \"{$codigo}\" en el catálogo.";
        }

        return $this->describir($producto, completo: true);
    }
}
