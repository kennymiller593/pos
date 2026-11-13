<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\ProductoGrupo;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Modelos con variantes (Polo básico en tallas y colores). El modelo solo agrupa: cada combinación
 * es un producto normal con su presentación por defecto, así ventas, compras, FIFO, kardex y SUNAT
 * siguen operando por producto sin enterarse.
 */
class VarianteService
{
    public const MAX_ATRIBUTOS = 3;

    public const MAX_COMBINACIONES = 200;

    /** Tablas donde un producto deja rastro: si aparece en alguna, la variante se desactiva en vez de eliminarse. */
    private const TABLAS_CON_MOVIMIENTOS = [
        'movimientos_inventario', 'comprobante_detalles', 'compra_detalles', 'cotizacion_detalles',
        'pedido_detalles', 'transferencia_detalles', 'guia_remision_detalles', 'mezcla_insumos', 'mezclas',
        'receta_insumos', 'recetas', 'capas_costo', 'lotes',
    ];

    /** Lo que hizo el último guardarGrupo(): ['creadas' => n, 'desactivadas' => n, 'eliminadas' => n, 'stock' => unidades]. */
    public array $resumen = ['creadas' => 0, 'desactivadas' => 0, 'eliminadas' => 0, 'stock' => 0.0];

    public function __construct(private readonly InventarioService $inventario) {}

    /** Identifica una combinación sin distinguir mayúsculas ni espacios de más: ["M", "Rojo"] y ["m ", "ROJO"] son la misma. */
    public static function clave(array $valores): string
    {
        return implode("\x1f", array_map(fn ($v) => mb_strtolower(trim((string) $v)), array_values($valores)));
    }

    /**
     * Todas las combinaciones de la definición, en el orden en que se escribieron los valores
     * (el primer atributo manda): Talla [S, M] x Color [Rojo, Azul] → S Rojo, S Azul, M Rojo, M Azul.
     *
     * @param  list<array{nombre: string, valores: list<string>}>  $atributos
     * @return list<list<string>>
     */
    public static function combinaciones(array $atributos): array
    {
        $combinaciones = [[]];
        foreach ($atributos as $atributo) {
            $siguientes = [];
            foreach ($combinaciones as $parcial) {
                foreach ($atributo['valores'] as $valor) {
                    $siguientes[] = [...$parcial, $valor];
                }
            }
            $combinaciones = $siguientes;
        }

        return $atributos ? $combinaciones : [];
    }

    /** Deja la definición limpia: nombres y valores sin espacios sobrantes y sin vacíos. */
    public static function normalizarAtributos(array $atributos): array
    {
        return collect($atributos)
            ->map(fn ($a) => [
                'nombre' => trim((string) ($a['nombre'] ?? '')),
                'valores' => collect($a['valores'] ?? [])->map(fn ($v) => trim((string) $v))->filter(fn ($v) => $v !== '')->values()->all(),
            ])
            ->filter(fn ($a) => $a['nombre'] !== '')
            ->values()
            ->all();
    }

    /** Los valores de una variante ya guardada, en el orden de los atributos del modelo. */
    public static function valoresDe(Producto $variante, array $atributos): array
    {
        return array_map(fn ($a) => (string) (($variante->atributos ?? [])[$a['nombre']] ?? ''), $atributos);
    }

    /**
     * Crea o actualiza un modelo y sus variantes.
     *
     * $datos: nombre, categoria_id, marca_id, unidad_base_codigo, tipo_afectacion_codigo, precio_base, costo,
     * atributos [{nombre, valores[]}] y variantes [{valores[], precio_venta, codigo_barras, stock_inicial, omitir}]
     * (las filas son opcionales: una combinación sin fila toma el precio base).
     *
     * Al editar: las combinaciones nuevas se crean; las que ya existen nunca se duplican; las que salen de la
     * definición (o vienen con omitir) se desactivan si tienen movimientos y se eliminan si no tienen ninguno.
     * Las combinaciones omitidas se recuerdan en el modelo (producto_grupos.omitidas): una combinación sin fila
     * que ya estaba omitida sigue omitida, no se crea sola al volver a guardar.
     * El stock inicial solo aplica a variantes que se crean en esta llamada y entra a $sucursalId.
     */
    public function guardarGrupo(Usuario $usuario, array $datos, ?ProductoGrupo $grupo = null, ?string $sucursalId = null): ProductoGrupo
    {
        $empresaId = $usuario->empresa_id;
        $atributos = self::normalizarAtributos($datos['atributos'] ?? []);

        // al editar los atributos del modelo no cambian (solo sus valores): se conservan los nombres guardados
        if ($grupo) {
            foreach ($grupo->atributos ?? [] as $i => $guardado) {
                if (isset($atributos[$i])) {
                    $atributos[$i]['nombre'] = $guardado['nombre'];
                }
            }
        }

        $filas = collect($datos['variantes'] ?? [])->keyBy(fn ($f) => self::clave($f['valores'] ?? []));
        $precioBase = (float) $datos['precio_base'];
        $costo = round((float) ($datos['costo'] ?? 0), 6);
        $puedePrecios = $usuario->can('productos.precios');
        $puedeStock = $usuario->can('stock.ajustar') && $sucursalId !== null;

        // el Nuevo RUS no discrimina IGV: lo gravado se guarda como exonerado (igual que en el producto)
        $afectacion = $datos['tipo_afectacion_codigo'];
        if ($usuario->empresa->esRus() && $afectacion === '10') {
            $afectacion = Empresa::AFECTACION_RUS;
        }

        $this->resumen = ['creadas' => 0, 'desactivadas' => 0, 'eliminadas' => 0, 'stock' => 0.0];
        $cambiosPrecio = [];
        $esNuevo = $grupo === null;
        // lo que ya estaba omitido se respeta mientras no llegue una fila que diga otra cosa
        $omitidasAntes = array_map(fn ($valores) => self::clave((array) $valores), $grupo?->omitidas ?? []);

        // mismo candado que el alta y la importación de productos: los códigos internos (P0001...) no se repiten.
        // 60 s de vida como en la importación: un modelo de 200 variantes no debe soltarlo a medias.
        $grupo = Cache::lock("producto-codigo:{$empresaId}", 60)->block(10, function () use (
            $usuario, $datos, $grupo, $sucursalId, $empresaId, $atributos, $filas, $precioBase, $costo, $puedePrecios, $puedeStock, $afectacion, $omitidasAntes, &$cambiosPrecio,
        ) {
            return DB::transaction(function () use (
                $usuario, $datos, $grupo, $sucursalId, $empresaId, $atributos, $filas, $precioBase, $costo, $puedePrecios, $puedeStock, $afectacion, $omitidasAntes, &$cambiosPrecio,
            ) {
                // el siguiente código se calcula una vez bajo el candado y se va sumando en memoria
                $siguienteCodigo = (int) substr(Producto::siguienteCodigo($empresaId), 1);
                $omitidas = [];

                $grupo ??= new ProductoGrupo(['empresa_id' => $empresaId]);
                $grupo->fill([
                    'nombre' => trim((string) $datos['nombre']),
                    'categoria_id' => $datos['categoria_id'] ?? null,
                    'marca_id' => $datos['marca_id'] ?? null,
                    'atributos' => $atributos,
                    'activo' => true,
                ])->save();

                $existentes = $grupo->variantes()->with('presentaciones')->get();
                $porClave = $existentes->keyBy(fn (Producto $p) => self::clave(self::valoresDe($p, $atributos)));

                // la unidad se fija al crear el modelo: las variantes nuevas siguen la de las que ya existen
                $unidad = $existentes->first()?->unidad_base_codigo ?? $datos['unidad_base_codigo'];
                $nombreUnidad = UnidadMedida::where('codigo', $unidad)->value('nombre') ?? 'Unidad';

                $vigentes = [];
                foreach (self::combinaciones($atributos) as $orden => $valores) {
                    $clave = self::clave($valores);
                    $fila = $filas->get($clave);
                    $omitir = $fila ? (bool) ($fila['omitir'] ?? false) : in_array($clave, $omitidasAntes, true);
                    if ($omitir) {
                        $omitidas[] = $valores;
                    }
                    /** @var Producto|null $variante */
                    $variante = $porClave->get($clave);

                    $comunes = [
                        'nombre' => trim($grupo->nombre.' '.implode(' ', $valores)),
                        'categoria_id' => $grupo->categoria_id,
                        'marca_id' => $grupo->marca_id,
                        'tipo_afectacion_codigo' => $afectacion,
                        'atributos' => array_combine(array_column($atributos, 'nombre'), $valores),
                        'variante_orden' => $orden,
                    ];

                    if ($variante) {
                        $vigentes[] = $variante->id;

                        if ($omitir) {
                            // quitada a mano de la tabla: fuera si estaba a la venta; una ya inactiva se deja como está
                            if ($variante->activo) {
                                $this->retirar($variante);
                            }

                            continue;
                        }

                        $variante->fill([...$comunes, 'activo' => true])->save();

                        $presentacion = $variante->presentaciones->firstWhere('es_default', true) ?? $variante->presentaciones->first();
                        if ($presentacion && $fila) {
                            $cambios = ['codigo_barras' => $fila['codigo_barras'] ?? null];
                            $precio = isset($fila['precio_venta']) ? (float) $fila['precio_venta'] : null;

                            if ($precio !== null && abs((float) $presentacion->precio_venta - $precio) >= 0.005) {
                                // un almacenero mantiene el catálogo, pero los precios de lista los fija quien tiene el permiso
                                if (! $puedePrecios) {
                                    throw new ErrorDeNegocio('Tu rol no puede cambiar precios de venta. Pide a un administrador que lo haga.');
                                }
                                $cambiosPrecio[] = [
                                    'producto_id' => $variante->id,
                                    'producto' => $variante->nombre,
                                    'cambios' => [['presentacion' => $presentacion->nombre, 'de' => (float) $presentacion->precio_venta, 'a' => $precio]],
                                ];
                                $cambios['precio_venta'] = $precio;
                            }
                            $presentacion->update($cambios);
                        }

                        continue;
                    }

                    if ($omitir) {
                        continue;
                    }

                    // variante nueva: un producto como el que crea el formulario de productos
                    $variante = new Producto([
                        'empresa_id' => $empresaId,
                        'grupo_id' => $grupo->id,
                        'unidad_base_codigo' => $unidad,
                        'permite_fraccion' => false,
                        'controla_lote' => false,
                        'controla_stock' => true,
                        'stock_minimo' => 0,
                        'activo' => true,
                        ...$comunes,
                    ]);
                    $variante->codigo_interno = 'P'.str_pad((string) $siguienteCodigo++, 4, '0', STR_PAD_LEFT);
                    $variante->save();

                    $variante->presentaciones()->create([
                        'empresa_id' => $empresaId,
                        'nombre' => $nombreUnidad,
                        'unidad_codigo' => $unidad,
                        'factor_conversion' => 1,
                        'precio_venta' => isset($fila['precio_venta']) ? (float) $fila['precio_venta'] : $precioBase,
                        'codigo_barras' => $fila['codigo_barras'] ?? null,
                        'es_default' => true,
                        'activo' => true,
                    ]);
                    $this->resumen['creadas']++;

                    // stock inicial: entra como ajuste con su capa FIFO, igual que al crear un producto
                    $stock = round((float) ($fila['stock_inicial'] ?? 0), 3);
                    if ($stock > 0 && $puedeStock) {
                        $this->inventario->ingresarCapa($variante, $sucursalId, $stock, $costo);
                        $this->inventario->incrementarStock($empresaId, $variante->id, $sucursalId, $stock);
                        $this->inventario->registrarMovimiento(
                            $empresaId, $sucursalId, $variante->id, 'ajuste', $stock, $costo, usuarioId: $usuario->id,
                            observacion: 'Stock inicial al crear la variante',
                        );
                        $this->resumen['stock'] += $stock;
                    }
                }

                // lo que ya no está en la definición (se quitó un valor): fuera de la venta
                foreach ($existentes as $variante) {
                    if (! in_array($variante->id, $vigentes, true) && $variante->activo) {
                        $this->retirar($variante);
                    }
                }

                $grupo->update(['omitidas' => $omitidas]);

                return $grupo;
            });
        });

        if ($esNuevo) {
            Auditoria::registrar($usuario, 'producto_grupo.creado', 'producto_grupo', $grupo->id, [
                'modelo' => $grupo->nombre,
                'variantes' => $this->resumen['creadas'],
            ]);
        }

        // constancia de los cambios de precio, como en el producto (fuera de la transacción: ya quedó guardado)
        foreach ($cambiosPrecio as $cambio) {
            Auditoria::registrar($usuario, 'producto.precio_actualizado', 'producto', $cambio['producto_id'], [
                'producto' => $cambio['producto'],
                'cambios' => $cambio['cambios'],
            ]);
        }

        return $grupo;
    }

    /**
     * Elimina el modelo con todas sus variantes (borrado lógico, como al eliminar un producto).
     * Con stock de por medio no se elimina: esas unidades quedarían en el inventario sin producto visible.
     */
    public function eliminarGrupo(Usuario $usuario, ProductoGrupo $grupo): int
    {
        $eliminadas = DB::transaction(function () use ($grupo) {
            $variantes = $grupo->variantes()->get();

            $conStock = DB::table('stock')->whereIn('producto_id', $variantes->pluck('id'))->where('cantidad', '<>', 0);
            if ($conStock->exists()) {
                $unidades = rtrim(rtrim(number_format((float) $conStock->sum('cantidad'), 3, '.', ''), '0'), '.');

                throw new ErrorDeNegocio("Este modelo tiene {$unidades} unidades en stock. Véndelas, transfiérelas o ajústalas a cero antes de eliminarlo.");
            }

            $variantes->each->delete();
            $grupo->delete();

            return $variantes->count();
        });

        Auditoria::registrar($usuario, 'producto_grupo.eliminado', 'producto_grupo', $grupo->id, [
            'modelo' => $grupo->nombre,
            'variantes' => $eliminadas,
        ]);

        return $eliminadas;
    }

    /** ¿El producto ya se compró, vendió, cotizó, movió o tiene stock? */
    public function tieneMovimientos(Producto $producto): bool
    {
        foreach (self::TABLAS_CON_MOVIMIENTOS as $tabla) {
            if (DB::table($tabla)->where('producto_id', $producto->id)->exists()) {
                return true;
            }
        }

        return DB::table('stock')->where('producto_id', $producto->id)->where('cantidad', '<>', 0)->exists();
    }

    /** Saca una variante de la venta: con movimientos se desactiva (su historial sigue intacto); sin ninguno, se elimina. */
    private function retirar(Producto $variante): void
    {
        if ($this->tieneMovimientos($variante)) {
            $variante->update(['activo' => false]);
            $this->resumen['desactivadas']++;

            return;
        }

        $variante->delete();
        $this->resumen['eliminadas']++;
    }
}
