<?php

namespace App\Http\Controllers;

use App\Models\Comprobante;
use App\Models\ComprobanteDetalle;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Services\ReportesNegocioService;
use App\Support\ExportadorExcel;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReporteController extends Controller
{
    private const ENTRADAS = ['compra', 'devolucion', 'ajuste', 'transferencia_entrada'];

    private const TIPOS_KARDEX = [
        'compra' => 'Compra',
        'venta' => 'Venta',
        'ajuste' => 'Ajuste (entrada)',
        'merma' => 'Merma / salida',
        'devolucion' => 'Devolución (anulación)',
        'transferencia_entrada' => 'Transferencia entrada',
        'transferencia_salida' => 'Transferencia salida',
    ];

    /**
     * Los reportes disponibles, agrupados como se muestran en pantalla.
     * sin_fechas = foto de hoy (no usa el rango) · producto = pide elegir un producto · agrupar = por día, semana o mes.
     */
    public const CATALOGO = [
        ['grupo' => 'Ventas', 'reportes' => [
            ['valor' => 'ventas', 'label' => 'Detalle de ventas', 'descripcion' => 'Cada comprobante emitido, con su cliente, condición y vendedor.'],
            ['valor' => 'productos', 'label' => 'Por producto', 'descripcion' => 'Qué productos se venden más y cuánto pesa cada uno en tu venta.'],
            ['valor' => 'categorias', 'label' => 'Por categoría', 'descripcion' => 'Venta, costo y utilidad de cada categoría de productos.'],
            ['valor' => 'vendedores', 'label' => 'Por vendedor', 'descripcion' => 'Cuánto vendió cada persona, su ticket promedio y la utilidad que dejó.'],
            ['valor' => 'horas', 'label' => 'Por hora', 'descripcion' => 'A qué horas del día vendes más, para organizar a tu personal.'],
            ['valor' => 'dias', 'label' => 'Por día de la semana', 'descripcion' => 'Qué días de la semana son los más fuertes.'],
        ]],
        ['grupo' => 'Ganancias', 'reportes' => [
            ['valor' => 'utilidad', 'label' => 'Utilidad por período', 'descripcion' => 'Cuánto vendiste, cuánto te costó y cuánto ganaste por día, semana o mes.', 'agrupar' => true],
            ['valor' => 'margen', 'label' => 'Margen por producto', 'descripcion' => 'Qué productos te dejan más ganancia.'],
        ]],
        ['grupo' => 'Clientes', 'reportes' => [
            ['valor' => 'clientes', 'label' => 'Los que más compran', 'descripcion' => 'Tus mejores clientes: cuántas veces compraron, cuánto y cuándo fue la última vez.'],
            ['valor' => 'cobranza', 'label' => 'Cuentas por cobrar', 'descripcion' => 'Quién te debe hoy y cuánto tiempo lleva cada deuda.', 'sin_fechas' => true],
        ]],
        ['grupo' => 'Caja', 'reportes' => [
            ['valor' => 'caja', 'label' => 'Por medio de pago', 'descripcion' => 'Cuánto entró y salió en efectivo, Yape, tarjeta y los demás medios.'],
        ]],
        ['grupo' => 'Inventario', 'reportes' => [
            ['valor' => 'kardex', 'label' => 'Kardex', 'descripcion' => 'Entradas, salidas y saldo de un producto, movimiento por movimiento.', 'producto' => true],
        ]],
        ['grupo' => 'Contabilidad', 'reportes' => [
            ['valor' => 'libro', 'label' => 'Registro de ventas', 'descripcion' => 'Boletas, facturas y notas de crédito con el detalle que pide tu contador.'],
        ]],
    ];

    public function __construct(private readonly ReportesNegocioService $negocio) {}

    public function index(Request $request): Response
    {
        [$tipo, $desde, $hasta, $productoId, $agrupar] = $this->filtros($request);
        $datos = $this->construir($request, $tipo, $desde, $hasta, $productoId, $agrupar);

        return Inertia::render('Reportes/Index', [
            // agrupar: lo que se aplicó de verdad (si no se eligió, sale del largo del rango)
            'filtros' => ['tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta, 'producto_id' => $productoId, 'agrupar' => $datos['agrupar'] ?? null],
            'catalogo' => self::CATALOGO,
            'datos' => $datos,
            'productos' => Producto::query()
                ->where('empresa_id', $request->user()->empresa_id)
                ->where('controla_stock', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'codigo_interno']),
        ]);
    }

    public function exportar(Request $request)
    {
        [$tipo, $desde, $hasta, $productoId, $agrupar] = $this->filtros($request);
        $formato = $request->query('formato') === 'pdf' ? 'pdf' : 'xlsx';

        $datos = $this->construir($request, $tipo, $desde, $hasta, $productoId, $agrupar);
        // los reportes que son una foto de hoy traen su propio periodo ("Al 03/10/2026")
        $porFechas = ! isset($datos['periodo']);
        $nombre = $porFechas ? "reporte-{$tipo}-{$desde}-a-{$hasta}" : "reporte-{$tipo}-".now()->toDateString();
        $subtitulo = ($datos['periodo'] ?? "Del {$desde} al {$hasta}").($datos['contexto'] ?? null ? " · {$datos['contexto']}" : '');

        if ($formato === 'xlsx') {
            return (new ExportadorExcel)
                ->hoja($datos['titulo'], $datos['titulo'], $subtitulo, $datos['columnas'], $datos['filas'], $datos['resumen'])
                ->descargar($nombre);
        }

        return SnappyPdf::loadView('pdf.reporte', [
            'empresa' => $request->user()->empresa,
            'titulo' => $datos['titulo'],
            'subtitulo' => $subtitulo,
            'columnas' => $datos['columnas'],
            'filas' => $datos['filas'],
            'resumen' => $datos['resumen'],
        ])
            ->setOption('encoding', 'utf-8')
            ->setOption('page-size', 'A4')
            ->setOption('orientation', count($datos['columnas']) > 6 ? 'Landscape' : 'Portrait')
            ->download("{$nombre}.pdf");
    }

    // ---------------------------------------------------------------

    private function filtros(Request $request): array
    {
        // fechas invalidas o rangos de mas de un ano no llegan a la consulta
        $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde', function ($atributo, $valor, $falla) use ($request) {
                $desde = $request->query('desde');
                if ($desde && $valor && Carbon::parse($desde)->diffInDays(Carbon::parse($valor)) > 366) {
                    $falla('El rango no puede superar un año.');
                }
            }],
            'producto_id' => ['nullable', 'uuid'],
        ], [
            'desde.date' => 'La fecha inicial no es válida.',
            'hasta.date' => 'La fecha final no es válida.',
            'hasta.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
        ]);

        $tipos = collect(self::CATALOGO)->flatMap(fn ($grupo) => array_column($grupo['reportes'], 'valor'));
        $tipo = $tipos->contains($request->query('tipo')) ? $request->query('tipo') : 'ventas';

        $desde = $request->query('desde') ?: now()->startOfMonth()->toDateString();
        $hasta = $request->query('hasta') ?: now()->toDateString();

        $agrupar = in_array($request->query('agrupar'), ['dia', 'semana', 'mes'], true) ? $request->query('agrupar') : null;

        return [$tipo, $desde, $hasta, $request->query('producto_id'), $agrupar];
    }

    private function construir(Request $request, string $tipo, string $desde, string $hasta, ?string $productoId, ?string $agrupar = null): array
    {
        $empresaId = $request->user()->empresa_id;
        $sucursalId = $this->sucursalConsultaId($request); // null = todas

        return match ($tipo) {
            'libro' => $this->reporteLibroVentas($request, $desde, $hasta),
            'margen' => $this->reporteMargen($request, $desde, $hasta),
            'kardex' => $this->reporteKardex($request, $desde, $hasta, $productoId),
            'productos' => $this->negocio->porProducto($empresaId, $sucursalId, $desde, $hasta),
            'categorias' => $this->negocio->porCategoria($empresaId, $sucursalId, $desde, $hasta),
            'vendedores' => $this->negocio->porVendedor($empresaId, $sucursalId, $desde, $hasta),
            'horas' => $this->negocio->porHora($empresaId, $sucursalId, $desde, $hasta),
            'dias' => $this->negocio->porDiaSemana($empresaId, $sucursalId, $desde, $hasta),
            'utilidad' => $this->negocio->utilidadPorPeriodo($empresaId, $sucursalId, $desde, $hasta, $agrupar),
            'clientes' => $this->negocio->clientesQueMasCompran($empresaId, $sucursalId, $desde, $hasta),
            'cobranza' => $this->negocio->cobranzaPorAntiguedad($empresaId, $sucursalId),
            'caja' => $this->negocio->cajaPorMedioDePago($empresaId, $sucursalId, $desde, $hasta),
            default => $this->reporteVentas($request, $desde, $hasta),
        };
    }

    /**
     * Registro de ventas con el detalle que pide el contador (y el PLE 14.1):
     * solo comprobantes electrónicos, anulados con importe cero, notas de
     * crédito en negativo y con el documento que modifican.
     */
    private function reporteLibroVentas(Request $request, string $desde, string $hasta): array
    {
        $comprobantes = Comprobante::query()
            ->where('empresa_id', $request->user()->empresa_id)
            ->when($this->sucursalConsultaId($request), fn ($q, $id) => $q->where('sucursal_id', $id))
            ->whereIn('tipo_comprobante_codigo', ['01', '03', '07'])
            ->whereBetween('fecha_emision', [$desde, $hasta])
            ->with([
                'sunat:comprobante_id,estado',
                'comprobanteRef:id,tipo_comprobante_codigo,serie,correlativo,fecha_emision',
            ])
            ->orderBy('fecha_emision')
            ->orderBy('tipo_comprobante_codigo')
            ->orderBy('serie')
            ->orderBy('correlativo')
            ->get();

        $estadosSunat = [
            'aceptado' => 'Aceptado', 'observado' => 'Aceptado con obs.', 'rechazado' => 'Rechazado',
            'pendiente' => 'Pendiente', 'baja_pendiente' => 'Baja en proceso', 'baja' => 'Dado de baja',
        ];

        $monto = function (Comprobante $c, string $campo): float {
            if ($c->estado === 'anulado') {
                return 0.0;
            }

            return ($c->tipo_comprobante_codigo === '07' ? -1 : 1) * (float) $c->{$campo};
        };

        $filas = $comprobantes->map(fn (Comprobante $c) => [
            $c->fecha_emision->format('d/m/Y'),
            $c->tipo_comprobante_codigo,
            $c->serie,
            str_pad($c->correlativo, 8, '0', STR_PAD_LEFT),
            trim((string) $c->cliente_tipo_doc) ?: '0',
            $c->cliente_numero_doc ?: '-',
            $c->estado === 'anulado' ? 'ANULADO' : ($c->cliente_nombre ?: 'CLIENTES VARIOS'),
            number_format($monto($c, 'total_gravado'), 2, '.', ''),
            number_format($monto($c, 'total_exonerado'), 2, '.', ''),
            number_format($monto($c, 'total_inafecto'), 2, '.', ''),
            number_format($monto($c, 'total_igv'), 2, '.', ''),
            number_format($monto($c, 'total'), 2, '.', ''),
            $c->estado === 'anulado' ? 'Anulado' : 'Emitido',
            $estadosSunat[$c->sunat?->estado ?? 'pendiente'] ?? '-',
            $c->comprobanteRef?->tipo_comprobante_codigo ?? '',
            $c->comprobanteRef ? "{$c->comprobanteRef->serie}-".str_pad($c->comprobanteRef->correlativo, 8, '0', STR_PAD_LEFT) : '',
            $c->comprobanteRef?->fecha_emision?->format('d/m/Y') ?? '',
        ])->values();

        $suma = fn (string $campo) => $comprobantes->sum(fn ($c) => $monto($c, $campo));

        return [
            'titulo' => 'Registro de ventas',
            'columnas' => [
                'Fecha', 'Tipo', 'Serie', 'Número', 'Tipo doc.', 'Nº doc.', 'Cliente',
                'Base gravada', 'Exonerado', 'Inafecto', 'IGV', 'Total', 'Estado', 'SUNAT',
                'Ref. tipo', 'Ref. número', 'Ref. fecha',
            ],
            'filas' => $filas,
            'resumen' => [
                ['etiqueta' => 'Comprobantes', 'valor' => (string) $comprobantes->count()],
                ['etiqueta' => 'Base gravada', 'valor' => 'S/ '.number_format($suma('total_gravado'), 2)],
                ['etiqueta' => 'Exonerado + inafecto', 'valor' => 'S/ '.number_format($suma('total_exonerado') + $suma('total_inafecto'), 2)],
                ['etiqueta' => 'IGV', 'valor' => 'S/ '.number_format($suma('total_igv'), 2)],
                ['etiqueta' => 'Total', 'valor' => 'S/ '.number_format($suma('total'), 2)],
            ],
        ];
    }

    private function reporteVentas(Request $request, string $desde, string $hasta): array
    {
        $comprobantes = Comprobante::query()
            ->where('empresa_id', $request->user()->empresa_id)
            ->when($this->sucursalConsultaId($request), fn ($q, $id) => $q->where('sucursal_id', $id))
            ->where('estado', 'emitido')
            ->whereBetween('fecha_emision', [$desde, $hasta])
            ->with('usuario:id,nombre_completo')
            ->orderBy('fecha_emision')
            ->orderBy('correlativo')
            ->get();

        $tipos = ['00' => 'Nota de venta', '01' => 'Factura', '03' => 'Boleta', '07' => 'Nota de crédito'];

        // las notas de credito aparecen con importe negativo y restan del total
        $signo = fn ($c) => $c->tipo_comprobante_codigo === '07' ? -1 : 1;

        return [
            'titulo' => 'Ventas por rango de fechas',
            'columnas' => ['Fecha', 'Comprobante', 'Tipo', 'Cliente', 'Condición', 'Vendedor', 'Total'],
            'filas' => $comprobantes->map(fn ($c) => [
                $c->fecha_emision->format('d/m/Y'),
                "{$c->serie}-".str_pad($c->correlativo, 6, '0', STR_PAD_LEFT),
                $tipos[$c->tipo_comprobante_codigo] ?? $c->tipo_comprobante_codigo,
                $c->cliente_nombre ?? 'Público general',
                $c->es_credito ? 'Crédito' : 'Contado',
                $c->usuario?->nombre_completo ?? '—',
                number_format($signo($c) * (float) $c->total, 2),
            ])->values(),
            'resumen' => [
                ['etiqueta' => 'Comprobantes', 'valor' => (string) $comprobantes->where('tipo_comprobante_codigo', '!=', '07')->count()],
                ['etiqueta' => 'Total vendido', 'valor' => 'S/ '.number_format($comprobantes->sum(fn ($c) => $signo($c) * (float) $c->total), 2)],
                ['etiqueta' => 'IGV', 'valor' => 'S/ '.number_format($comprobantes->sum(fn ($c) => $signo($c) * (float) $c->total_igv), 2)],
                ['etiqueta' => 'Descuentos', 'valor' => 'S/ '.number_format($comprobantes->sum(fn ($c) => $signo($c) * (float) $c->total_descuentos), 2)],
            ],
        ];
    }

    private function reporteMargen(Request $request, string $desde, string $hasta): array
    {
        // los detalles de una nota de credito revierten la venta y el costo de lo devuelto
        $signo = "(CASE WHEN comprobantes.tipo_comprobante_codigo = '07' THEN -1 ELSE 1 END)";

        $filas = ComprobanteDetalle::query()
            ->join('comprobantes', 'comprobantes.id', '=', 'comprobante_detalles.comprobante_id')
            ->join('productos', 'productos.id', '=', 'comprobante_detalles.producto_id')
            ->where('comprobantes.empresa_id', $request->user()->empresa_id)
            ->when($this->sucursalConsultaId($request), fn ($q, $id) => $q->where('comprobantes.sucursal_id', $id))
            ->where('comprobantes.estado', 'emitido')
            ->whereBetween('comprobantes.fecha_emision', [$desde, $hasta])
            ->groupBy('productos.id', 'productos.nombre')
            ->selectRaw("
                productos.nombre,
                SUM({$signo} * comprobante_detalles.cantidad) as cantidad,
                SUM({$signo} * comprobante_detalles.total) as venta,
                SUM({$signo} * comprobante_detalles.costo_unitario * comprobante_detalles.cantidad) as costo
            ")
            ->orderByRaw("SUM({$signo} * (comprobante_detalles.total - comprobante_detalles.costo_unitario * comprobante_detalles.cantidad)) desc")
            ->get();

        $ventaTotal = (float) $filas->sum('venta');
        $costoTotal = (float) $filas->sum('costo');

        return [
            'titulo' => 'Margen por producto',
            'columnas' => ['Producto', 'Cant. vendida', 'Venta', 'Costo (FIFO)', 'Margen', 'Margen %'],
            'filas' => $filas->map(function ($fila) {
                $margen = (float) $fila->venta - (float) $fila->costo;

                return [
                    $fila->nombre,
                    rtrim(rtrim(number_format((float) $fila->cantidad, 3), '0'), '.'),
                    number_format((float) $fila->venta, 2),
                    number_format((float) $fila->costo, 2),
                    number_format($margen, 2),
                    ((float) $fila->venta > 0 ? number_format($margen / (float) $fila->venta * 100, 1) : '0.0').'%',
                ];
            })->values(),
            'resumen' => [
                ['etiqueta' => 'Venta total', 'valor' => 'S/ '.number_format($ventaTotal, 2)],
                ['etiqueta' => 'Costo total', 'valor' => 'S/ '.number_format($costoTotal, 2)],
                ['etiqueta' => 'Margen', 'valor' => 'S/ '.number_format($ventaTotal - $costoTotal, 2)],
                ['etiqueta' => 'Margen %', 'valor' => ($ventaTotal > 0 ? number_format(($ventaTotal - $costoTotal) / $ventaTotal * 100, 1) : '0.0').'%'],
            ],
        ];
    }

    private function reporteKardex(Request $request, string $desde, string $hasta, ?string $productoId): array
    {
        $usuario = $request->user();
        $sucursalId = $this->sucursalDeTrabajo($request);

        $producto = $productoId
            ? Producto::where('empresa_id', $usuario->empresa_id)->find($productoId)
            : null;

        if (! $producto) {
            return [
                'titulo' => 'Kardex de producto',
                'contexto' => null,
                'columnas' => ['Fecha', 'Tipo', 'Entrada', 'Salida', 'Costo unit.', 'Saldo', 'Usuario'],
                'filas' => collect(),
                'resumen' => [],
            ];
        }

        $base = MovimientoInventario::query()
            ->where('producto_id', $producto->id)
            ->where('sucursal_id', $sucursalId);

        // saldo antes del rango: entradas - salidas
        $previos = (clone $base)->whereDate('creado_en', '<', $desde)->get(['tipo', 'cantidad']);
        $saldo = $previos->reduce(
            fn ($acumulado, $m) => $acumulado + (in_array($m->tipo, self::ENTRADAS, true) ? 1 : -1) * (float) $m->cantidad,
            0.0,
        );
        $saldoInicial = $saldo;

        $movimientos = (clone $base)
            ->whereDate('creado_en', '>=', $desde)
            ->whereDate('creado_en', '<=', $hasta)
            ->with('usuario:id,nombre_completo')
            ->orderBy('creado_en')
            ->get();

        $entradas = 0.0;
        $salidas = 0.0;

        $filas = $movimientos->map(function ($m) use (&$saldo, &$entradas, &$salidas) {
            $esEntrada = in_array($m->tipo, self::ENTRADAS, true);
            $cantidad = (float) $m->cantidad;
            $saldo += $esEntrada ? $cantidad : -$cantidad;
            $esEntrada ? $entradas += $cantidad : $salidas += $cantidad;

            $numero = fn ($v) => rtrim(rtrim(number_format($v, 3), '0'), '.');

            return [
                $m->creado_en->format('d/m/Y H:i'),
                self::TIPOS_KARDEX[$m->tipo] ?? $m->tipo,
                $esEntrada ? $numero($cantidad) : '',
                $esEntrada ? '' : $numero($cantidad),
                $m->costo_unitario !== null ? number_format((float) $m->costo_unitario, 2) : '',
                $numero($saldo),
                $m->usuario?->nombre_completo ?? '—',
            ];
        })->values();

        $numero = fn ($v) => rtrim(rtrim(number_format($v, 3), '0'), '.');

        return [
            'titulo' => 'Kardex de producto',
            'contexto' => $producto->nombre,
            'columnas' => ['Fecha', 'Tipo', 'Entrada', 'Salida', 'Costo unit.', 'Saldo', 'Usuario'],
            'filas' => $filas,
            'resumen' => [
                ['etiqueta' => 'Saldo inicial', 'valor' => $numero($saldoInicial)],
                ['etiqueta' => 'Entradas', 'valor' => $numero($entradas)],
                ['etiqueta' => 'Salidas', 'valor' => $numero($salidas)],
                ['etiqueta' => 'Saldo final', 'valor' => $numero($saldo)],
            ],
        ];
    }
}
