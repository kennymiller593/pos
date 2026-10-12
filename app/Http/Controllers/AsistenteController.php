<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Models\AgenteIa;
use App\Models\ConversacionIa;
use App\Models\MensajeIa;
use App\Services\IA\AsistenteIaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** El asistente de IA dentro de la app: conversaciones del usuario con los agentes activos de su empresa. */
class AsistenteController extends Controller
{
    /** Conversaciones recientes que se listan. */
    private const RECIENTES = 30;

    public function __construct(private readonly AsistenteIaService $asistente) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $usuario = $request->user();
        $agentes = $this->asistente->agentesDe($usuario->empresa);

        if ($agentes->isEmpty()) {
            return redirect()->route('inicio')->with('error', 'Tu empresa no tiene un asistente de IA activo.');
        }

        $conversaciones = ConversacionIa::query()
            ->where('usuario_id', $usuario->id)
            ->whereIn('agente_id', $agentes->pluck('id'))
            ->orderByDesc('actualizado_en')
            ->limit(self::RECIENTES)
            ->get(['id', 'agente_id', 'titulo', 'actualizado_en']);

        $actual = null;
        if ($id = $request->query('c')) {
            $conversacion = $conversaciones->firstWhere('id', $id) ? ConversacionIa::with('mensajes')->find($id) : null;
            $actual = $conversacion ? $this->conversacion($conversacion) : null;
        }

        return Inertia::render('Asistente/Index', [
            'agentes' => $agentes->map(fn (AgenteIa $a) => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'descripcion' => $a->descripcion,
                'sugerencias' => $a->sugerencias ?? [],
                'consultas_mes' => $this->asistente->consultasDelMes($usuario->empresa, $a),
                'limite_mensual' => $this->asistente->limiteDe($usuario->empresa, $a),
            ]),
            'conversaciones' => $conversaciones->map(fn ($c) => [
                'id' => $c->id, 'agente_id' => $c->agente_id, 'titulo' => $c->titulo, 'actualizado_en' => $c->actualizado_en->toDateTimeString(),
            ]),
            'actual' => $actual,
        ]);
    }

    /** Envía una consulta; crea la conversación si es nueva. Responde en JSON para que el chat no recargue. */
    public function enviar(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $datos = $request->validate([
            'conversacion_id' => ['nullable', Rule::exists('ia_conversaciones', 'id')->where('usuario_id', $usuario->id)],
            'agente_id' => ['required_without:conversacion_id', 'nullable', Rule::exists('ia_agentes', 'id')],
            'texto' => ['required', 'string', 'max:'.AsistenteIaService::MAX_CARACTERES],
        ], ['texto.required' => 'Escribe tu consulta.', 'texto.max' => 'La consulta es muy larga.']);

        $conversacion = filled($datos['conversacion_id'] ?? null)
            ? ConversacionIa::findOrFail($datos['conversacion_id'])
            : null;

        $agente = $conversacion?->agente ?? AgenteIa::findOrFail($datos['agente_id']);
        if (! $this->asistente->agentesDe($usuario->empresa)->contains('id', $agente->id)) {
            return response()->json(['mensaje' => 'Este agente no está activo para tu empresa.'], 403);
        }

        $conversacion ??= ConversacionIa::create(['empresa_id' => $usuario->empresa_id, 'usuario_id' => $usuario->id, 'agente_id' => $agente->id]);

        try {
            $respuesta = $this->asistente->responder($conversacion, $usuario, $datos['texto']);
        } catch (ErrorDeNegocio $e) {
            if ($conversacion->mensajes()->doesntExist()) {
                $conversacion->delete();
            }

            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json([
            'conversacion' => ['id' => $conversacion->id, 'agente_id' => $conversacion->agente_id, 'titulo' => $conversacion->titulo, 'actualizado_en' => $conversacion->actualizado_en->toDateTimeString()],
            'mensaje' => $this->mensaje($respuesta),
            'consultas_mes' => $this->asistente->consultasDelMes($usuario->empresa, $agente),
        ]);
    }

    public function eliminar(Request $request, ConversacionIa $conversacion): RedirectResponse
    {
        abort_unless($conversacion->usuario_id === $request->user()->id, 403);
        $conversacion->delete();

        return redirect()->route('asistente.index')->with('success', 'Conversación eliminada.');
    }

    private function conversacion(ConversacionIa $c): array
    {
        return [
            'id' => $c->id,
            'agente_id' => $c->agente_id,
            'titulo' => $c->titulo,
            'mensajes' => $c->mensajes->map(fn (MensajeIa $m) => $this->mensaje($m))->values(),
        ];
    }

    private function mensaje(MensajeIa $m): array
    {
        return [
            'id' => $m->id,
            'rol' => $m->rol,
            'contenido' => $m->contenido,
            'proveedor' => $m->proveedor_codigo,
            'modelo' => $m->modelo,
            'duracion_ms' => $m->duracion_ms,
            'herramientas' => collect($m->herramientas ?? [])->pluck('nombre')->unique()->values(),
            'creado_en' => $m->creado_en?->toDateTimeString(),
        ];
    }
}
