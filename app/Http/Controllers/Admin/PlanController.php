<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre', 'descripcion', 'precio_mensual', 'max_sucursales', 'max_usuarios', 'max_comprobantes_mes', 'activo', 'publico', 'orden']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);
        $datos['codigo'] = $this->codigoUnico($datos['nombre']);

        $plan = Plan::create($datos);

        Auditoria::registrar($request->user(), 'plataforma.plan_creado', 'plan', $plan->id, [
            'plan' => $plan->codigo,
            'datos' => $datos,
        ]);

        return back()->with('success', "Plan {$plan->nombre} creado.");
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $datos = $this->validar($request, $plan);

        // la prueba no se puede apagar (la necesita el registro) y nunca se ofrece en la landing
        if ($plan->codigo === 'prueba') {
            $datos['activo'] = true;
            $datos['publico'] = $plan->publico;
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

    private function validar(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:50', Rule::unique('planes', 'nombre')->ignore($plan?->id)],
            'descripcion' => ['nullable', 'string', 'max:250'],
            'precio_mensual' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'max_sucursales' => ['nullable', 'integer', 'min:1'],
            'max_usuarios' => ['nullable', 'integer', 'min:1'],
            'max_comprobantes_mes' => ['nullable', 'integer', 'min:1'],
            'activo' => ['required', 'boolean'],
            'publico' => ['required', 'boolean'],
            'orden' => ['required', 'integer', 'min:0', 'max:32767'],
        ], [
            'nombre.unique' => 'Ya existe un plan con ese nombre.',
            'precio_mensual.min' => 'El precio no puede ser negativo.',
        ]);
    }

    /** "Plan Corporativo" -> "plan_corporativo" (con sufijo si ya existe). */
    private function codigoUnico(string $nombre): string
    {
        $base = Str::limit(Str::slug($nombre, '_'), 26, '') ?: 'plan';
        $codigo = $base;

        for ($i = 2; Plan::where('codigo', $codigo)->exists(); $i++) {
            $codigo = "{$base}_{$i}";
        }

        return $codigo;
    }
}
