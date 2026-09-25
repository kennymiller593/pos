<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Suscripcion;
use App\Services\SuscripcionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Panel de la plataforma: todas las empresas, sus planes y su actividad. */
class EmpresaController extends Controller
{
    public function __construct(private readonly SuscripcionService $suscripciones) {}

    public function index(Request $request): Response
    {
        $buscar = trim((string) $request->query('buscar'));
        $estado = $request->query('estado'); // activa | prueba | vencida | inactiva

        $empresas = Empresa::query()
            ->withCount([
                'usuarios as usuarios_activos' => fn ($q) => $q->where('activo', true),
                'sucursales as sucursales_activas' => fn ($q) => $q->where('activo', true),
                'comprobantes as comprobantes_mes' => fn ($q) => $q->where('fecha_emision', '>=', now()->startOfMonth()->toDateString()),
            ])
            ->with(['suscripciones' => fn ($q) => $q->with('plan:id,codigo,nombre')->orderByDesc('fecha_fin')->limit(1)])
            ->when($buscar !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('razon_social', 'ilike', "%{$buscar}%")
                ->orWhere('nombre_comercial', 'ilike', "%{$buscar}%")
                ->orWhere('ruc', 'like', "{$buscar}%")))
            ->when($estado === 'inactiva', fn ($q) => $q->where('activo', false))
            ->orderByDesc('creado_en')
            ->paginate(20)
            ->withQueryString()
            ->through(function (Empresa $e) {
                $resumen = $this->suscripciones->resumen($e);

                return [
                    'id' => $e->id,
                    'ruc' => $e->ruc,
                    'razon_social' => $e->razon_social,
                    'nombre_comercial' => $e->nombre_comercial,
                    'activo' => (bool) $e->activo,
                    'facturacion_electronica' => (bool) $e->facturacion_electronica,
                    'entorno_sunat' => $e->entorno_sunat,
                    'creado_en' => $e->creado_en?->toDateString(),
                    'usuarios' => (int) $e->usuarios_activos,
                    'sucursales' => (int) $e->sucursales_activas,
                    'comprobantes_mes' => (int) $e->comprobantes_mes,
                    'suscripcion' => $resumen,
                ];
            });

        // el filtro por estado de suscripcion se calcula en PHP (depende de fechas y gracia)
        if (in_array($estado, ['activa', 'prueba', 'vencida'], true)) {
            $empresas->setCollection($empresas->getCollection()->filter(fn ($e) => match ($estado) {
                'prueba' => $e['suscripcion']['es_prueba'] && $e['suscripcion']['vigente'],
                'activa' => ! $e['suscripcion']['es_prueba'] && $e['suscripcion']['vigente'],
                'vencida' => ! $e['suscripcion']['vigente'],
            })->values());
        }

        return Inertia::render('Admin/Empresas/Index', [
            'empresas' => $empresas,
            'filtros' => ['buscar' => $buscar, 'estado' => $estado],
            'totales' => [
                'empresas' => Empresa::count(),
                'activas' => Empresa::where('activo', true)->count(),
                'registradas_mes' => Empresa::where('creado_en', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    public function show(Empresa $empresa): Response
    {
        $empresa->loadCount([
            'usuarios as usuarios_activos' => fn ($q) => $q->where('activo', true),
            'sucursales as sucursales_activas' => fn ($q) => $q->where('activo', true),
            'productos', 'clientes',
        ]);

        return Inertia::render('Admin/Empresas/Show', [
            'empresa' => [
                'id' => $empresa->id,
                'ruc' => $empresa->ruc,
                'razon_social' => $empresa->razon_social,
                'nombre_comercial' => $empresa->nombre_comercial,
                'rubro' => $empresa->rubro?->nombre,
                'regimen_tributario' => $empresa->regimen_tributario,
                'activo' => (bool) $empresa->activo,
                'facturacion_electronica' => (bool) $empresa->facturacion_electronica,
                'entorno_sunat' => $empresa->entorno_sunat,
                'certificado_vence_en' => $empresa->certificado_vence_en?->toDateString(),
                'creado_en' => $empresa->creado_en?->toDateTimeString(),
                'usuarios' => (int) $empresa->usuarios_activos,
                'sucursales' => (int) $empresa->sucursales_activas,
                'productos' => (int) $empresa->productos_count,
                'clientes' => (int) $empresa->clientes_count,
                'comprobantes_mes' => Comprobante::where('empresa_id', $empresa->id)
                    ->where('fecha_emision', '>=', now()->startOfMonth()->toDateString())->count(),
                'comprobantes_total' => Comprobante::where('empresa_id', $empresa->id)->count(),
                'ultima_venta' => Comprobante::where('empresa_id', $empresa->id)->max('creado_en'),
            ],
            'suscripcion' => $this->suscripciones->resumen($empresa),
            'historial' => Suscripcion::query()
                ->where('empresa_id', $empresa->id)
                ->with('plan:id,codigo,nombre,precio_mensual')
                ->orderByDesc('fecha_fin')
                ->get(['id', 'plan_id', 'fecha_inicio', 'fecha_fin', 'estado', 'es_prueba', 'nota', 'creado_en']),
            'usuarios' => $empresa->usuarios()->with('rol:id,nombre')->orderBy('nombre_completo')
                ->get(['id', 'nombre_completo', 'email', 'rol_id', 'activo', 'email_verificado_en', 'creado_en']),
            'planes' => Plan::where('activo', true)->where('codigo', '!=', 'prueba')->orderBy('orden')
                ->get(['codigo', 'nombre', 'precio_mensual', 'max_sucursales', 'max_usuarios', 'max_comprobantes_mes']),
        ]);
    }

    /** Activa o renueva un plan (tras recibir el pago). */
    public function activarPlan(Request $request, Empresa $empresa): RedirectResponse
    {
        $datos = $request->validate([
            'plan' => ['required', Rule::exists('planes', 'codigo')->where('activo', true)],
            'meses' => ['required', 'integer', 'min:1', 'max:36'],
            'nota' => ['nullable', 'string', 'max:250'],
        ]);

        $plan = Plan::where('codigo', $datos['plan'])->firstOrFail();
        $suscripcion = $this->suscripciones->activar($empresa, $plan, (int) $datos['meses'], $datos['nota'] ?? null);

        Auditoria::registrar($request->user(), 'plataforma.plan_activado', 'empresa', $empresa->id, [
            'empresa' => $empresa->razon_social,
            'plan' => $plan->codigo,
            'meses' => (int) $datos['meses'],
            'hasta' => $suscripcion->fecha_fin->toDateString(),
            'nota' => $datos['nota'] ?? null,
        ]);

        return back()->with('success', "Plan {$plan->nombre} activo hasta el {$suscripcion->fecha_fin->format('d/m/Y')}.");
    }

    /** Alarga la prueba (o la suscripción vigente) N días, p. ej. mientras el cliente decide. */
    public function extender(Request $request, Empresa $empresa): RedirectResponse
    {
        $datos = $request->validate(['dias' => ['required', 'integer', 'min:1', 'max:90']]);

        $suscripcion = $this->suscripciones->vigente($empresa) ?? $this->suscripciones->ultima($empresa);

        if (! $suscripcion) {
            return back()->with('error', 'La empresa no tiene ninguna suscripción que extender.');
        }

        $base = $suscripcion->fecha_fin->isFuture() ? $suscripcion->fecha_fin : now();
        $suscripcion->update(['estado' => 'activa', 'fecha_fin' => $base->copy()->addDays((int) $datos['dias'])->toDateString()]);

        Auditoria::registrar($request->user(), 'plataforma.suscripcion_extendida', 'empresa', $empresa->id, [
            'empresa' => $empresa->razon_social,
            'dias' => (int) $datos['dias'],
            'hasta' => $suscripcion->fecha_fin->toDateString(),
        ]);

        return back()->with('success', "Suscripción extendida hasta el {$suscripcion->fecha_fin->format('d/m/Y')}.");
    }

    /** Suspende o reactiva el acceso de toda la empresa. */
    public function alternarActivo(Request $request, Empresa $empresa): RedirectResponse
    {
        if ($empresa->id === $request->user()->empresa_id) {
            return back()->with('error', 'No puedes desactivar tu propia empresa.');
        }

        $empresa->update(['activo' => ! $empresa->activo]);

        Auditoria::registrar($request->user(), $empresa->activo ? 'plataforma.empresa_activada' : 'plataforma.empresa_desactivada', 'empresa', $empresa->id, [
            'empresa' => $empresa->razon_social,
        ]);

        return back()->with('success', $empresa->activo
            ? "{$empresa->razon_social} reactivada."
            : "{$empresa->razon_social} desactivada: sus usuarios ya no pueden entrar.");
    }
}
