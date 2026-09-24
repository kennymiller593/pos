<?php

namespace App\Http\Controllers;

use App\Models\AperturaCaja;
use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Services\CajaService;
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
            ->with(['caja:id,nombre,sucursal_id', 'caja.sucursal:id,nombre', 'usuario:id,nombre_completo'])
            ->withSum(['movimientos as ingresos' => fn ($q) => $q->where('tipo', 'ingreso')], 'monto')
            ->withSum(['movimientos as egresos' => fn ($q) => $q->where('tipo', 'egreso')], 'monto')
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
                'movimientos' => $apertura->movimientos()
                    ->with('usuario:id,nombre_completo')
                    ->latest('creado_en')
                    ->limit(50)
                    ->get(['id', 'tipo', 'concepto', 'monto', 'usuario_id', 'creado_en']),
            ] : null,
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

        AperturaCaja::create([
            'empresa_id' => $usuario->empresa_id,
            'caja_id' => $caja->id,
            'usuario_id' => $usuario->id,
            'monto_inicial' => $datos['monto_inicial'],
        ]);

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
            'monto_cierre' => ['required', 'numeric', 'min:0'],
        ], [
            'monto_cierre.required' => 'Ingresa el efectivo contado.',
            'monto_cierre.min' => 'El monto no puede ser negativo.',
        ]);

        $esperado = $this->caja->resumen($apertura)['esperado'];

        DB::transaction(function () use ($apertura, $datos, $esperado) {
            $apertura->update([
                'monto_cierre' => $datos['monto_cierre'],
                'monto_sistema' => $esperado,
                'cerrada_en' => now(),
            ]);
        });

        $diferencia = round($datos['monto_cierre'] - $esperado, 2);

        if ($diferencia != 0) {
            Auditoria::registrar($request->user(), 'caja.cierre_con_diferencia', 'apertura_caja', $apertura->id, [
                'caja' => $apertura->caja?->nombre,
                'esperado' => round($esperado, 2),
                'contado' => round((float) $datos['monto_cierre'], 2),
                'diferencia' => $diferencia,
            ]);
        }

        $detalle = $diferencia == 0
            ? 'Caja cuadrada, sin diferencias.'
            : ($diferencia > 0
                ? 'Sobran S/ '.number_format($diferencia, 2).'.'
                : 'Faltan S/ '.number_format(abs($diferencia), 2).'.');

        return back()->with('success', "Caja cerrada. {$detalle}");
    }
}
