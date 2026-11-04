<?php

namespace App\Http\Controllers;

use App\Models\AperturaCaja;
use App\Models\MedioPago;
use App\Services\CajaService;
use App\Services\MovimientosCajaService;
use App\Support\ContextoSucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Movimientos de caja: todo lo que entró y salió, fila por fila, por rango de fechas o por turno. */
class CajaMovimientosController extends Controller
{
    /** Rango máximo de fechas de la lista (no está paginada). */
    public const MAX_DIAS = 92;

    public function __construct(private readonly MovimientosCajaService $movimientos, private readonly CajaService $caja) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        $filtros = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'turno' => ['nullable', 'uuid'],
        ], [
            'hasta.after_or_equal' => 'La fecha "hasta" no puede ser anterior a "desde".',
        ]);

        // la lista no se pagina: un rango de meses enteros en una tienda grande sería pesado para la página
        if (filled($filtros['desde'] ?? null) && filled($filtros['hasta'] ?? null)
            && \Carbon\Carbon::parse($filtros['desde'])->diffInDays(\Carbon\Carbon::parse($filtros['hasta'])) > self::MAX_DIAS) {
            return back()->withErrors(['hasta' => 'Elige un rango de hasta '.self::MAX_DIAS.' días.']);
        }

        // un turno concreto (desde el historial): se listan solo sus movimientos, sin importar las fechas
        $turno = null;
        if (filled($filtros['turno'] ?? null)) {
            $turno = AperturaCaja::where('empresa_id', $usuario->empresa_id)
                ->when(! $usuario->can('caja.ver_todas'), fn ($q) => $q->where('usuario_id', $usuario->id))
                ->with(['caja:id,nombre,sucursal_id', 'caja.sucursal:id,nombre', 'usuario:id,nombre_completo'])
                ->find($filtros['turno']);
            // y de una sucursal a la que el usuario puede entrar (usuario_sucursales)
            abort_unless($turno && ContextoSucursal::de($request)->esAccesible($turno->caja?->sucursal_id ?? ''), 404);
        }

        $hoy = now()->toDateString();
        $desde = $filtros['desde'] ?? $hoy;
        $hasta = $filtros['hasta'] ?? $hoy;

        $lista = $this->movimientos->listar($usuario, $this->sucursalesConsulta($request), $turno, $desde, $hasta);

        return Inertia::render('Caja/Movimientos', [
            ...$lista,
            'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'turno' => $turno?->id],
            'turno' => $turno ? [
                'id' => $turno->id,
                'caja' => $turno->caja?->nombre.($turno->caja?->sucursal ? " · {$turno->caja->sucursal->nombre}" : ''),
                'cajero' => $turno->usuario?->nombre_completo,
                'abierta_en' => $turno->abierta_en,
                'cerrada_en' => $turno->cerrada_en,
                'monto_inicial' => (float) $turno->monto_inicial,
                // el cuadre del cajón: con qué abrió, cuánto debía haber en efectivo y cuánto se contó
                'resumen' => $this->caja->resumen($turno),
                // sin el medio interno 'adelanto' (al entregar un pedido): ese dinero ya se contó el día que lo dejaron
                'medios' => array_values(array_filter($this->caja->resumenPorMedio($turno), fn ($m) => ! in_array($m['codigo'], MedioPago::INTERNOS, true))),
                'monto_cierre' => $turno->cerrada_en ? (float) $turno->monto_cierre : null,
                // el cuadre tal como quedó al cerrar (mismo dato que el historial): esperado, contado y diferencia en efectivo
                'cierre' => $turno->cerrada_en ? [
                    'esperado' => (float) $turno->monto_sistema,
                    'contado' => (float) $turno->monto_cierre,
                    'diferencia' => round((float) ($turno->cierresMedios()->where('medio_pago_codigo', 'efectivo')->value('diferencia') ?? ((float) $turno->monto_cierre - (float) $turno->monto_sistema)), 2),
                ] : null,
                'observaciones' => $turno->observaciones,
            ] : null,
            'cajaAbierta' => ($abierta = $this->caja->aperturaDe($usuario)) ? ['id' => $abierta->id, 'caja' => $abierta->caja?->nombre] : null,
        ]);
    }

    /** El "ojo" de una fila: la venta con sus productos, la compra, el cobro o el movimiento manual. */
    public function detalle(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(['venta', 'compra', 'pago_proveedor', 'cobro', 'movimiento'])],
            'id' => ['required', 'uuid'],
        ]);

        $detalle = $this->movimientos->detalle($request->user(), $this->sucursalesConsulta($request), $datos['tipo'], $datos['id']);
        abort_unless($detalle, 404);

        return response()->json($detalle);
    }
}
