<?php

namespace App\Http\Controllers;

use App\Models\Cobro;
use App\Models\CuentaPorCobrar;
use App\Models\MedioPago;
use App\Services\CajaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CuentaPorCobrarController extends Controller
{
    public function __construct(private readonly CajaService $caja)
    {
    }

    public function index(Request $request): Response
    {
        $empresaId = $request->user()->empresa_id;
        $filtros = $request->only(['buscar', 'estado']);

        $cuentas = CuentaPorCobrar::query()
            ->where('empresa_id', $empresaId)
            ->with([
                'cliente:id,nombre,numero_documento,telefono',
                'comprobante:id,serie,correlativo,fecha_emision',
            ])
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->whereHas('cliente', fn ($c) => $c
                ->where('nombre', 'ilike', "%{$buscar}%")
                ->orWhere('numero_documento', 'ilike', "{$buscar}%")))
            ->when(
                $filtros['estado'] ?? null,
                fn ($q, $estado) => $q->where('estado', $estado),
                fn ($q) => $q->orderByRaw("CASE WHEN estado = 'pagado' THEN 1 ELSE 0 END"),
            )
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        $resumen = [
            'por_cobrar' => (float) CuentaPorCobrar::query()
                ->where('empresa_id', $empresaId)
                ->where('estado', '!=', 'pagado')
                ->selectRaw('COALESCE(SUM(monto_total - monto_pagado), 0) as saldo')
                ->value('saldo'),
            'cuentas_pendientes' => CuentaPorCobrar::query()
                ->where('empresa_id', $empresaId)
                ->where('estado', '!=', 'pagado')
                ->count(),
        ];

        return Inertia::render('CuentasPorCobrar/Index', [
            'cuentas' => $cuentas,
            'filtros' => $filtros,
            'resumen' => $resumen,
            'mediosPago' => MedioPago::orderBy('nombre')->get(['codigo', 'nombre', 'requiere_referencia']),
            'cajaAbierta' => $this->caja->aperturaDe($request->user()) !== null,
        ]);
    }

    public function cobrar(Request $request, CuentaPorCobrar $cuenta): RedirectResponse
    {
        $usuario = $request->user();

        abort_unless($cuenta->empresa_id === $usuario->empresa_id, 403);

        $apertura = $this->caja->aperturaDe($usuario);
        if (! $apertura) {
            return back()->with('error', 'Necesitas abrir caja para registrar cobros.');
        }

        if ($cuenta->estado === 'pagado') {
            return back()->with('error', 'Esta cuenta ya está pagada.');
        }

        $saldo = round((float) $cuenta->monto_total - (float) $cuenta->monto_pagado, 2);

        $datos = $request->validate([
            'monto' => ['required', 'numeric', 'gt:0', "max:{$saldo}"],
            'medio_pago_codigo' => ['required', 'exists:medios_pago,codigo'],
            'referencia' => ['nullable', 'string', 'max:100'],
        ], [
            'monto.required' => 'Ingresa el monto del cobro.',
            'monto.gt' => 'El monto debe ser mayor a 0.',
            'monto.max' => "El monto no puede superar el saldo (S/ {$saldo}).",
        ]);

        try {
            DB::transaction(function () use ($cuenta, $datos, $apertura, $usuario) {
                Cobro::create([
                    'empresa_id' => $cuenta->empresa_id,
                    'cuenta_id' => $cuenta->id,
                    'apertura_id' => $apertura->id,
                    'usuario_id' => $usuario->id,
                    'medio_pago_codigo' => $datos['medio_pago_codigo'],
                    'monto' => round((float) $datos['monto'], 2),
                    'referencia' => $datos['referencia'] ?? null,
                ]);

                $nuevoPagado = round((float) $cuenta->monto_pagado + (float) $datos['monto'], 2);

                $cuenta->update([
                    'monto_pagado' => $nuevoPagado,
                    'estado' => $nuevoPagado >= (float) $cuenta->monto_total ? 'pagado' : 'parcial',
                ]);
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo registrar el cobro. Intenta de nuevo.');
        }

        $cuenta->refresh();

        return back()->with('success', $cuenta->estado === 'pagado'
            ? 'Cobro registrado. ¡Cuenta saldada!'
            : 'Cobro registrado. Saldo restante: S/ ' . number_format((float) $cuenta->monto_total - (float) $cuenta->monto_pagado, 2) . '.');
    }
}
