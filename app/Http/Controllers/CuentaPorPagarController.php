<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Models\CuentaPorPagar;
use App\Models\MedioPago;
use App\Models\PagoProveedor;
use App\Services\CajaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CuentaPorPagarController extends Controller
{
    public function __construct(private readonly CajaService $caja) {}

    public function index(Request $request): Response
    {
        $empresaId = $request->user()->empresa_id;
        $filtros = $request->only(['buscar', 'estado']);

        $cuentas = CuentaPorPagar::query()
            ->where('empresa_id', $empresaId)
            ->with([
                'proveedor:id,razon_social,ruc,telefono',
                'compra:id,serie_numero,fecha',
            ])
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->whereHas('proveedor', fn ($p) => $p
                ->where('razon_social', 'ilike', "%{$buscar}%")
                ->orWhere('ruc', 'ilike', "{$buscar}%")))
            ->when(
                $filtros['estado'] ?? null,
                fn ($q, $estado) => $q->where('estado', $estado),
                fn ($q) => $q->orderByRaw("CASE WHEN estado = 'pagado' THEN 1 ELSE 0 END"),
            )
            ->orderByRaw('fecha_vencimiento asc nulls last')
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        $resumen = [
            'por_pagar' => (float) CuentaPorPagar::query()
                ->where('empresa_id', $empresaId)
                ->where('estado', '!=', 'pagado')
                ->selectRaw('COALESCE(SUM(monto_total - monto_pagado), 0) as saldo')
                ->value('saldo'),
            'cuentas_pendientes' => CuentaPorPagar::query()
                ->where('empresa_id', $empresaId)
                ->where('estado', '!=', 'pagado')
                ->count(),
            'vencidas' => CuentaPorPagar::query()
                ->where('empresa_id', $empresaId)
                ->where('estado', '!=', 'pagado')
                ->whereNotNull('fecha_vencimiento')
                ->where('fecha_vencimiento', '<', now()->toDateString())
                ->count(),
        ];

        return Inertia::render('CuentasPorPagar/Index', [
            'cuentas' => $cuentas,
            'filtros' => $filtros,
            'resumen' => $resumen,
            'mediosPago' => MedioPago::orderBy('nombre')->get(['codigo', 'nombre', 'requiere_referencia']),
            'cajaAbierta' => $this->caja->aperturaDe($request->user()) !== null,
        ]);
    }

    public function pagar(Request $request, CuentaPorPagar $cuenta): RedirectResponse
    {
        $usuario = $request->user();

        abort_unless($cuenta->empresa_id === $usuario->empresa_id, 403);

        if ($cuenta->estado === 'pagado') {
            return back()->with('error', 'Esta deuda ya está saldada.');
        }

        $saldo = round((float) $cuenta->monto_total - (float) $cuenta->monto_pagado, 2);

        $datos = $request->validate([
            'monto' => ['required', 'numeric', 'gt:0', "max:{$saldo}"],
            'medio_pago_codigo' => ['required', 'exists:medios_pago,codigo'],
            'referencia' => ['nullable', 'string', 'max:100'],
        ], [
            'monto.required' => 'Ingresa el monto del pago.',
            'monto.gt' => 'El monto debe ser mayor a 0.',
            'monto.max' => "El monto no puede superar el saldo (S/ {$saldo}).",
        ]);

        // si el pago sale del cajon, necesita caja abierta y efectivo suficiente
        $apertura = null;
        if ($datos['medio_pago_codigo'] === 'efectivo') {
            $apertura = $this->caja->aperturaDe($usuario);

            if (! $apertura) {
                return back()->with('error', 'Para pagar en efectivo necesitas abrir caja: el pago sale del cajón.');
            }

            $disponible = $this->caja->resumen($apertura)['esperado'];
            if ((float) $datos['monto'] > $disponible) {
                return back()->with('error', 'No hay suficiente efectivo en caja (disponible: S/ '.number_format($disponible, 2).').');
            }
        }

        try {
            DB::transaction(function () use ($cuenta, $datos, $apertura, $usuario) {
                // se relee con bloqueo: un doble clic no puede pagar la deuda dos veces
                $bloqueada = CuentaPorPagar::lockForUpdate()->findOrFail($cuenta->id);
                $monto = round((float) $datos['monto'], 2);
                $saldo = round((float) $bloqueada->monto_total - (float) $bloqueada->monto_pagado, 2);

                if ($bloqueada->estado === 'pagado' || $monto > $saldo + 0.001) {
                    throw new ErrorDeNegocio('El saldo cambió mientras registrabas el pago (ahora es S/ '.number_format($saldo, 2).'). Revisa y vuelve a intentarlo.');
                }

                PagoProveedor::create([
                    'empresa_id' => $bloqueada->empresa_id,
                    'cuenta_id' => $bloqueada->id,
                    'apertura_id' => $apertura?->id,
                    'usuario_id' => $usuario->id,
                    'medio_pago_codigo' => $datos['medio_pago_codigo'],
                    'monto' => $monto,
                    'referencia' => $datos['referencia'] ?? null,
                ]);

                $nuevoPagado = round((float) $bloqueada->monto_pagado + $monto, 2);

                $bloqueada->update([
                    'monto_pagado' => $nuevoPagado,
                    'estado' => $nuevoPagado >= (float) $bloqueada->monto_total - 0.001 ? 'pagado' : 'parcial',
                ]);
            });
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo registrar el pago. Intenta de nuevo.');
        }

        $cuenta->refresh();

        return back()->with('success', $cuenta->estado === 'pagado'
            ? 'Pago registrado. ¡Deuda saldada!'
            : 'Pago registrado. Saldo restante: S/ '.number_format((float) $cuenta->monto_total - (float) $cuenta->monto_pagado, 2).'.');
    }
}
