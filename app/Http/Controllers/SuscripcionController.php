<?php

namespace App\Http\Controllers;

use App\Models\Comprobante;
use App\Models\Plan;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Services\SuscripcionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuscripcionController extends Controller
{
    public function __construct(private readonly SuscripcionService $suscripciones) {}

    /** Estado del plan, uso actual frente a los límites y catálogo de planes. */
    public function index(Request $request): Response
    {
        $empresa = $request->user()->empresa;
        $vigente = $this->suscripciones->vigente($empresa);

        return Inertia::render('Suscripcion/Index', [
            'suscripcion' => $this->suscripciones->resumen($empresa),
            'uso' => [
                'usuarios' => Usuario::where('empresa_id', $empresa->id)->where('activo', true)->count(),
                'sucursales' => Sucursal::where('empresa_id', $empresa->id)->where('activo', true)->count(),
                'comprobantes_mes' => Comprobante::where('empresa_id', $empresa->id)
                    ->where('fecha_emision', '>=', now()->startOfMonth()->toDateString())
                    ->count(),
            ],
            'limites' => $vigente?->plan ? [
                'usuarios' => $vigente->plan->max_usuarios,
                'sucursales' => $vigente->plan->max_sucursales,
                'comprobantes_mes' => $vigente->plan->max_comprobantes_mes,
            ] : null,
            'planes' => Plan::query()
                ->where('activo', true)
                ->where('codigo', '!=', 'prueba')
                ->orderBy('orden')
                ->get(['id', 'codigo', 'nombre', 'descripcion', 'precio_mensual', 'max_sucursales', 'max_usuarios', 'max_comprobantes_mes']),
            'historial' => Suscripcion::query()
                ->where('empresa_id', $empresa->id)
                ->with('plan:id,nombre')
                ->orderByDesc('fecha_fin')
                ->limit(12)
                ->get(['id', 'plan_id', 'fecha_inicio', 'fecha_fin', 'estado', 'es_prueba', 'nota']),
            'contacto' => [
                'whatsapp' => config('app.soporte_whatsapp'),
                'email' => config('app.soporte_email'),
            ],
            'empresa' => ['ruc' => $empresa->ruc, 'razon_social' => $empresa->razon_social],
        ]);
    }
}
