<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Jobs\EnviarComprobanteSunat;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\CompraDetalle;
use App\Models\CuentaPorCobrar;
use App\Models\MedioPago;
use App\Models\Producto;
use App\Models\TipoDocumentoIdentidad;
use App\Services\CajaService;
use App\Services\VentaService;
use App\Support\DocumentoIdentidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function __construct(
        private readonly CajaService $caja,
        private readonly VentaService $ventas,
    ) {}

    public function index(Request $request): Response
    {
        $apertura = $this->caja->aperturaDe($request->user());

        if (! $apertura) {
            return Inertia::render('Pos/Index', ['apertura' => null]);
        }

        $sucursalId = $apertura->caja->sucursal_id;
        $empresaId = $request->user()->empresa_id;

        $productos = Producto::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->with(['presentaciones' => fn ($q) => $q->where('activo', true)->orderByDesc('es_default')->orderBy('nombre')])
            ->withSum(['stock as stock' => fn ($q) => $q->where('sucursal_id', $sucursalId)], 'cantidad')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo_interno', 'categoria_id', 'controla_stock', 'permite_fraccion', 'imagen_url'])
            ->filter(fn ($p) => $p->presentaciones->isNotEmpty())
            ->values();

        return Inertia::render('Pos/Index', [
            'apertura' => ['id' => $apertura->id, 'caja' => $apertura->caja->nombre],
            'facturacionElectronica' => (bool) $request->user()->empresa->facturacion_electronica,
            'productos' => $productos->map(fn ($p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'codigo_interno' => $p->codigo_interno,
                'categoria_id' => $p->categoria_id,
                'controla_stock' => $p->controla_stock,
                'permite_fraccion' => $p->permite_fraccion,
                'imagen_url' => $p->imagen_url,
                'stock' => (float) ($p->stock ?? 0),
                'presentaciones' => $p->presentaciones->map(fn ($pres) => [
                    'id' => $pres->id,
                    'nombre' => $pres->nombre,
                    'precio_venta' => (float) $pres->precio_venta,
                    'precio_mayorista' => $pres->precio_mayorista !== null ? (float) $pres->precio_mayorista : null,
                    'cantidad_mayorista' => $pres->cantidad_mayorista !== null ? (float) $pres->cantidad_mayorista : null,
                    'factor_conversion' => (float) $pres->factor_conversion,
                    'codigo_barras' => $pres->codigo_barras,
                    'es_default' => $pres->es_default,
                ]),
            ]),
            'categorias' => Categoria::where('empresa_id', $empresaId)->orderBy('nombre')->get(['id', 'nombre']),
            'mediosPago' => MedioPago::orderBy('nombre')->get(['codigo', 'nombre', 'requiere_referencia']),
            'tiposDocumento' => TipoDocumentoIdentidad::orderBy('codigo')->get(['codigo', 'nombre']),
        ]);
    }

    public function clientes(Request $request): JsonResponse
    {
        $buscar = trim((string) $request->query('buscar'));

        if ($buscar === '') {
            return response()->json([]);
        }

        return response()->json(
            Cliente::query()
                ->where('empresa_id', $request->user()->empresa_id)
                ->addSelect([
                    'id', 'nombre', 'tipo_documento_codigo', 'numero_documento', 'direccion', 'limite_credito',
                    'deuda' => CuentaPorCobrar::query()
                        ->selectRaw('COALESCE(SUM(monto_total - monto_pagado), 0)')
                        ->whereColumn('cliente_id', 'clientes.id')
                        ->where('estado', '!=', 'pagado'),
                ])
                ->where(fn ($q) => $q
                    ->where('nombre', 'ilike', "%{$buscar}%")
                    ->orWhere('numero_documento', 'ilike', "{$buscar}%"))
                ->orderBy('nombre')
                ->limit(10)
                ->get()
        );
    }

    /** Creación rápida de cliente desde el POS (responde JSON). */
    public function crearCliente(Request $request): JsonResponse
    {
        $empresaId = $request->user()->empresa_id;

        $datos = $request->validate([
            'tipo_documento_codigo' => ['required', Rule::exists('tipos_documento_identidad', 'codigo')],
            'numero_documento' => [
                'nullable', 'string', 'max:15',
                DocumentoIdentidad::regla($request->input('tipo_documento_codigo')),
                Rule::unique('clientes', 'numero_documento')
                    ->where('empresa_id', $empresaId)
                    ->where('tipo_documento_codigo', $request->input('tipo_documento_codigo'))
                    ->whereNull('eliminado_en'),
            ],
            'nombre' => ['required', 'string', 'max:200'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'limite_credito' => ['nullable', 'numeric', 'min:0'],
        ], [
            'nombre.required' => 'Ingresa el nombre.',
            'numero_documento.unique' => 'Ya tienes un cliente con este documento.',
        ]);

        $cliente = Cliente::create([
            ...$datos,
            'limite_credito' => $datos['limite_credito'] ?? 0,
            'empresa_id' => $empresaId,
        ]);

        return response()->json([
            'id' => $cliente->id,
            'nombre' => $cliente->nombre,
            'tipo_documento_codigo' => $cliente->tipo_documento_codigo,
            'numero_documento' => $cliente->numero_documento,
            'direccion' => $cliente->direccion,
            'limite_credito' => (float) $cliente->limite_credito,
            'deuda' => 0,
        ], 201);
    }

    /** Últimas compras de un producto (para consulta rápida desde el POS). */
    public function historialProducto(Request $request, Producto $producto): JsonResponse
    {
        $empresaId = $request->user()->empresa_id;

        abort_unless($producto->empresa_id === $empresaId, 403);

        $compras = CompraDetalle::query()
            ->join('compras', 'compras.id', '=', 'compra_detalles.compra_id')
            ->leftJoin('proveedores', 'proveedores.id', '=', 'compras.proveedor_id')
            ->leftJoin('producto_presentaciones', 'producto_presentaciones.id', '=', 'compra_detalles.presentacion_id')
            ->leftJoin('capas_costo', 'capas_costo.compra_detalle_id', '=', 'compra_detalles.id')
            ->where('compras.empresa_id', $empresaId)
            ->where('compra_detalles.producto_id', $producto->id)
            ->orderByDesc('compras.creado_en')
            ->limit(8)
            ->get([
                'compra_detalles.id',
                'compra_detalles.cantidad',
                'compra_detalles.costo_unitario',
                'compra_detalles.total',
                'compras.fecha',
                'compras.serie_numero as documento',
                'proveedores.razon_social as proveedor',
                'producto_presentaciones.nombre as presentacion',
                'capas_costo.cantidad_restante as stock_restante',
            ])
            ->map(fn ($fila) => [
                'id' => $fila->id,
                'fecha' => $fila->fecha,
                'documento' => $fila->documento,
                'proveedor' => $fila->proveedor,
                'presentacion' => $fila->presentacion,
                'stock_restante' => $fila->stock_restante !== null ? (float) $fila->stock_restante : null,
                'cantidad' => (float) $fila->cantidad,
                'costo_unitario' => (float) $fila->costo_unitario,
                'total' => (float) $fila->total,
            ]);

        return response()->json(['compras' => $compras]);
    }

    public function vender(Request $request): RedirectResponse
    {
        $apertura = $this->caja->aperturaDe($request->user());

        if (! $apertura) {
            return back()->with('error', 'Necesitas abrir caja antes de vender.');
        }

        $esCredito = $request->boolean('es_credito');

        // sin facturacion electronica activa solo se emiten notas de venta internas
        $tiposPermitidos = $request->user()->empresa->facturacion_electronica ? 'in:00,03,01' : 'in:00';

        $datos = $request->validate([
            'tipo_comprobante_codigo' => ['required', $tiposPermitidos],
            'cliente_id' => [$esCredito ? 'required' : 'nullable', 'uuid'],
            'es_credito' => ['required', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.presentacion_id' => ['required', 'uuid'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.descuento' => ['nullable', 'numeric', 'min:0'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'gt:0'],
            'pagos' => [$esCredito ? 'nullable' : 'required', 'array'],
            'pagos.*.medio_pago_codigo' => ['required', 'exists:medios_pago,codigo'],
            'pagos.*.monto' => ['required', 'numeric', 'gt:0'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:100'],
        ], [
            'items.required' => 'El carrito está vacío.',
            'pagos.required' => 'Indica cómo se pagó la venta.',
            'cliente_id.required' => 'La venta al crédito necesita un cliente.',
        ]);

        $conPrecioManual = collect($datos['items'])->contains(fn ($i) => filled($i['precio_unitario'] ?? null));
        if ($conPrecioManual && ! $request->user()->can('pos.precio_manual')) {
            return back()->with('error', 'Tu rol no puede cambiar el precio de lista. Pide a un administrador o cajero que lo haga.');
        }

        try {
            $comprobante = $this->ventas->registrar($request->user(), $apertura, $datos);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo registrar la venta. Intenta de nuevo.');
        }

        // el envio a SUNAT corre despues de responder para no demorar la caja
        if (in_array($comprobante->tipo_comprobante_codigo, ['01', '03'], true)) {
            EnviarComprobanteSunat::dispatchAfterResponse($comprobante->id);
        }

        $numero = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);
        $total = number_format((float) $comprobante->total, 2);

        return back()
            ->with('ticket', route('comprobantes.ticket', $comprobante))
            ->with('success', $esCredito
                ? "Venta {$numero} al crédito por S/ {$total} registrada a \"{$comprobante->cliente_nombre}\"."
                : "Venta {$numero} registrada por S/ {$total}.");
    }
}
