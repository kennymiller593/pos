<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ErrorDeNegocio;
use App\Http\Controllers\Controller;
use App\Models\AgenteEmpresa;
use App\Models\AgenteIa;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\MensajeIa;
use App\Models\ProveedorIa;
use App\Models\Rubro;
use App\Services\IA\AsistenteIaService;
use App\Support\PlantillasAgente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Agentes de IA de la plataforma: instrucciones por rubro, cadena de modelos y activación por empresa. */
class AgenteIaController extends Controller
{
    public function __construct(private readonly AsistenteIaService $asistente) {}

    public function index(): Response
    {
        $usoMes = MensajeIa::query()
            ->join('ia_conversaciones', 'ia_conversaciones.id', '=', 'ia_mensajes.conversacion_id')
            ->selectRaw('ia_conversaciones.agente_id, count(*) as consultas, coalesce(sum(ia_mensajes.costo_usd), 0) as costo')
            ->where('ia_mensajes.rol', 'assistant')->where('ia_mensajes.creado_en', '>=', now()->startOfMonth())
            ->groupBy('ia_conversaciones.agente_id')->get()->keyBy('agente_id');

        return Inertia::render('Admin/IA/Agentes', [
            'agentes' => AgenteIa::with('rubro:codigo,nombre')->withCount(['activaciones as empresas_activas' => fn ($q) => $q->where('activo', true)])
                ->orderByDesc('activo')->orderBy('nombre')->get()->map(fn (AgenteIa $a) => [
                    'id' => $a->id,
                    'codigo' => $a->codigo,
                    'nombre' => $a->nombre,
                    'rubro_codigo' => $a->rubro_codigo,
                    'rubro' => $a->rubro?->nombre,
                    'descripcion' => $a->descripcion,
                    'instrucciones' => $a->instrucciones,
                    'cadena' => $a->eslabones(),
                    'limite_mensual' => $a->limite_mensual,
                    'max_pasos' => $a->max_pasos,
                    'sugerencias' => $a->sugerencias ?? [],
                    'activo' => $a->activo,
                    'razonar' => (bool) $a->razonar,
                    'empresas_activas' => (int) $a->empresas_activas,
                    'empresas_del_rubro' => $a->rubro_codigo ? Empresa::where('rubro_codigo', $a->rubro_codigo)->where('activo', true)->count() : 0,
                    'consultas_mes' => (int) ($usoMes[$a->id]->consultas ?? 0),
                    'costo_mes' => (float) ($usoMes[$a->id]->costo ?? 0),
                ]),
            'rubros' => Rubro::orderBy('nombre')->get(['codigo', 'nombre']),
            'proveedores' => ProveedorIa::with('modelos:id,proveedor_codigo,modelo')->orderBy('orden')->get()->map(fn ($p) => [
                'codigo' => $p->codigo,
                'nombre' => $p->nombre,
                'listo' => $p->activo && filled($p->api_key),
                'modelos' => $p->modelos->pluck('modelo'),
            ]),
            'plantillas' => PlantillasAgente::todas(),
            // empresas para el botón "Probar": el agente responde con el catálogo de la elegida
            'empresas' => Empresa::where('activo', true)->orderBy('razon_social')->get(['id', 'razon_social', 'nombre_comercial', 'rubro_codigo'])
                ->map(fn ($e) => ['id' => $e->id, 'nombre' => $e->nombre_comercial ?: $e->razon_social, 'rubro_codigo' => $e->rubro_codigo]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);
        $datos['codigo'] = $this->codigoLibre($datos['nombre']);

        $agente = AgenteIa::create($datos);
        Auditoria::registrar($request->user(), 'plataforma.ia_agente', 'ia_agente', $agente->id, ['nombre' => $agente->nombre, 'accion' => 'creado']);

        return back()->with('success', "Agente \"{$agente->nombre}\" creado. Actívalo en cada empresa desde su ficha, o para todo su rubro con un clic.");
    }

    public function update(Request $request, AgenteIa $agente): RedirectResponse
    {
        $agente->update($this->validar($request));
        Auditoria::registrar($request->user(), 'plataforma.ia_agente', 'ia_agente', $agente->id, ['nombre' => $agente->nombre, 'accion' => 'editado']);

        return back()->with('success', "Agente \"{$agente->nombre}\" guardado.");
    }

    /** Activa el agente en todas las empresas activas de su rubro que aún no lo tengan. */
    public function activarRubro(Request $request, AgenteIa $agente): RedirectResponse
    {
        if (! $agente->rubro_codigo) {
            return back()->with('error', 'Este agente no tiene rubro: actívalo empresa por empresa.');
        }

        $nuevas = 0;
        DB::transaction(function () use ($agente, &$nuevas) {
            Empresa::where('rubro_codigo', $agente->rubro_codigo)->where('activo', true)->pluck('id')->each(function ($empresaId) use ($agente, &$nuevas) {
                $activacion = AgenteEmpresa::firstOrNew(['empresa_id' => $empresaId, 'agente_id' => $agente->id]);
                if (! $activacion->exists || ! $activacion->activo) {
                    $activacion->fill(['activo' => true, 'activado_en' => now(), 'desactivado_en' => null])->save();
                    $nuevas++;
                }
            });
        });
        Auditoria::registrar($request->user(), 'plataforma.ia_agente_activado', 'ia_agente', $agente->id, ['rubro' => $agente->rubro_codigo, 'empresas' => $nuevas]);

        return back()->with('success', $nuevas
            ? "\"{$agente->nombre}\" activado en {$nuevas} empresa(s) del rubro {$agente->rubro?->nombre}."
            : 'Todas las empresas del rubro ya tenían este agente activo.');
    }

    /** Prueba el agente contra el catálogo de una empresa, sin guardar conversación. */
    public function probar(Request $request, AgenteIa $agente): JsonResponse
    {
        $datos = $request->validate([
            'empresa_id' => ['required', Rule::exists('empresas', 'id')],
            'texto' => ['required', 'string', 'max:'.AsistenteIaService::MAX_CARACTERES],
        ]);

        $empresa = Empresa::findOrFail($datos['empresa_id']);

        try {
            $r = $this->asistente->consultar($agente, $empresa, $empresa->sucursales()->where('activo', true)->orderBy('nombre')->first(), $datos['texto']);
        } catch (ErrorDeNegocio $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $r);
    }

    private function validar(Request $request): array
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'rubro_codigo' => ['nullable', Rule::exists('rubros', 'codigo')],
            'descripcion' => ['nullable', 'string', 'max:200'],
            'instrucciones' => ['required', 'string', 'max:12000'],
            'cadena' => ['required', 'array', 'min:1', 'max:6'],
            'cadena.*.proveedor' => ['required', Rule::exists('ia_proveedores', 'codigo')],
            'cadena.*.modelo' => ['required', 'string', 'max:120'],
            'limite_mensual' => ['required', 'integer', 'min:1', 'max:1000000'],
            'max_pasos' => ['required', 'integer', 'min:2', 'max:12'],
            'sugerencias' => ['nullable', 'array', 'max:6'],
            'sugerencias.*' => ['nullable', 'string', 'max:120'],
            'activo' => ['required', 'boolean'],
            'razonar' => ['sometimes', 'boolean'],
        ], [
            'instrucciones.required' => 'Escribe las instrucciones del agente.',
            'cadena.required' => 'Agrega al menos un modelo a la cadena.',
            'cadena.min' => 'Agrega al menos un modelo a la cadena.',
            'cadena.*.modelo.required' => 'Indica el nombre del modelo.',
        ]);

        $datos['cadena'] = collect($datos['cadena'])->map(fn ($e) => ['proveedor' => $e['proveedor'], 'modelo' => trim($e['modelo'])])->values()->all();
        $datos['sugerencias'] = collect($datos['sugerencias'] ?? [])->map(fn ($s) => trim($s))->filter()->values()->all();
        $datos['descripcion'] = filled($datos['descripcion'] ?? null) ? trim($datos['descripcion']) : null;
        $datos['rubro_codigo'] = $datos['rubro_codigo'] ?: null;

        return $datos;
    }

    private function codigoLibre(string $nombre): string
    {
        $base = Str::limit(Str::slug($nombre, '_'), 30, '') ?: 'agente';
        $codigo = $base;
        for ($i = 2; AgenteIa::where('codigo', $codigo)->exists(); $i++) {
            $codigo = "{$base}_{$i}";
        }

        return $codigo;
    }
}
