<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\ConversacionIa;
use App\Services\CloudflareService;
use App\Services\IA\AsistenteIaService;
use App\Services\PortadaTiendaService;
use App\Support\Tienda;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** La página del asistente de IA de la empresa: cómo se presenta el chatbot en la tienda, uso del mes y accesos. */
class AsistenteConfigController extends Controller
{
    public function __construct(private readonly AsistenteIaService $asistente) {}

    public function edit(Request $request): Response|RedirectResponse
    {
        $empresa = $request->user()->empresa;
        $agentes = $this->asistente->agentesDe($empresa);
        if ($agentes->isEmpty()) {
            return redirect()->route('inicio')->with('error', 'Tu empresa no tiene un asistente de IA activo.');
        }
        $config = Tienda::config($empresa);

        return Inertia::render('Asistente/Configurar', [
            'negocio' => $empresa->nombre_comercial ?: $empresa->razon_social,
            'agentes' => $agentes->map(fn ($a) => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'descripcion' => $a->descripcion,
                'consultas_mes' => $this->asistente->consultasDelMes($empresa, $a),
                'limite_mensual' => $this->asistente->limiteDe($empresa, $a),
            ])->values(),
            'chatbot' => [
                'activo' => (bool) $config['chatbot_activo'],
                'agente' => $config['chatbot_agente'],
                'nombre' => $config['chatbot_nombre'],
                'saludo' => $config['chatbot_saludo'],
                'color' => $config['chatbot_color'],
                'avatar' => $config['chatbot_avatar'],
            ],
            'tienda' => [
                'habilitada' => (bool) $empresa->tienda_habilitada,
                'publicada' => (bool) $empresa->tienda_publicada,
                'url' => Tienda::urlDe($empresa),
                'color' => Tienda::colores($config)[0],
                'mostrar_precios' => (bool) $config['mostrar_precios'],
            ],
            'chats_sin_leer' => $empresa->tienda_habilitada ? ConversacionIa::query()
                ->where('empresa_id', $empresa->id)->where('canal', 'tienda')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('ia_mensajes')
                    ->whereColumn('ia_mensajes.conversacion_id', 'ia_conversaciones.id')
                    ->where('ia_mensajes.rol', 'user')
                    ->where(fn ($w) => $w->whereNull('ia_conversaciones.leida_en')->orWhereColumn('ia_mensajes.creado_en', '>', 'ia_conversaciones.leida_en')))
                ->count() : 0,
        ]);
    }

    /** Guarda cómo se presenta el chatbot en la tienda (vive en la configuración de la tienda). */
    public function update(Request $request, PortadaTiendaService $portada): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        abort_unless($empresa->tienda_habilitada, 403, 'La tienda en línea no está contratada.');
        $agentes = $this->asistente->agentesDe($empresa);

        $datos = $request->validate([
            'chatbot_activo' => ['required', 'boolean'],
            'chatbot_agente' => ['nullable', 'string', function (string $atributo, mixed $valor, \Closure $falla) use ($request, $agentes) {
                if ($request->boolean('chatbot_activo') && $agentes->isEmpty()) {
                    $falla('Tu empresa aún no tiene un agente de IA activo: pídelo a la plataforma.');
                } elseif (filled($valor) && ! $agentes->contains('id', $valor)) {
                    $falla('Ese agente no está activo para tu empresa.');
                }
            }],
            'chatbot_nombre' => ['nullable', 'string', 'max:40'],
            'chatbot_saludo' => ['nullable', 'string', 'max:200'],
            'chatbot_color' => ['nullable', 'string', function (string $atributo, mixed $valor, \Closure $falla) {
                if (filled($valor) && ! Tienda::esColor((string) $valor)) {
                    $falla('Elige un color válido, por ejemplo #7C3AED.');
                }
            }],
            'chatbot_avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'chatbot_avatar_quitar' => ['nullable', 'boolean'],
        ], [
            'chatbot_avatar.image' => 'La imagen del asistente debe ser JPG, PNG o WEBP.',
            'chatbot_avatar.max' => 'La imagen no debe pesar más de 2 MB.',
        ]);

        $config = Tienda::config($empresa);
        $avatarAnterior = $config['chatbot_avatar'];

        $config['chatbot_activo'] = (bool) $datos['chatbot_activo'];
        $config['chatbot_agente'] = filled($datos['chatbot_agente'] ?? null) ? $datos['chatbot_agente'] : ($agentes->count() === 1 ? $agentes->first()->id : null);
        $config['chatbot_nombre'] = filled($datos['chatbot_nombre'] ?? null) ? trim($datos['chatbot_nombre']) : null;
        $config['chatbot_saludo'] = filled($datos['chatbot_saludo'] ?? null) ? trim($datos['chatbot_saludo']) : null;
        $config['chatbot_color'] = filled($datos['chatbot_color'] ?? null) ? strtoupper(trim($datos['chatbot_color'])) : null;
        $config['chatbot_avatar'] = match (true) {
            $request->hasFile('chatbot_avatar') => $portada->guardarIcono($request->file('chatbot_avatar'), $empresa),
            $request->boolean('chatbot_avatar_quitar') => null,
            default => $avatarAnterior,
        };

        $empresa->update(['tienda_config' => $config]);
        if ($avatarAnterior && $avatarAnterior !== $config['chatbot_avatar']) {
            $portada->eliminar($avatarAnterior);
        }

        // la tienda está en la caché de borde: se purga para que el cambio se vea al momento
        app(CloudflareService::class)->purgarTienda($empresa->fresh());

        Auditoria::registrar($request->user(), 'tienda.chatbot', 'empresa', $empresa->id, ['activo' => $config['chatbot_activo'], 'nombre' => $config['chatbot_nombre']]);

        return back()->with('success', $config['chatbot_activo'] ? 'Asistente guardado: ya se ve en tu tienda.' : 'Asistente guardado. No se muestra en la tienda.');
    }
}
