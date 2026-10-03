<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\ComprobanteDetalle;
use App\Models\CuentaPorCobrar;
use App\Models\Empresa;
use App\Models\MovimientoPuntos;
use App\Models\TipoDocumentoIdentidad;
use App\Services\PuntosService;
use App\Support\DocumentoIdentidad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClienteController extends Controller
{
    public function index(Request $request): Response
    {
        $empresaId = $request->user()->empresa_id;
        $filtros = $request->only(['buscar']);

        $clientes = Cliente::query()
            ->where('empresa_id', $empresaId)
            ->addSelect(['deuda' => CuentaPorCobrar::query()
                ->selectRaw('COALESCE(SUM(monto_total - monto_pagado), 0)')
                ->whereColumn('cliente_id', 'clientes.id')
                ->where('estado', '!=', 'pagado'),
            ])
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->where(fn ($w) => $w
                ->where('nombre', 'ilike', "%{$buscar}%")
                ->orWhere('numero_documento', 'ilike', "{$buscar}%")))
            ->orderBy('nombre')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Clientes/Index', [
            'clientes' => $clientes,
            'filtros' => $filtros,
            'tiposDocumento' => TipoDocumentoIdentidad::orderBy('codigo')->get(['codigo', 'nombre', 'longitud']),
            'programaPuntos' => $this->programaPuntos($request->user()->empresa),
        ]);
    }

    /**
     * Ficha del cliente: quién es, cuánto ha comprado, qué compra, cuánto debe y sus puntos.
     * Es de toda la empresa (un cliente compra en cualquier sucursal).
     */
    public function show(Request $request, Cliente $cliente, PuntosService $puntos): Response
    {
        abort_unless($cliente->empresa_id === $request->user()->empresa_id, 403);

        $empresa = $request->user()->empresa;
        $neto = Comprobante::SQL_TOTAL_NETO;
        $esVenta = "tipo_comprobante_codigo <> '07'";
        $numero = fn ($c) => "{$c->serie}-".str_pad((string) $c->correlativo, 6, '0', STR_PAD_LEFT);
        $tipos = ['00' => 'Nota de venta', '01' => 'Factura', '03' => 'Boleta', '07' => 'Nota de crédito'];

        // las notas de credito restan y lo anulado no cuenta
        $resumen = Comprobante::query()
            ->where('cliente_id', $cliente->id)
            ->where('estado', 'emitido')
            ->selectRaw("
                COALESCE(SUM({$neto}), 0) as total,
                COUNT(*) FILTER (WHERE {$esVenta}) as compras,
                MIN(fecha_emision) FILTER (WHERE {$esVenta}) as primera,
                MAX(fecha_emision) FILTER (WHERE {$esVenta}) as ultima
            ")
            ->toBase()
            ->first();

        $cuentas = CuentaPorCobrar::query()
            ->where('cliente_id', $cliente->id)
            ->where('estado', '!=', 'pagado')
            ->with('comprobante:id,serie,correlativo,fecha_emision')
            ->get()
            ->sortBy(fn ($c) => $c->comprobante?->fecha_emision)
            ->values();

        $deuda = (float) $cuentas->sum(fn ($c) => (float) $c->monto_total - (float) $c->monto_pagado);
        $limite = (float) $cliente->limite_credito;

        $compras = Comprobante::query()
            ->where('cliente_id', $cliente->id)
            ->with([
                'detalles:id,comprobante_id,descripcion,cantidad,precio_unitario,descuento,total',
                'cuentaPorCobrar:id,comprobante_id,monto_total,monto_pagado,estado',
                'comprobanteRef:id,serie,correlativo',
            ])
            ->orderByDesc('fecha_emision')
            ->orderByDesc('creado_en')
            ->orderByDesc('id') // los id son UUID v7: desempatan por orden de creacion
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Comprobante $c) => [
                'id' => $c->id,
                'numero' => $numero($c),
                'tipo' => $tipos[$c->tipo_comprobante_codigo] ?? 'Comprobante',
                'es_nota_credito' => $c->tipo_comprobante_codigo === '07',
                'modifica' => $c->comprobanteRef ? $numero($c->comprobanteRef) : null,
                'fecha' => $c->fecha_emision->toDateString(),
                'hora' => substr((string) $c->hora_emision, 0, 5),
                'total' => (float) $c->total,
                'es_credito' => (bool) $c->es_credito,
                'estado' => $c->estado,
                'saldo' => $c->cuentaPorCobrar && $c->cuentaPorCobrar->estado !== 'pagado'
                    ? round((float) $c->cuentaPorCobrar->monto_total - (float) $c->cuentaPorCobrar->monto_pagado, 2)
                    : 0.0,
                'items' => $c->detalles->map(fn ($d) => [
                    'descripcion' => $d->descripcion,
                    'cantidad' => (float) $d->cantidad,
                    'precio_unitario' => (float) $d->precio_unitario,
                    'descuento' => (float) $d->descuento,
                    'total' => (float) $d->total,
                ]),
            ]);

        $signo = "(CASE WHEN comprobantes.tipo_comprobante_codigo = '07' THEN -1 ELSE 1 END)";

        // lo que mas lleva: sirve para saber que ofrecerle
        $frecuentes = ComprobanteDetalle::query()
            ->join('comprobantes', 'comprobantes.id', '=', 'comprobante_detalles.comprobante_id')
            ->join('productos', 'productos.id', '=', 'comprobante_detalles.producto_id')
            ->leftJoin('producto_presentaciones', 'producto_presentaciones.id', '=', 'comprobante_detalles.presentacion_id')
            ->where('comprobantes.cliente_id', $cliente->id)
            ->where('comprobantes.estado', 'emitido')
            ->groupBy('productos.id', 'productos.nombre')
            ->selectRaw("
                productos.nombre,
                SUM({$signo} * comprobante_detalles.cantidad * COALESCE(producto_presentaciones.factor_conversion, 1)) as cantidad,
                COUNT(DISTINCT comprobantes.id) FILTER (WHERE comprobantes.tipo_comprobante_codigo <> '07') as veces,
                SUM({$signo} * comprobante_detalles.total) as total,
                MAX(comprobantes.fecha_emision) as ultima
            ")
            ->havingRaw("SUM({$signo} * comprobante_detalles.total) > 0")
            ->orderByDesc('total')
            ->limit(6)
            ->toBase()
            ->get()
            ->map(fn ($f) => [
                'nombre' => $f->nombre,
                'cantidad' => (float) $f->cantidad,
                'veces' => (int) $f->veces,
                'total' => (float) $f->total,
                'ultima' => substr((string) $f->ultima, 0, 10),
            ]);

        $cobros = Cobro::query()
            ->whereHas('cuenta', fn ($q) => $q->where('cliente_id', $cliente->id))
            ->with(['medioPago:codigo,nombre', 'cuenta:id,comprobante_id', 'cuenta.comprobante:id,serie,correlativo'])
            ->latest('creado_en')
            ->limit(8)
            ->get()
            ->map(fn (Cobro $c) => [
                'id' => $c->id,
                'fecha' => $c->creado_en->toDateTimeString(),
                'monto' => (float) $c->monto,
                'medio' => $c->medioPago?->nombre ?? $c->medio_pago_codigo,
                'comprobante' => $c->cuenta?->comprobante ? $numero($c->cuenta->comprobante) : null,
            ]);

        $reglas = $puntos->reglas($empresa);

        return Inertia::render('Clientes/Ficha', [
            'cliente' => [
                'id' => $cliente->id,
                'nombre' => $cliente->nombre,
                'tipo_documento' => $cliente->tipoDocumento?->nombre,
                'numero_documento' => $cliente->numero_documento,
                'direccion' => $cliente->direccion,
                'telefono' => $cliente->telefono,
                'email' => $cliente->email,
                'limite_credito' => $limite,
                'puntos' => (int) $cliente->puntos,
                'creado_en' => $cliente->creado_en?->toDateString(),
            ],
            'resumen' => [
                'total' => (float) $resumen->total,
                'compras' => (int) $resumen->compras,
                'ticket_promedio' => $resumen->compras > 0 ? round((float) $resumen->total / $resumen->compras, 2) : 0.0,
                'primera' => $resumen->primera ? substr((string) $resumen->primera, 0, 10) : null,
                'ultima' => $resumen->ultima ? substr((string) $resumen->ultima, 0, 10) : null,
                'deuda' => round($deuda, 2),
                'credito_disponible' => $limite > 0 ? round(max(0, $limite - $deuda), 2) : null,
            ],
            'compras' => $compras,
            'deudas' => $cuentas->map(fn (CuentaPorCobrar $c) => [
                'id' => $c->id,
                'comprobante' => $c->comprobante ? $numero($c->comprobante) : '—',
                'fecha' => $c->comprobante?->fecha_emision?->toDateString(),
                'dias' => $c->comprobante?->fecha_emision ? (int) $c->comprobante->fecha_emision->startOfDay()->diffInDays(now()->startOfDay()) : 0,
                'total' => (float) $c->monto_total,
                'pagado' => (float) $c->monto_pagado,
                'saldo' => round((float) $c->monto_total - (float) $c->monto_pagado, 2),
            ]),
            'cobros' => $cobros,
            'frecuentes' => $frecuentes,
            // null si la empresa no usa el programa y el cliente nunca tuvo puntos
            'puntos' => $reglas || $cliente->puntos > 0 ? [
                'reglas' => $reglas,
                'saldo' => (int) $cliente->puntos,
                'movimientos' => MovimientoPuntos::query()
                    ->where('cliente_id', $cliente->id)
                    ->with('usuario:id,nombre_completo')
                    ->latest('creado_en')
                    ->latest('id')
                    ->limit(15)
                    ->get()
                    ->map(fn (MovimientoPuntos $m) => [
                        'id' => $m->id,
                        'fecha' => $m->creado_en->toDateTimeString(),
                        'tipo' => MovimientoPuntos::TIPOS[$m->tipo] ?? $m->tipo,
                        'concepto' => $m->concepto,
                        'puntos' => $m->puntos,
                        'saldo' => $m->saldo,
                        'usuario' => $m->usuario?->nombre_completo,
                    ]),
            ] : null,
        ]);
    }

    /** Enciende, apaga o cambia las reglas del programa de puntos de la empresa. */
    public function guardarProgramaPuntos(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'activo' => ['required', 'boolean'],
            'soles_por_punto' => ['required', 'numeric', 'min:0.1', 'max:100000'],
            'valor' => ['required', 'numeric', 'min:0.01', 'max:1000'],
            'minimo_canje' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ], [
            'soles_por_punto.required' => 'Indica cada cuántos soles se gana 1 punto.',
            'soles_por_punto.min' => 'Debe ser al menos S/ 0.10.',
            'valor.required' => 'Indica cuánto vale cada punto.',
            'valor.min' => 'Cada punto debe valer al menos S/ 0.01.',
        ]);

        $empresa = $request->user()->empresa;
        $antes = $this->programaPuntos($empresa);

        $empresa->update([
            'puntos_activo' => $datos['activo'],
            'puntos_soles_por_punto' => round((float) $datos['soles_por_punto'], 2),
            'puntos_valor' => round((float) $datos['valor'], 2),
            'puntos_minimo_canje' => (int) ($datos['minimo_canje'] ?? 0),
        ]);

        // las reglas deciden cuanto descuento se regala: queda constancia de quien las cambio
        Auditoria::registrar($request->user(), 'puntos.programa', 'empresa', $empresa->id, [
            'antes' => $antes,
            'ahora' => $this->programaPuntos($empresa->refresh()),
        ]);

        return back()->with('success', $datos['activo'] ? 'Programa de puntos activo.' : 'Programa de puntos apagado. Los clientes conservan sus puntos.');
    }

    /** Suma o resta puntos a mano, con su motivo. */
    public function ajustarPuntos(Request $request, Cliente $cliente, PuntosService $puntos): RedirectResponse
    {
        abort_unless($cliente->empresa_id === $request->user()->empresa_id, 403);

        $datos = $request->validate([
            'puntos' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'concepto' => ['required', 'string', 'max:150'],
        ], [
            'puntos.required' => 'Indica cuántos puntos.',
            'puntos.not_in' => 'Indica cuántos puntos.',
            'concepto.required' => 'Escribe el motivo.',
        ]);

        try {
            $movimiento = $puntos->ajustar($cliente, (int) $datos['puntos'], trim($datos['concepto']), $request->user());
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        Auditoria::registrar($request->user(), 'puntos.ajuste', 'cliente', $cliente->id, [
            'cliente' => $cliente->nombre,
            'puntos' => $movimiento->puntos,
            'saldo' => $movimiento->saldo,
            'motivo' => $movimiento->concepto,
        ]);

        return back()->with('success', $movimiento->puntos > 0
            ? "Se sumaron {$movimiento->puntos} puntos a {$cliente->nombre}."
            : 'Se restaron '.abs($movimiento->puntos)." puntos a {$cliente->nombre}.");
    }

    /** Reglas tal como estan guardadas (tambien con el programa apagado, para poder editarlas). */
    private function programaPuntos(Empresa $empresa): array
    {
        return [
            'activo' => (bool) $empresa->puntos_activo,
            'soles_por_punto' => (float) $empresa->puntos_soles_por_punto,
            'valor' => (float) $empresa->puntos_valor,
            'minimo_canje' => (int) $empresa->puntos_minimo_canje,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        // solo quien administra el credito puede abrir una linea al crear
        if (! $request->user()->can('clientes.credito')) {
            $datos['limite_credito'] = 0;
        }

        $cliente = Cliente::create([
            ...$datos,
            'empresa_id' => $request->user()->empresa_id,
        ]);

        if ((float) $datos['limite_credito'] > 0) {
            $this->auditarCredito($request, $cliente, 0.0, (float) $datos['limite_credito']);
        }

        return back()->with('success', 'Cliente creado.');
    }

    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        abort_unless($cliente->empresa_id === $request->user()->empresa_id, 403);

        $datos = $this->validar($request, $cliente);
        $limiteAntes = (float) $cliente->limite_credito;

        if (! $request->user()->can('clientes.credito')) {
            $datos['limite_credito'] = $limiteAntes;
        }

        $cliente->update($datos);

        if (abs((float) $datos['limite_credito'] - $limiteAntes) >= 0.005) {
            $this->auditarCredito($request, $cliente, $limiteAntes, (float) $datos['limite_credito']);
        }

        return back()->with('success', 'Cliente actualizado.');
    }

    /** Cambiar la linea de credito es dar plata fiada: queda constancia de quien y cuanto. */
    private function auditarCredito(Request $request, Cliente $cliente, float $de, float $a): void
    {
        Auditoria::registrar($request->user(), 'cliente.limite_credito', 'cliente', $cliente->id, [
            'cliente' => $cliente->nombre,
            'de' => $de,
            'a' => $a,
        ]);
    }

    public function destroy(Request $request, Cliente $cliente): RedirectResponse
    {
        abort_unless($cliente->empresa_id === $request->user()->empresa_id, 403);

        $tieneDeuda = CuentaPorCobrar::query()
            ->where('cliente_id', $cliente->id)
            ->where('estado', '!=', 'pagado')
            ->exists();

        if ($tieneDeuda) {
            return back()->with('error', 'No se puede eliminar: el cliente tiene deudas pendientes.');
        }

        $cliente->delete();

        return back()->with('success', 'Cliente eliminado.');
    }

    private function validar(Request $request, ?Cliente $cliente = null): array
    {
        $empresaId = $request->user()->empresa_id;

        return $request->validate([
            'tipo_documento_codigo' => ['required', Rule::exists('tipos_documento_identidad', 'codigo')],
            'numero_documento' => [
                'nullable', 'string', 'max:15',
                DocumentoIdentidad::regla($request->input('tipo_documento_codigo')),
                Rule::unique('clientes', 'numero_documento')
                    ->where('empresa_id', $empresaId)
                    ->where('tipo_documento_codigo', $request->input('tipo_documento_codigo'))
                    ->whereNull('eliminado_en')
                    ->ignore($cliente?->id),
            ],
            'nombre' => ['required', 'string', 'max:200'],
            'direccion' => ['nullable', 'string', 'max:250'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'limite_credito' => ['nullable', 'numeric', 'min:0'],
        ], [
            'nombre.required' => 'Ingresa el nombre.',
            'numero_documento.unique' => 'Ya tienes un cliente con este documento.',
            'limite_credito.min' => 'El límite no puede ser negativo.',
            'email.email' => 'El correo no es válido.',
        ]);
    }
}
