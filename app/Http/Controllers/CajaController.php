<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Models\AperturaCaja;
use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Services\CajaService;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CajaController extends Controller
{
    public function __construct(private readonly CajaService $caja) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();

        $cajas = Caja::query()
            ->where('empresa_id', $usuario->empresa_id)
            ->when($usuario->sucursalesPermitidas(), fn ($q, $ids) => $q->whereIn('sucursal_id', $ids))
            ->where('activo', true)
            ->with(['aperturaAbierta.usuario:id,nombre_completo', 'sucursal:id,nombre'])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'sucursal_id']);

        $apertura = $this->caja->aperturaDe($usuario);

        $veTodas = $usuario->can('caja.ver_todas');

        // turnos cerrados: quien puede ver todas las cajas ve todos; los demas solo los suyos
        $historial = AperturaCaja::query()
            ->where('empresa_id', $usuario->empresa_id)
            ->whereNotNull('cerrada_en')
            ->when(! $veTodas, fn ($q) => $q->where('usuario_id', $usuario->id))
            ->with(['caja:id,nombre,sucursal_id', 'caja.sucursal:id,nombre', 'usuario:id,nombre_completo', 'cierresMedios.medioPago:codigo,nombre'])
            ->withSum(['movimientos as ingresos' => fn ($q) => $q->where('tipo', 'ingreso')->where('medio_pago_codigo', 'efectivo')], 'monto')
            ->withSum(['movimientos as egresos' => fn ($q) => $q->where('tipo', 'egreso')->where('medio_pago_codigo', 'efectivo')], 'monto')
            ->withSum(['pagos as ventas_efectivo' => fn ($q) => $q->where('medio_pago_codigo', 'efectivo')], 'monto')
            ->withSum(['cobros as cobros_efectivo' => fn ($q) => $q->where('medio_pago_codigo', 'efectivo')], 'monto')
            ->withSum(['pagosProveedor as pagos_proveedor_efectivo' => fn ($q) => $q->where('medio_pago_codigo', 'efectivo')], 'monto')
            ->latest('cerrada_en')
            ->paginate(10)
            ->withQueryString()
            ->through(fn ($turno) => [
                'id' => $turno->id,
                'caja' => $turno->caja
                    ? $turno->caja->nombre.($turno->caja->sucursal ? " · {$turno->caja->sucursal->nombre}" : '')
                    : null,
                'usuario' => $turno->usuario?->nombre_completo,
                'abierta_en' => $turno->abierta_en,
                'cerrada_en' => $turno->cerrada_en,
                'monto_inicial' => (float) $turno->monto_inicial,
                'ingresos' => (float) ($turno->ingresos ?? 0),
                'egresos' => round((float) ($turno->egresos ?? 0) + (float) ($turno->pagos_proveedor_efectivo ?? 0), 2),
                'ventas_efectivo' => round((float) ($turno->ventas_efectivo ?? 0) + (float) ($turno->cobros_efectivo ?? 0), 2),
                'monto_sistema' => (float) $turno->monto_sistema,
                'monto_cierre' => (float) $turno->monto_cierre,
                'diferencia' => round((float) $turno->monto_cierre - (float) $turno->monto_sistema, 2),
                // cuadre por medio (turnos cerrados antes de esta funcion no lo tienen)
                'medios' => $turno->cierresMedios->map(fn ($m) => [
                    'codigo' => $m->medio_pago_codigo,
                    'nombre' => $m->medioPago?->nombre ?? $m->medio_pago_codigo,
                    'esperado' => (float) $m->esperado,
                    'declarado' => $m->declarado !== null ? (float) $m->declarado : null,
                    'diferencia' => (float) $m->diferencia,
                ])->values(),
                'diferencia_total' => round((float) $turno->cierresMedios->sum('diferencia') ?: ((float) $turno->monto_cierre - (float) $turno->monto_sistema), 2),
            ]);

        return Inertia::render('Caja/Index', [
            'historial' => $historial,
            'cajas' => $cajas->map(fn ($caja) => [
                'id' => $caja->id,
                'nombre' => $caja->nombre,
                'sucursal' => $caja->sucursal?->nombre,
                'ocupada_por' => $caja->aperturaAbierta?->usuario?->nombre_completo,
            ]),
            'apertura' => $apertura ? [
                'id' => $apertura->id,
                'caja' => $apertura->caja->nombre
                    .($apertura->caja->loadMissing('sucursal:id,nombre')->sucursal ? " · {$apertura->caja->sucursal->nombre}" : ''),
                'abierta_en' => $apertura->abierta_en,
                'monto_inicial' => (float) $apertura->monto_inicial,
                'resumen' => $this->caja->resumen($apertura),
                'medios' => $this->caja->resumenPorMedio($apertura),
                'movimientos' => $apertura->movimientos()
                    ->with('usuario:id,nombre_completo')
                    ->latest('creado_en')
                    ->limit(50)
                    ->get(['id', 'tipo', 'concepto', 'monto', 'medio_pago_codigo', 'referencia', 'usuario_id', 'creado_en']),
            ] : null,
            'denominaciones' => CajaService::DENOMINACIONES,
        ]);
    }

    public function abrir(Request $request): RedirectResponse
    {
        $usuario = $request->user();

        $datos = $request->validate([
            'caja_id' => ['required', 'uuid'],
            'monto_inicial' => ['required', 'numeric', 'min:0'],
        ], [
            'caja_id.required' => 'Elige una caja.',
            'monto_inicial.required' => 'Ingresa el monto inicial.',
            'monto_inicial.min' => 'El monto no puede ser negativo.',
        ]);

        if ($this->caja->aperturaDe($usuario)) {
            return back()->with('error', 'Ya tienes una caja abierta. Ciérrala antes de abrir otra.');
        }

        $caja = Caja::query()
            ->where('empresa_id', $usuario->empresa_id)
            ->where('activo', true)
            ->find($datos['caja_id']);

        if (! $caja) {
            return back()->with('error', 'La caja elegida no existe.');
        }

        $permitidas = $usuario->sucursalesPermitidas();
        if ($permitidas && ! in_array($caja->sucursal_id, $permitidas, true)) {
            return back()->with('error', 'No estás asignado a la sucursal de esa caja.');
        }

        $ocupadaPor = $caja->aperturaAbierta?->usuario?->nombre_completo;
        if ($ocupadaPor) {
            return back()->with('error', "La caja ya está abierta por {$ocupadaPor}.");
        }

        try {
            AperturaCaja::create([
                'empresa_id' => $usuario->empresa_id,
                'caja_id' => $caja->id,
                'usuario_id' => $usuario->id,
                'monto_inicial' => $datos['monto_inicial'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // la BD garantiza una apertura por caja y una por usuario aunque lleguen dos clics a la vez
            return back()->with('error', 'Esa caja (o tu usuario) ya tiene un turno abierto.');
        }

        return back()->with('success', "Caja {$caja->nombre} abierta.");
    }

    public function movimiento(Request $request): RedirectResponse
    {
        $apertura = $this->caja->aperturaDe($request->user());

        if (! $apertura) {
            return back()->with('error', 'No tienes una caja abierta.');
        }

        $datos = $request->validate([
            'tipo' => ['required', 'in:ingreso,egreso'],
            'concepto' => ['required', 'string', 'max:200'],
            'monto' => ['required', 'numeric', 'gt:0'],
        ], [
            'concepto.required' => 'Describe el motivo del movimiento.',
            'monto.required' => 'Ingresa el monto.',
            'monto.gt' => 'El monto debe ser mayor a 0.',
        ]);

        if ($datos['tipo'] === 'egreso') {
            if (! $request->user()->can('caja.egresos')) {
                return back()->with('error', 'Tu rol no puede registrar egresos de caja.');
            }

            $disponible = $this->caja->resumen($apertura)['esperado'];
            if ($datos['monto'] > $disponible) {
                return back()->with('error', 'No hay suficiente efectivo en caja para ese egreso (disponible: S/ '.number_format($disponible, 2).').');
            }
        }

        $movimiento = MovimientoCaja::create([
            'empresa_id' => $apertura->empresa_id,
            'apertura_id' => $apertura->id,
            'usuario_id' => $request->user()->id,
            ...$datos,
        ]);

        // el dinero que sale de caja deja constancia de quien lo saco y por que
        if ($datos['tipo'] === 'egreso') {
            Auditoria::registrar($request->user(), 'caja.egreso', 'movimiento_caja', $movimiento->id, [
                'caja' => $apertura->caja?->nombre,
                'concepto' => $datos['concepto'],
                'monto' => (float) $datos['monto'],
            ]);
        }

        return back()->with('success', $datos['tipo'] === 'ingreso' ? 'Ingreso registrado.' : 'Egreso registrado.');
    }

    public function cerrar(Request $request): RedirectResponse
    {
        $apertura = $this->caja->aperturaDe($request->user());

        if (! $apertura) {
            return back()->with('error', 'No tienes una caja abierta.');
        }

        $datos = $request->validate([
            'monto_cierre' => ['required_without:conteo', 'nullable', 'numeric', 'min:0'],
            'conteo' => ['nullable', 'array'],
            'conteo.*' => ['nullable', 'integer', 'min:0'],
            'declarados' => ['nullable', 'array'],
            'declarados.*' => ['nullable', 'numeric', 'min:0'],
        ], [
            'monto_cierre.required_without' => 'Ingresa el efectivo contado.',
            'monto_cierre.min' => 'El monto no puede ser negativo.',
            'declarados.*.min' => 'El monto no puede ser negativo.',
        ]);

        // el conteo por billetes/monedas, si viene, manda sobre el total escrito a mano
        $conteo = collect($datos['conteo'] ?? [])
            ->only(CajaService::DENOMINACIONES)
            ->map(fn ($n) => (int) $n)
            ->filter();
        $efectivo = $conteo->isNotEmpty()
            ? round($conteo->reduce(fn ($total, $n, $valor) => $total + $n * (float) $valor, 0.0), 2)
            : (float) $datos['monto_cierre'];

        $declarados = collect($datos['declarados'] ?? [])
            ->map(fn ($v) => $v === null || $v === '' ? null : (float) $v)
            ->put('efectivo', $efectivo)
            ->all();

        try {
            $cuadre = DB::transaction(function () use ($apertura, $declarados, $conteo) {
                // con la apertura bloqueada, las ventas en curso esperan y lo esperado es lo real
                $bloqueada = AperturaCaja::lockForUpdate()->findOrFail($apertura->id);

                if ($bloqueada->cerrada_en) {
                    throw new ErrorDeNegocio('Esta caja ya fue cerrada.');
                }

                return $this->caja->cerrar($bloqueada, $declarados, $conteo->isNotEmpty() ? $conteo->all() : null);
            });
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        $conDiferencia = collect($cuadre)->filter(fn ($m) => abs($m['diferencia']) >= 0.005);

        if ($conDiferencia->isNotEmpty()) {
            $efectivoCuadre = collect($cuadre)->firstWhere('codigo', 'efectivo');
            Auditoria::registrar($request->user(), 'caja.cierre_con_diferencia', 'apertura_caja', $apertura->id, [
                'caja' => $apertura->caja?->nombre,
                'esperado' => $efectivoCuadre['esperado'],
                'contado' => $efectivoCuadre['declarado'],
                'diferencia' => $efectivoCuadre['diferencia'],
                'medios' => $conDiferencia->map(fn ($m) => [
                    'medio' => $m['nombre'],
                    'esperado' => $m['esperado'],
                    'declarado' => $m['declarado'],
                    'diferencia' => $m['diferencia'],
                ])->values()->all(),
            ]);
        }

        $detalle = $conDiferencia->isEmpty()
            ? 'Caja cuadrada, sin diferencias.'
            : $conDiferencia->map(fn ($m) => ($m['diferencia'] > 0 ? 'sobran' : 'faltan').' S/ '.number_format(abs($m['diferencia']), 2)." en {$m['nombre']}")
                ->implode(', ').'.';

        return back()
            ->with('success', 'Caja cerrada. '.ucfirst($detalle))
            ->with('ticket', route('caja.turnos.ticket', $apertura));
    }

    /** Ticket de cierre del turno (resumen por medio, conteo y diferencias) para la impresora térmica. */
    public function ticketCierre(Request $request, AperturaCaja $apertura)
    {
        $usuario = $request->user();

        abort_unless($apertura->empresa_id === $usuario->empresa_id, 403);
        abort_unless($apertura->usuario_id === $usuario->id || $usuario->can('caja.ver_todas'), 403);
        abort_unless($apertura->cerrada_en !== null, 404);

        $apertura->load(['caja:id,nombre,sucursal_id,ancho_ticket', 'caja.sucursal:id,nombre', 'usuario:id,nombre_completo', 'cierresMedios.medioPago:codigo,nombre']);

        $medios = $this->caja->resumenPorMedio($apertura);
        $cierres = $apertura->cierresMedios->keyBy('medio_pago_codigo');
        $ancho = (int) ($apertura->caja?->ancho_ticket ?? 80);
        $empresa = $usuario->empresa;

        $pdf = SnappyPdf::loadView('pdf.cierre-caja', [
            'apertura' => $apertura,
            'empresa' => $empresa,
            'medios' => $medios,
            'cierres' => $cierres,
            'ventas' => $apertura->comprobantes()->where('estado', 'emitido')->where('tipo_comprobante_codigo', '!=', '07')
                ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total), 0) AS total')->first(),
            'anuladas' => $apertura->comprobantes()->where('estado', 'anulado')->count(),
            'ancho' => $ancho,
        ])
            ->setOption('page-width', "{$ancho}mm")
            ->setOption('page-height', (int) ceil(110 + count($medios) * 18 + count($apertura->conteo_efectivo ?? []) * 4).'mm')
            ->setOption('margin-top', '3')
            ->setOption('margin-bottom', '3')
            ->setOption('margin-left', '4')
            ->setOption('margin-right', '4')
            ->setOption('encoding', 'utf-8');

        return $pdf->inline('cierre-'.$apertura->cerrada_en->format('Y-m-d-His').'.pdf');
    }
}
