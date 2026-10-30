<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductoRequest extends FormRequest
{
    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;
        $productoId = $this->route('producto')?->id;

        return [
            // al crear lo asigna el sistema (P0001, P0002...); solo se edita en productos existentes
            'codigo_interno' => [
                $productoId ? 'required' : 'nullable', 'string', 'max:50',
                Rule::unique('productos', 'codigo_interno')
                    ->where('empresa_id', $empresaId)
                    ->whereNull('eliminado_en')
                    ->ignore($productoId),
            ],
            'nombre' => ['required', 'string', 'max:200'],
            'categoria_id' => ['nullable', 'uuid', Rule::exists('categorias', 'id')->where('empresa_id', $empresaId)],
            'marca_id' => ['nullable', 'uuid', Rule::exists('marcas', 'id')->where('empresa_id', $empresaId)],
            'unidad_base_codigo' => ['required', Rule::exists('unidades_medida', 'codigo')],
            'tipo_afectacion_codigo' => ['required', Rule::exists('tipos_afectacion_igv', 'codigo')],
            'permite_fraccion' => ['required', 'boolean'],
            'controla_lote' => ['required', 'boolean'],
            'controla_stock' => ['required', 'boolean'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0'],
            'activo' => ['required', 'boolean'],
            'imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'imagen_eliminar' => ['nullable', 'boolean'],

            // solo al crear: el stock con el que arranca el producto (en unidades base) y su costo;
            // entra como ajuste con una capa FIFO, igual que en la importacion. En edicion se ignora.
            'stock_inicial' => ['nullable', 'numeric', 'min:0'],
            'precio_compra' => ['nullable', 'numeric', 'min:0', Rule::requiredIf(fn () => ! $productoId && (float) $this->input('stock_inicial') > 0)],
            'sucursal_id' => ['nullable', 'uuid'],

            'presentaciones' => ['required', 'array', 'min:1'],
            'presentaciones.*.id' => ['nullable', 'uuid'],
            'presentaciones.*.nombre' => ['required', 'string', 'max:80'],
            'presentaciones.*.unidad_codigo' => ['required', Rule::exists('unidades_medida', 'codigo')],
            'presentaciones.*.factor_conversion' => ['required', 'numeric', 'gt:0'],
            'presentaciones.*.precio_venta' => ['required', 'numeric', 'min:0'],
            'presentaciones.*.precio_mayorista' => ['nullable', 'numeric', 'min:0', 'required_with:presentaciones.*.cantidad_mayorista'],
            'presentaciones.*.cantidad_mayorista' => ['nullable', 'numeric', 'gt:0', 'required_with:presentaciones.*.precio_mayorista'],
            'presentaciones.*.codigo_barras' => ['nullable', 'string', 'max:50'],
            'presentaciones.*.es_default' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo_interno.required' => 'El código interno es obligatorio.',
            'codigo_interno.unique' => 'Ya existe un producto con este código.',
            'nombre.required' => 'El nombre es obligatorio.',
            'unidad_base_codigo.required' => 'Elige la unidad base.',
            'tipo_afectacion_codigo.required' => 'Elige el tipo de afectación IGV.',
            'presentaciones.required' => 'Agrega al menos una presentación.',
            'presentaciones.min' => 'Agrega al menos una presentación.',
            'presentaciones.*.nombre.required' => 'El nombre de la presentación es obligatorio.',
            'presentaciones.*.unidad_codigo.required' => 'Elige la unidad.',
            'presentaciones.*.factor_conversion.required' => 'Indica el factor.',
            'presentaciones.*.factor_conversion.gt' => 'El factor debe ser mayor a 0.',
            'presentaciones.*.precio_venta.required' => 'Indica el precio.',
            'presentaciones.*.precio_venta.min' => 'El precio no puede ser negativo.',
            'presentaciones.*.precio_mayorista.required_with' => 'Indica el precio mayorista o borra la cantidad.',
            'presentaciones.*.cantidad_mayorista.required_with' => 'Indica desde cuántas unidades aplica el precio mayorista.',
            'imagen.image' => 'El archivo debe ser una imagen.',
            'imagen.mimes' => 'Formatos permitidos: JPG, PNG o WEBP.',
            'imagen.max' => 'La imagen no debe pesar más de 2 MB.',
            'stock_inicial.min' => 'El stock inicial no puede ser negativo.',
            'precio_compra.required' => 'Indica el precio de compra del stock inicial.',
            'precio_compra.min' => 'El precio de compra no puede ser negativo.',
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($this->route('producto') || (float) $this->input('stock_inicial') <= 0) {
                return;
            }

            // con lote hace falta el numero y el vencimiento: eso lo pide el ajuste de stock
            if ($this->boolean('controla_lote')) {
                $validator->errors()->add('stock_inicial', 'Este producto controla lote: carga el stock con un ajuste de stock indicando el lote.');
            }

            if (! $this->boolean('permite_fraccion') && fmod((float) $this->input('stock_inicial'), 1) != 0) {
                $validator->errors()->add('stock_inicial', 'Este producto no permite cantidades fraccionadas.');
            }
        }];
    }
}
