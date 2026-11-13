<?php

namespace App\Http\Requests;

use App\Models\ProductoGrupo;
use App\Services\VarianteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductoGrupoRequest extends FormRequest
{
    /** Un modelo de otra empresa no se toca. */
    public function authorize(): bool
    {
        $grupo = $this->route('grupo');

        return ! $grupo instanceof ProductoGrupo || $grupo->empresa_id === $this->user()->empresa_id;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;

        return [
            'nombre' => ['required', 'string', 'max:160'],
            'categoria_id' => ['nullable', 'uuid', Rule::exists('categorias', 'id')->where('empresa_id', $empresaId)],
            'marca_id' => ['nullable', 'uuid', Rule::exists('marcas', 'id')->where('empresa_id', $empresaId)],
            'unidad_base_codigo' => ['required', 'string', Rule::exists('unidades_medida', 'codigo')],
            'tipo_afectacion_codigo' => ['required', 'string', Rule::exists('tipos_afectacion_igv', 'codigo')],
            // precio de las combinaciones que no indiquen uno propio
            'precio_base' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            // costo unitario del stock inicial (solo hace falta si se carga stock)
            'costo' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            // a qué sucursal entra el stock inicial (viendo "todas")
            'sucursal_id' => ['nullable', 'uuid'],

            'atributos' => ['required', 'array', 'min:1', 'max:'.VarianteService::MAX_ATRIBUTOS],
            'atributos.*.nombre' => ['required', 'string', 'max:30'],
            'atributos.*.valores' => ['required', 'array', 'min:1', 'max:100'],
            'atributos.*.valores.*' => ['required', 'string', 'max:30'],

            // una fila por combinación con lo que se aparta del precio base (opcional)
            'variantes' => ['nullable', 'array', 'max:'.VarianteService::MAX_COMBINACIONES],
            'variantes.*.valores' => ['required', 'array', 'min:1', 'max:'.VarianteService::MAX_ATRIBUTOS],
            'variantes.*.valores.*' => ['required', 'string', 'max:30'],
            'variantes.*.precio_venta' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'variantes.*.codigo_barras' => ['nullable', 'string', 'max:50'],
            'variantes.*.stock_inicial' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'variantes.*.omitir' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre del modelo.',
            'nombre.max' => 'El nombre del modelo no debe pasar de 160 caracteres.',
            'unidad_base_codigo.required' => 'Elige la unidad.',
            'tipo_afectacion_codigo.required' => 'Elige el tipo de afectación IGV.',
            'precio_base.required' => 'Indica el precio de venta.',
            'precio_base.numeric' => 'El precio de venta debe ser un número.',
            'precio_base.gt' => 'El precio de venta debe ser mayor a 0.',
            'precio_base.max' => 'El precio de venta es demasiado alto.',
            'costo.max' => 'El costo es demasiado alto.',
            'variantes.*.precio_venta.max' => 'El precio es demasiado alto.',
            'variantes.*.stock_inicial.max' => 'El stock inicial es demasiado alto.',
            'unidad_base_codigo.string' => 'Elige la unidad.',
            'tipo_afectacion_codigo.string' => 'Elige el tipo de afectación IGV.',
            'costo.numeric' => 'El costo debe ser un número.',
            'costo.min' => 'El costo no puede ser negativo.',
            'atributos.required' => 'Agrega al menos una característica (por ejemplo, Talla).',
            'atributos.min' => 'Agrega al menos una característica (por ejemplo, Talla).',
            'atributos.max' => 'Un modelo admite como máximo '.VarianteService::MAX_ATRIBUTOS.' características.',
            'atributos.*.nombre.required' => 'Ponle nombre a la característica (por ejemplo, Talla o Color).',
            'atributos.*.nombre.max' => 'El nombre de la característica no debe pasar de 30 caracteres.',
            'atributos.*.valores.required' => 'Agrega al menos una opción a la característica.',
            'atributos.*.valores.min' => 'Agrega al menos una opción a la característica.',
            'atributos.*.valores.max' => 'Una característica admite como máximo 100 opciones.',
            'atributos.*.valores.*.required' => 'Hay una opción vacía.',
            'atributos.*.valores.*.max' => 'Cada opción no debe pasar de 30 caracteres.',
            'variantes.max' => 'Un modelo admite como máximo '.VarianteService::MAX_COMBINACIONES.' variantes.',
            'variantes.*.precio_venta.numeric' => 'El precio debe ser un número.',
            'variantes.*.precio_venta.gt' => 'El precio debe ser mayor a 0.',
            'variantes.*.codigo_barras.max' => 'El código de barras no debe pasar de 50 caracteres.',
            'variantes.*.stock_inicial.integer' => 'El stock inicial debe ser un número entero.',
            'variantes.*.stock_inicial.min' => 'El stock inicial no puede ser negativo.',
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            // con errores de formato no se sigue: lo de abajo asume listas bien formadas
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $minusculas = fn ($texto) => mb_strtolower(trim((string) $texto));
            $atributos = (array) $this->input('atributos');
            $grupo = $this->route('grupo');

            // nombres de atributo y valores sin repetir (Talla y talla son lo mismo)
            $nombres = array_map(fn ($a) => $minusculas($a['nombre'] ?? ''), $atributos);
            foreach ($nombres as $i => $nombre) {
                if (array_search($nombre, $nombres, true) !== $i) {
                    $validator->errors()->add("atributos.{$i}.nombre", 'Esta característica está repetida.');
                }
            }
            foreach ($atributos as $i => $atributo) {
                $valores = array_map($minusculas, (array) ($atributo['valores'] ?? []));
                foreach ($valores as $j => $valor) {
                    if (array_search($valor, $valores, true) !== $j) {
                        $validator->errors()->add("atributos.{$i}.valores", "La opción \"{$atributo['valores'][$j]}\" está repetida.");
                        break;
                    }
                }
            }

            // al editar se agregan o quitan valores, pero los atributos del modelo son los mismos
            if ($grupo instanceof ProductoGrupo) {
                $guardados = array_map(fn ($a) => $minusculas($a['nombre'] ?? ''), $grupo->atributos ?? []);
                if ($guardados !== array_values($nombres)) {
                    $validator->errors()->add('atributos', 'Las características de un modelo ya creado no se cambian: solo se agregan o quitan opciones. Para otras características crea un modelo nuevo.');
                }
            }

            $total = array_product(array_map(fn ($a) => count((array) ($a['valores'] ?? [])), $atributos));
            if ($total > VarianteService::MAX_COMBINACIONES) {
                $validator->errors()->add('atributos', "Son {$total} variantes y el máximo es ".VarianteService::MAX_COMBINACIONES.'. Quita algunas opciones o divide el modelo en dos.');
            }

            // el nombre de cada variante es "modelo + valores" y debe caber en el nombre del producto
            $masLargo = array_sum(array_map(fn ($a) => 1 + max(array_map(fn ($v) => mb_strlen(trim((string) $v)), (array) $a['valores'])), $atributos));
            if (mb_strlen(trim((string) $this->input('nombre'))) + $masLargo > 200) {
                $validator->errors()->add('nombre', 'El nombre del modelo con sus opciones pasa de 200 caracteres: acórtalo.');
            }

            $this->validarFilas($validator, $grupo instanceof ProductoGrupo ? $grupo : null);
        }];
    }

    /** Códigos de barras sin repetir (entre filas y contra los que ya usa la empresa) y costo cuando se carga stock. */
    private function validarFilas($validator, ?ProductoGrupo $grupo): void
    {
        $filas = collect((array) $this->input('variantes'))->reject(fn ($f) => filter_var($f['omitir'] ?? false, FILTER_VALIDATE_BOOLEAN));

        $vistos = [];
        foreach ($filas as $i => $fila) {
            $codigo = trim((string) ($fila['codigo_barras'] ?? ''));
            if ($codigo === '') {
                continue;
            }
            if (isset($vistos[$codigo])) {
                $validator->errors()->add("variantes.{$i}.codigo_barras", 'Este código de barras está repetido en otra fila.');
            }
            $vistos[$codigo] = true;
        }

        if ($vistos) {
            // quién usa ya cada código entre los productos vivos de la empresa
            $usados = DB::table('producto_presentaciones as pp')
                ->join('productos as p', 'p.id', '=', 'pp.producto_id')
                ->where('pp.empresa_id', $this->user()->empresa_id)
                ->whereNull('p.eliminado_en')
                ->whereIn('pp.codigo_barras', array_map('strval', array_keys($vistos)))
                ->get(['pp.codigo_barras', 'p.id', 'p.nombre'])
                ->groupBy('codigo_barras');

            // la variante que ya existe para cada fila: solo ella puede conservar su propio código
            // (otra del mismo modelo, aunque esté inactiva u omitida, sí bloquea)
            $propias = [];
            if ($grupo) {
                $atributos = $grupo->atributos ?? [];
                foreach ($grupo->variantes()->get(['id', 'atributos']) as $variante) {
                    $propias[VarianteService::clave(VarianteService::valoresDe($variante, $atributos))] = $variante->id;
                }
            }

            foreach ($filas as $i => $fila) {
                $codigo = trim((string) ($fila['codigo_barras'] ?? ''));
                if ($codigo === '' || ! $usados->has($codigo)) {
                    continue;
                }
                $propia = $propias[VarianteService::clave((array) ($fila['valores'] ?? []))] ?? null;
                $otro = $usados[$codigo]->first(fn ($uso) => $uso->id !== $propia);
                if ($otro) {
                    $validator->errors()->add("variantes.{$i}.codigo_barras", "Ese código de barras ya lo usa \"{$otro->nombre}\".");
                }
            }
        }

        $conStock = $filas->contains(fn ($f) => (float) ($f['stock_inicial'] ?? 0) > 0);
        $costo = $this->input('costo');
        // sin costo quedaría una capa a costo 0 que infla el margen
        if ($conStock && ($costo === null || $costo === '')) {
            $validator->errors()->add('costo', 'Indica el costo de compra para cargar el stock inicial.');
        }
    }
}
