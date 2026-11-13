<?php

namespace App\Http\Requests;

use App\Services\SerieService;
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
            // cada unidad con su serie / IMEI (módulo opcional; sin el módulo activo el campo se ignora)
            'controla_serie' => ['nullable', 'boolean'],
            'controla_stock' => ['required', 'boolean'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0'],
            'activo' => ['required', 'boolean'],
            'imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'imagen_eliminar' => ['nullable', 'boolean'],

            // solo al crear: a que sucursal entra el stock inicial (viendo "todas"); en edicion se ignora
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
            // solo al crear: cuanto hay de esa presentacion y a cuanto se compro cada una ("2 cajas a S/ 30");
            // entra como ajuste con una capa FIFO por presentacion, igual que en la importacion. En edicion se ignora.
            'presentaciones.*.stock_inicial' => ['nullable', 'numeric', 'min:0'],
            'presentaciones.*.precio_compra' => ['nullable', 'numeric', 'min:0'],
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
            'presentaciones.*.stock_inicial.min' => 'El stock inicial no puede ser negativo.',
            'presentaciones.*.precio_compra.min' => 'El precio de compra no puede ser negativo.',
        ];
    }

    /**
     * Si el producto queda controlando serie / IMEI: lo que diga el formulario cuando la empresa tiene el módulo
     * activo; sin el módulo el campo se ignora y el producto conserva lo que tenía.
     */
    public function controlaSerie(): bool
    {
        // tampoco se toca si el formulario no manda el campo (una pantalla abierta desde antes del módulo)
        if (! $this->user()->empresa->series_activo || ! $this->has('controla_serie')) {
            return (bool) $this->route('producto')?->controla_serie;
        }

        return $this->boolean('controla_serie');
    }

    public function after(): array
    {
        return [function ($validator) {
            $this->validarSerie($validator);

            if ($this->route('producto')) {
                return;
            }

            foreach ((array) $this->input('presentaciones') as $i => $p) {
                $stock = (float) ($p['stock_inicial'] ?? 0);
                if ($stock <= 0) {
                    continue;
                }

                // con lote hace falta el numero y el vencimiento: eso lo pide el ajuste de stock
                if ($this->boolean('controla_lote')) {
                    $validator->errors()->add("presentaciones.{$i}.stock_inicial", 'Este producto controla lote: carga el stock con un ajuste de stock indicando el lote.');
                }

                // con serie hace falta el numero de cada unidad: eso lo pide el ajuste de stock
                if ($this->controlaSerie()) {
                    $validator->errors()->add("presentaciones.{$i}.stock_inicial", 'Este producto controla serie / IMEI: carga el stock con un ajuste de stock indicando las series.');
                }

                // sin costo quedaria una capa a costo 0 que infla el margen
                if (! isset($p['precio_compra']) || $p['precio_compra'] === '') {
                    $validator->errors()->add("presentaciones.{$i}.precio_compra", 'Indica el precio de compra del stock inicial.');
                }

                $base = round($stock * ((float) ($p['factor_conversion'] ?? 1) ?: 1), 3);
                if (! $this->boolean('permite_fraccion') && fmod($base, 1) != 0) {
                    $validator->errors()->add("presentaciones.{$i}.stock_inicial", 'Este producto no permite cantidades fraccionadas.');
                }
            }
        }];
    }

    /**
     * Controla serie / IMEI: cada unidad es una pieza entera con su número, así que no admite fracciones, lotes ni
     * presentaciones que agrupen varias unidades. Si se activa con stock, esas unidades quedan por regularizar
     * (Stock → Registrar sus series); se desactiva solo si ya no quedan unidades con serie en el negocio.
     */
    private function validarSerie($validator): void
    {
        $producto = $this->route('producto');
        $antes = (bool) $producto?->controla_serie;
        $ahora = $this->controlaSerie();

        if ($ahora) {
            if ($this->boolean('permite_fraccion')) {
                $validator->errors()->add('controla_serie', 'Un producto con serie / IMEI se vende por unidades enteras: desmarca "Permite fracción".');
            }
            if ($this->boolean('controla_lote')) {
                $validator->errors()->add('controla_serie', 'Un producto no puede controlar lote y serie / IMEI a la vez: elige uno.');
            }
            if (! $this->boolean('controla_stock')) {
                $validator->errors()->add('controla_serie', 'Para controlar serie / IMEI el producto debe controlar stock.');
            }
            foreach ((array) $this->input('presentaciones') as $i => $p) {
                if (abs((float) ($p['factor_conversion'] ?? 1) - 1) > 0.0000001) {
                    $validator->errors()->add("presentaciones.{$i}.factor_conversion", 'Con serie / IMEI cada presentación es una unidad: el factor debe ser 1.');
                }
            }
        }

        if (! $producto || $antes === $ahora) {
            return;
        }

        // activar con una transferencia en camino o desactivar con unidades con serie en stock: no se puede
        // (la misma regla vale para el modelo con variantes)
        if ($motivo = SerieService::impedimentoParaCambiar($producto, $ahora)) {
            $validator->errors()->add('controla_serie', $motivo);
        }
    }
}
