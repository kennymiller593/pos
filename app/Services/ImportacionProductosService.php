<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Carga masiva de productos desde Excel: plantilla, vista previa fila por
 * fila (sin tocar la BD) e importación de las filas válidas.
 *
 * Una fila = un producto. Solo nombre y precio_venta son obligatorios.
 * Si el codigo de barras (o el nombre exacto) ya existe, la fila actualiza
 * ese producto en vez de duplicarlo; el stock inicial solo entra en los nuevos.
 */
class ImportacionProductosService
{
    public const MAX_FILAS = 2000;

    /** Columnas de la plantilla, en orden. */
    public const COLUMNAS = [
        'nombre', 'precio_venta', 'precio_compra', 'stock_inicial', 'codigo_barras', 'categoria', 'marca',
        'unidad', 'stock_minimo', 'afecto_igv', 'permite_fraccion', 'precio_mayorista', 'cantidad_mayorista',
        'presentacion', 'factor', 'precio_presentacion', 'precio_compra_presentacion', 'codigo_barras_presentacion',
    ];

    private const OBLIGATORIAS = ['nombre', 'precio_venta'];

    private const AFECTACION = ['SI' => '10', 'GRAVADO' => '10', 'NO' => '20', 'EXONERADO' => '20', 'INAFECTO' => '30'];

    public function __construct(private readonly InventarioService $inventario) {}

    // ------------------------------------------------------------------
    // Plantilla

    public function plantilla(): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet()->setTitle('Productos');

        $hoja->fromArray(self::COLUMNAS, null, 'A1');
        $hoja->fromArray([
            ['Cable UTP Cat6', 1.20, '', 305, '', 'Redes', 'Dixon', 'Metro', 20, 'SI', 'SI', 1.00, 50, 'Caja 305 m', 305, 190.00, 142.30, ''],
            ['Gaseosa Inca Kola 500 ml', 2.50, 1.60, 48, '7750182001234', 'Bebidas', 'Inca Kola', 'Unidad', 12, 'SI', 'NO', '', '', 'Paquete x6', 6, 14.00, '', ''],
            ['Arroz Costeño 1 kg', 4.80, 3.90, 0, '', 'Abarrotes', 'Costeño', 'Unidad', 0, 'EXONERADO', 'NO', '', '', '', '', '', '', ''],
        ], null, 'A2');

        $ultima = chr(ord('A') + count(self::COLUMNAS) - 1);
        $hoja->getStyle("A1:{$ultima}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $hoja->getStyle("A1:{$ultima}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('059669');
        $hoja->getStyle('A1:B1')->getFill()->getStartColor()->setRGB('B45309'); // obligatorias en ambar
        $hoja->freezePane('B2');
        foreach (range('A', $ultima) as $col) {
            $hoja->getColumnDimension($col)->setAutoSize(true);
        }

        // listas desplegables en unidad, afecto_igv y permite_fraccion
        $unidades = UnidadMedida::orderBy('nombre')->pluck('nombre')->implode(',');
        $listas = [
            'H' => $unidades,
            'J' => 'SI,EXONERADO,INAFECTO',
            'K' => 'SI,NO',
        ];
        foreach ($listas as $col => $valores) {
            $validacion = $hoja->getCell("{$col}2")->getDataValidation();
            $validacion->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)
                ->setShowErrorMessage(true)->setErrorTitle('Valor no válido')->setError('Elige un valor de la lista.')
                ->setFormula1('"'.$valores.'"');
            $hoja->setDataValidation("{$col}2:{$col}".(self::MAX_FILAS + 1), clone $validacion);
        }

        $ayuda = $libro->createSheet()->setTitle('Instrucciones');
        $ayuda->fromArray(array_map(fn ($l) => [$l], [
            'Cómo llenar la plantilla',
            '',
            'Una fila por producto. Solo "nombre" y "precio_venta" son obligatorias (columnas en ámbar).',
            'Precios con IGV, en soles, con punto o coma decimal (1.20 o 1,20).',
            'precio_venta / precio_compra: por la unidad base (el metro, la unidad, el kilo).',
            'stock_inicial: cantidad en unidades base; entra a la sucursal activa con su precio_compra.',
            'unidad: Unidad, Metro, Kilogramo, Litro, etc. Vacío = Unidad.',
            'afecto_igv: SI (gravado), EXONERADO o INAFECTO. Vacío = SI.',
            'permite_fraccion: SI si vendes medios (metros de cable, arroz a granel). Vacío = NO.',
            'categoria y marca: si no existen, se crean solas.',
            'presentacion: otra forma de vender el mismo producto (Caja, Paquete). factor = cuántas unidades base trae (Caja 305 m → 305).',
            'precio_presentacion: precio de venta de esa caja/paquete completo.',
            'precio_compra_presentacion: si solo conoces el precio de compra por caja, ponlo aquí y el sistema calcula el de la unidad.',
            'Si el código de barras o el nombre ya existen, la fila ACTUALIZA ese producto (no lo duplica).',
            'El código interno (P0001...) lo asigna el sistema. Máximo '.self::MAX_FILAS.' filas por archivo.',
            'Las filas de ejemplo se pueden borrar.',
        ]));
        $ayuda->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ayuda->getColumnDimension('A')->setWidth(110);

        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    // ------------------------------------------------------------------
    // Vista previa

    /**
     * Lee el archivo y evalúa cada fila sin escribir nada. Guarda el resultado
     * 30 min en caché para importarlo después con el token devuelto.
     *
     * @return array{token: string, filas: list<array>, resumen: array}
     *
     * @throws ErrorDeNegocio
     */
    public function previsualizar(string $ruta, Usuario $usuario): array
    {
        $filas = $this->leer($ruta);
        $contexto = $this->contexto($usuario->empresa_id);
        $vistas = [];
        $barrasEnArchivo = [];
        $nombresEnArchivo = [];

        foreach ($filas as $numero => $celdas) {
            $resultado = $this->evaluarFila($numero, $celdas, $contexto, $usuario);

            // duplicados dentro del mismo archivo
            foreach ($resultado['datos']['codigos_barras'] ?? [] as $barras) {
                if (isset($barrasEnArchivo[$barras])) {
                    $resultado['errores'][] = "El código de barras {$barras} se repite en la fila {$barrasEnArchivo[$barras]}.";
                }
                $barrasEnArchivo[$barras] = $numero;
            }
            $claveNombre = mb_strtolower(trim((string) ($resultado['datos']['nombre'] ?? '')));
            if ($claveNombre !== '' && $resultado['accion'] === 'crear') {
                if (isset($nombresEnArchivo[$claveNombre])) {
                    $resultado['errores'][] = "El producto se repite en la fila {$nombresEnArchivo[$claveNombre]}.";
                }
                $nombresEnArchivo[$claveNombre] = $numero;
            }

            $resultado['estado'] = $resultado['errores'] ? 'error' : ($resultado['advertencias'] ? 'advertencia' : 'ok');
            $vistas[] = $resultado;
        }

        $token = (string) Str::uuid();
        Cache::put($this->claveCache($usuario, $token), $vistas, now()->addMinutes(30));

        $validas = array_filter($vistas, fn ($f) => $f['estado'] !== 'error');

        return [
            'token' => $token,
            'filas' => array_map(fn ($f) => [
                'fila' => $f['fila'],
                'nombre' => $f['datos']['nombre'] ?? '',
                'precio_venta' => $f['datos']['precio_venta'] ?? null,
                'precio_compra' => $f['datos']['precio_compra'] ?? null,
                'stock_inicial' => $f['datos']['stock_inicial'] ?? null,
                'unidad' => $f['datos']['unidad_nombre'] ?? null,
                'presentaciones' => array_column($f['datos']['presentaciones'] ?? [], 'nombre'),
                'accion' => $f['accion'],
                'estado' => $f['estado'],
                'errores' => $f['errores'],
                'advertencias' => $f['advertencias'],
            ], $vistas),
            'resumen' => [
                'total' => count($vistas),
                'crear' => count(array_filter($validas, fn ($f) => $f['accion'] === 'crear')),
                'actualizar' => count(array_filter($validas, fn ($f) => $f['accion'] === 'actualizar')),
                'errores' => count($vistas) - count($validas),
                'advertencias' => count(array_filter($vistas, fn ($f) => $f['estado'] === 'advertencia')),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Importación

    /**
     * Crea/actualiza las filas válidas de una vista previa.
     *
     * @return array{creados: int, actualizados: int, omitidos: int}
     *
     * @throws ErrorDeNegocio
     */
    public function importar(string $token, Usuario $usuario, string $sucursalId): array
    {
        $vistas = Cache::pull($this->claveCache($usuario, $token));

        if (! is_array($vistas)) {
            throw new ErrorDeNegocio('La vista previa venció o ya se importó. Vuelve a subir el archivo.');
        }

        $validas = array_values(array_filter($vistas, fn ($f) => $f['estado'] !== 'error'));
        $empresaId = $usuario->empresa_id;
        $puedeStock = $usuario->can('stock.ajustar');
        $puedePrecios = $usuario->can('productos.precios');
        $creados = 0;
        $actualizados = 0;

        Cache::lock("producto-codigo:{$empresaId}", 60)->block(10, function () use ($validas, $empresaId, $usuario, $sucursalId, $puedeStock, $puedePrecios, &$creados, &$actualizados) {
            DB::transaction(function () use ($validas, $empresaId, $usuario, $sucursalId, $puedeStock, $puedePrecios, &$creados, &$actualizados) {
                $categorias = Categoria::where('empresa_id', $empresaId)->get()->keyBy(fn ($c) => mb_strtolower($c->nombre));
                $marcas = Marca::where('empresa_id', $empresaId)->get()->keyBy(fn ($m) => mb_strtolower($m->nombre));
                $siguiente = (int) substr(Producto::siguienteCodigo($empresaId), 1);

                foreach ($validas as $fila) {
                    $d = $fila['datos'];
                    $categoriaId = $this->obtenerCatalogo($categorias, Categoria::class, $empresaId, $d['categoria']);
                    $marcaId = $this->obtenerCatalogo($marcas, Marca::class, $empresaId, $d['marca']);

                    if ($fila['accion'] === 'actualizar') {
                        $this->actualizar($d, $categoriaId, $marcaId, $puedePrecios);
                        $actualizados++;

                        continue;
                    }

                    $producto = Producto::create([
                        'empresa_id' => $empresaId,
                        'codigo_interno' => 'P'.str_pad((string) $siguiente++, 4, '0', STR_PAD_LEFT),
                        'nombre' => $d['nombre'],
                        'categoria_id' => $categoriaId,
                        'marca_id' => $marcaId,
                        'unidad_base_codigo' => $d['unidad_codigo'],
                        'tipo_afectacion_codigo' => $d['afectacion'],
                        'permite_fraccion' => $d['permite_fraccion'],
                        'controla_lote' => false,
                        'controla_stock' => true,
                        'stock_minimo' => $d['stock_minimo'],
                        'activo' => true,
                    ]);

                    foreach ($d['presentaciones'] as $p) {
                        $producto->presentaciones()->create([...$p, 'empresa_id' => $empresaId, 'activo' => true]);
                    }

                    if ($puedeStock && $d['stock_inicial'] > 0) {
                        $costo = (float) ($d['precio_compra'] ?? 0);
                        $this->inventario->ingresarCapa($producto, $sucursalId, $d['stock_inicial'], $costo);
                        $this->inventario->incrementarStock($empresaId, $producto->id, $sucursalId, $d['stock_inicial']);
                        $this->inventario->registrarMovimiento($empresaId, $sucursalId, $producto->id, 'ajuste', $d['stock_inicial'], $costo, usuarioId: $usuario->id);
                    }

                    $creados++;
                }

                Auditoria::registrar($usuario, 'productos.importados', 'producto', null, [
                    'creados' => $creados,
                    'actualizados' => $actualizados,
                    'omitidos' => 0,
                ]);
            });
        });

        return ['creados' => $creados, 'actualizados' => $actualizados, 'omitidos' => count($vistas) - count($validas)];
    }

    // ------------------------------------------------------------------

    /** @return array<int, array<string, string>> filas indexadas por número de fila de Excel */
    private function leer(string $ruta): array
    {
        try {
            $lector = IOFactory::createReaderForFile($ruta);
            $lector->setReadDataOnly(true);
            $hoja = $lector->load($ruta)->getSheet(0);
        } catch (\Throwable) {
            throw new ErrorDeNegocio('No se pudo leer el archivo. Usa la plantilla en formato .xlsx.');
        }

        $datos = $hoja->toArray(null, true, false, false);
        $cabecera = array_map(fn ($c) => Str::snake(Str::ascii(trim((string) $c))), array_shift($datos) ?? []);

        $faltan = array_diff(self::OBLIGATORIAS, $cabecera);
        if ($faltan) {
            throw new ErrorDeNegocio('Faltan las columnas: '.implode(', ', $faltan).'. Descarga y usa la plantilla.');
        }

        $filas = [];
        foreach ($datos as $i => $valores) {
            $fila = [];
            foreach ($cabecera as $j => $columna) {
                if ($columna !== '') {
                    $fila[$columna] = trim((string) ($valores[$j] ?? ''));
                }
            }
            if (implode('', $fila) === '') {
                continue; // fila vacia
            }
            $filas[$i + 2] = $fila;
        }

        if ($filas === []) {
            throw new ErrorDeNegocio('El archivo no tiene productos.');
        }

        if (count($filas) > self::MAX_FILAS) {
            throw new ErrorDeNegocio('El archivo tiene '.count($filas).' filas; el máximo por archivo es '.self::MAX_FILAS.'. Divídelo en partes.');
        }

        return $filas;
    }

    private function contexto(string $empresaId): array
    {
        $unidades = [];
        foreach (UnidadMedida::all(['codigo', 'nombre']) as $u) {
            $unidades[mb_strtolower(Str::ascii($u->nombre))] = $u;
            $unidades[mb_strtolower($u->codigo)] = $u;
        }

        return [
            'empresa_id' => $empresaId,
            'es_rus' => (bool) Empresa::find($empresaId)?->esRus(),
            'unidades' => $unidades,
            'por_barras' => ProductoPresentacion::query()
                ->where('empresa_id', $empresaId)->whereNotNull('codigo_barras')->where('codigo_barras', '!=', '')
                ->pluck('producto_id', 'codigo_barras')->all(),
            'por_nombre' => Producto::query()->where('empresa_id', $empresaId)
                ->get(['id', 'nombre'])->mapWithKeys(fn ($p) => [mb_strtolower(trim($p->nombre)) => $p->id])->all(),
        ];
    }

    private function evaluarFila(int $numero, array $c, array $ctx, Usuario $usuario): array
    {
        $errores = [];
        $advertencias = [];
        $num = fn (string $col) => $this->numero($c[$col] ?? '', $col, $errores);

        $nombre = mb_substr($c['nombre'] ?? '', 0, 200);
        if ($nombre === '') {
            $errores[] = 'Falta el nombre.';
        }

        $precioVenta = $num('precio_venta');
        if ($precioVenta === null) {
            $errores[] = 'Falta el precio de venta.';
        } elseif ($precioVenta <= 0) {
            $errores[] = 'El precio de venta debe ser mayor a 0.';
        }

        $unidadTexto = mb_strtolower(Str::ascii($c['unidad'] ?? ''));
        $unidad = $unidadTexto === '' ? ($ctx['unidades']['niu'] ?? null) : ($ctx['unidades'][$unidadTexto] ?? $ctx['unidades'][rtrim($unidadTexto, 's')] ?? null);
        if (! $unidad) {
            $errores[] = "La unidad \"{$c['unidad']}\" no existe (usa Unidad, Metro, Kilogramo, Litro...).";
        }

        $afectoTexto = mb_strtoupper(Str::ascii($c['afecto_igv'] ?? ''));
        $afectacion = $afectoTexto === '' ? '10' : (self::AFECTACION[$afectoTexto] ?? null);
        if (! $afectacion) {
            $errores[] = "afecto_igv debe ser SI, EXONERADO o INAFECTO (vino \"{$c['afecto_igv']}\").";
        } elseif ($ctx['es_rus'] && $afectacion === '10') {
            $afectacion = Empresa::AFECTACION_RUS; // el Nuevo RUS no discrimina IGV
        }

        $fraccionTexto = mb_strtoupper(Str::ascii($c['permite_fraccion'] ?? ''));
        if (! in_array($fraccionTexto, ['', 'SI', 'NO'], true)) {
            $errores[] = 'permite_fraccion debe ser SI o NO.';
        }
        $permiteFraccion = $fraccionTexto === 'SI';

        $stockInicial = $num('stock_inicial') ?? 0.0;
        if ($stockInicial < 0) {
            $errores[] = 'El stock inicial no puede ser negativo.';
        } elseif (! $permiteFraccion && fmod($stockInicial, 1) != 0) {
            $errores[] = 'Stock con decimales: marca permite_fraccion = SI.';
        }

        $stockMinimo = $num('stock_minimo') ?? 0.0;
        $precioMayorista = $num('precio_mayorista');
        $cantidadMayorista = $num('cantidad_mayorista');
        if (($precioMayorista === null) !== ($cantidadMayorista === null)) {
            $errores[] = 'Para el precio mayorista indica precio_mayorista y cantidad_mayorista.';
        }

        $barras = $c['codigo_barras'] ?? '';
        $presentaciones = [[
            'nombre' => 'Unidad',
            'unidad_codigo' => $unidad?->codigo ?? 'NIU',
            'factor_conversion' => 1,
            'precio_venta' => $precioVenta,
            'precio_mayorista' => $precioMayorista,
            'cantidad_mayorista' => $cantidadMayorista,
            'codigo_barras' => $barras ?: null,
            'es_default' => true,
        ]];

        // precio de compra de la unidad base; si solo vino el de la presentacion 2, se deriva
        $precioCompra = $num('precio_compra');

        // una sola presentacion extra (caja, paquete...) para no complicar la plantilla
        $nombreP = mb_substr($c['presentacion'] ?? '', 0, 80);
        $factor = $num('factor');
        $precio = $num('precio_presentacion');

        if ($nombreP !== '' || $factor !== null || $precio !== null) {
            if ($nombreP === '' || ! $factor || $factor <= 0 || ! $precio || $precio <= 0) {
                $errores[] = 'Presentación: indica nombre, factor (> 0) y precio_presentacion (> 0).';
            } else {
                $presentaciones[] = [
                    'nombre' => $nombreP,
                    'unidad_codigo' => $unidad?->codigo ?? 'NIU',
                    'factor_conversion' => $factor,
                    'precio_venta' => $precio,
                    'precio_mayorista' => null,
                    'cantidad_mayorista' => null,
                    'codigo_barras' => ($c['codigo_barras_presentacion'] ?? '') ?: null,
                    'es_default' => false,
                ];

                if ($precioCompra === null && ($compraCaja = $num('precio_compra_presentacion')) !== null && $compraCaja > 0) {
                    $precioCompra = round($compraCaja / $factor, 6);
                }
            }
        }

        $codigosBarras = array_values(array_filter(array_column($presentaciones, 'codigo_barras')));

        // ¿existe ya? por codigo de barras y, si no, por nombre exacto
        $existenteId = null;
        foreach ($codigosBarras as $b) {
            if (isset($ctx['por_barras'][$b])) {
                $existenteId = $ctx['por_barras'][$b];
                break;
            }
        }
        $existenteId ??= $ctx['por_nombre'][mb_strtolower(trim($nombre))] ?? null;
        $accion = $existenteId ? 'actualizar' : 'crear';

        if ($accion === 'crear') {
            if ($stockInicial > 0 && $precioCompra === null) {
                $advertencias[] = 'Sin precio de compra: el stock inicial entra sin costo y el margen no se calculará.';
            }
            if ($stockInicial > 0 && ! $usuario->can('stock.ajustar')) {
                $advertencias[] = 'Tu rol no puede cargar stock: el producto se crea con stock 0.';
            }
        } else {
            if ($stockInicial > 0) {
                $advertencias[] = 'El producto ya existe: se actualizan sus datos, pero el stock inicial se ignora (usa un ajuste de stock).';
            }
            if (! $usuario->can('productos.precios')) {
                $advertencias[] = 'Tu rol no puede cambiar precios: se actualizan los demás datos.';
            }
        }

        if ($precioCompra !== null && $precioVenta && $precioCompra >= $precioVenta) {
            $advertencias[] = 'El precio de compra es mayor o igual al de venta (venderías a pérdida).';
        }

        return [
            'fila' => $numero,
            'accion' => $accion,
            'errores' => $errores,
            'advertencias' => $advertencias,
            'datos' => [
                'producto_id' => $existenteId,
                'nombre' => $nombre,
                'precio_venta' => $precioVenta,
                'precio_compra' => $precioCompra,
                'stock_inicial' => $stockInicial,
                'stock_minimo' => $stockMinimo,
                'unidad_codigo' => $unidad?->codigo,
                'unidad_nombre' => $unidad?->nombre,
                'afectacion' => $afectacion,
                'permite_fraccion' => $permiteFraccion,
                'categoria' => mb_substr($c['categoria'] ?? '', 0, 100),
                'marca' => mb_substr($c['marca'] ?? '', 0, 100),
                'presentaciones' => $presentaciones,
                'codigos_barras' => $codigosBarras,
            ],
        ];
    }

    /** Actualiza un producto existente sin tocar su stock ni el factor de sus presentaciones. */
    private function actualizar(array $d, ?string $categoriaId, ?string $marcaId, bool $puedePrecios): void
    {
        $producto = Producto::with('presentaciones')->find($d['producto_id']);
        if (! $producto) {
            return;
        }

        $producto->update(array_filter([
            'categoria_id' => $categoriaId,
            'marca_id' => $marcaId,
            'stock_minimo' => $d['stock_minimo'],
            'tipo_afectacion_codigo' => $d['afectacion'],
            'permite_fraccion' => $d['permite_fraccion'],
        ], fn ($v) => $v !== null));

        foreach ($d['presentaciones'] as $p) {
            $actual = $p['es_default']
                ? $producto->presentaciones->firstWhere('es_default', true)
                : $producto->presentaciones->first(fn ($x) => mb_strtolower($x->nombre) === mb_strtolower($p['nombre']));

            if (! $actual) {
                $producto->presentaciones()->create([...$p, 'empresa_id' => $producto->empresa_id, 'activo' => true, 'es_default' => false]);

                continue;
            }

            $cambios = ['codigo_barras' => $actual->codigo_barras ?: $p['codigo_barras']];
            if ($puedePrecios) {
                $cambios += [
                    'precio_venta' => $p['precio_venta'],
                    'precio_mayorista' => $p['precio_mayorista'],
                    'cantidad_mayorista' => $p['cantidad_mayorista'],
                ];
            }
            $actual->update($cambios);
        }
    }

    private function obtenerCatalogo(Collection $existentes, string $modelo, string $empresaId, string $nombre): ?string
    {
        if ($nombre === '') {
            return null;
        }

        $clave = mb_strtolower($nombre);
        if (! $existentes->has($clave)) {
            $existentes->put($clave, $modelo::create(['empresa_id' => $empresaId, 'nombre' => $nombre]));
        }

        return $existentes->get($clave)->id;
    }

    /** Número con punto o coma decimal; null si la celda está vacía. */
    private function numero(string $valor, string $columna, array &$errores): ?float
    {
        $valor = str_replace([' ', 'S/', 's/'], '', $valor);
        if ($valor === '') {
            return null;
        }
        if (str_contains($valor, ',') && ! str_contains($valor, '.')) {
            $valor = str_replace(',', '.', $valor);
        }
        $valor = str_replace(',', '', $valor);

        if (! is_numeric($valor)) {
            $errores[] = "{$columna} no es un número (\"{$valor}\").";

            return null;
        }

        return round((float) $valor, 6);
    }

    private function claveCache(Usuario $usuario, string $token): string
    {
        return "importacion-productos:{$usuario->id}:{$token}";
    }
}
