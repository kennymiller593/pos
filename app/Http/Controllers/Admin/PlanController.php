<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Catálogo de planes: precios y límites que ven la landing, Suscripción y los controles de límite. */
class PlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Planes/Index', [
            'planes' => Plan::query()
                ->withCount(['suscripciones as empresas_activas' => fn ($q) => $q->where('estado', 'activa')])
                ->orderBy('orden')
                ->get(['id', 'codigo', 'nombre', 'descripcion', 'precio_mensual', 'max_sucursales', 'max_usuarios', 'max_comprobantes_mes', 'activo', 'orden']),
        ]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:250'],
            'precio_mensual' => ['required', 'numeric', 'min:0'],
            'max_sucursales' => ['nullable', 'integer', 'min:1'],
            'max_usuarios' => ['nullable', 'integer', 'min:1'],
            'max_comprobantes_mes' => ['nullable', 'integer', 'min:1'],
            'activo' => ['required', 'boolean'],
            'orden' => ['required', 'integer', 'min:0'],
        ], [
            'precio_mensual.min' => 'El precio no puede ser negativo.',
        ]);

        // la prueba no se puede apagar: la necesita el registro
        if ($plan->codigo === 'prueba') {
            $datos['activo'] = true;
        }

        $antes = $plan->only(array_keys($datos));
        $plan->update($datos);

        Auditoria::registrar($request->user(), 'plataforma.plan_editado', 'plan', $plan->id, [
            'plan' => $plan->codigo,
            'antes' => $antes,
            'despues' => $plan->only(array_keys($datos)),
        ]);

        return back()->with('success', "Plan {$plan->nombre} actualizado.");
    }
}
