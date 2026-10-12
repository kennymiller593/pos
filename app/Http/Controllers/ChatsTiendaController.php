<?php

namespace App\Http\Controllers;

use App\Models\ConversacionIa;
use App\Models\MensajeIa;
use App\Models\Producto;
use App\Services\IA\AsistenteIaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Los chats que dejan los clientes en la tienda en línea: el negocio los lee y puede tomar la conversación. */
class ChatsTiendaController extends Controller
{
    private const RECIENTES = 60;

    public function __construct(private readonly AsistenteIaService $asistente) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $empresa = $request->user()->empresa;
        if (! $this->asistente->tieneAgentes($empresa)) {
            return redirect()->route('inicio')->with('error', 'Tu empresa no tiene un asistente de IA activo.');
        }

        $conversaciones = ConversacionIa::query()
            ->where('empresa_id', $empresa->id)->where('canal', 'tienda')
            ->with(['atendidaPor:id,nombre_completo'])
            ->withCount(['mensajes as total_mensajes'])
            ->withMax(['mensajes as ultimo_cliente_en' => fn ($q) => $q->where('rol', 'user')], 'creado_en')
            ->orderByDesc('actualizado_en')
            ->limit(self::RECIENTES)
            ->get();

        $ultimos = MensajeIa::query()
            ->whereIn('conversacion_id', $conversaciones->pluck('id'))
            ->orderByDesc('creado_en')
            ->get(['conversacion_id', 'rol', 'contenido', 'creado_en'])
            ->unique('conversacion_id')
            ->keyBy('conversacion_id');

        $actual = null;
        if ($id = $request->query('c')) {
            $conversacion = $conversaciones->firstWhere('id', $id);
            if ($conversacion) {
                ConversacionIa::whereKey($conversacion->id)->toBase()->update(['leida_en' => now()]); // toBase: sin tocar actualizado_en
                $actual = $this->conversacion($conversacion);
            }
        }

        return Inertia::render('Asistente/Chats', [
            'negocio' => $empresa->nombre_comercial ?: $empresa->razon_social,
            'conversaciones' => $conversaciones->map(fn (ConversacionIa $c) => [
                'id' => $c->id,
                'titulo' => $c->titulo,
                'modo' => $c->modo,
                'atendida_por' => $c->atendidaPor?->nombre_completo,
                'mensajes' => (int) $c->total_mensajes,
                'ultimo' => $ultimos[$c->id] ? ['rol' => $ultimos[$c->id]->rol, 'texto' => Str::limit($ultimos[$c->id]->contenido, 70)] : null,
                // sin leer: el cliente escribió después de la última vez que alguien del negocio abrió el chat
                'sin_leer' => $c->ultimo_cliente_en && (! $c->leida_en || $c->ultimo_cliente_en > $c->leida_en->toDateTimeString()) && $c->id !== $id,
                'actualizado_en' => $c->actualizado_en->toDateTimeString(),
            ]),
            'actual' => $actual,
        ]);
    }

    /** Mensajes nuevos de una conversación (para que la pantalla se actualice sola). */
    public function mensajes(Request $request, ConversacionIa $conversacion): JsonResponse
    {
        $this->autorizar($request, $conversacion);
        $desde = $request->query('desde');
        $desde = is_string($desde) && Str::isUuid($desde) ? $desde : null;
        ConversacionIa::whereKey($conversacion->id)->toBase()->update(['leida_en' => now()]); // toBase: sin tocar actualizado_en

        return response()->json([
            'modo' => $conversacion->modo,
            'atendida_por' => $conversacion->atendidaPor?->nombre_completo,
            'mensajes' => $conversacion->mensajes()->when($desde, fn ($q) => $q->where('id', '>', $desde))->get()->map(fn ($m) => $this->mensaje($m))->values(),
        ]);
    }

    /** El negocio toma la conversación: el bot deja de responder y lo que escriba el cliente queda esperando. */
    public function tomar(Request $request, ConversacionIa $conversacion): JsonResponse
    {
        $this->autorizar($request, $conversacion);
        $conversacion->forceFill(['modo' => 'humano', 'atendida_por' => $request->user()->id])->save();

        return response()->json(['modo' => 'humano', 'atendida_por' => $request->user()->nombre_completo]);
    }

    /** Devuelve la conversación al asistente. */
    public function devolver(Request $request, ConversacionIa $conversacion): JsonResponse
    {
        $this->autorizar($request, $conversacion);
        $conversacion->forceFill(['modo' => 'bot', 'atendida_por' => null])->save();

        return response()->json(['modo' => 'bot', 'atendida_por' => null]);
    }

    /** Un mensaje del negocio al cliente (toma la conversación si aún no lo había hecho). */
    public function responder(Request $request, ConversacionIa $conversacion): JsonResponse
    {
        $this->autorizar($request, $conversacion);
        $datos = $request->validate(['texto' => ['required', 'string', 'max:'.AsistenteIaService::MAX_CARACTERES]], ['texto.required' => 'Escribe el mensaje.']);

        if ($conversacion->modo !== 'humano') {
            $conversacion->forceFill(['modo' => 'humano', 'atendida_por' => $request->user()->id])->save();
        }
        $mensaje = MensajeIa::create([
            'conversacion_id' => $conversacion->id, 'empresa_id' => $conversacion->empresa_id, 'rol' => 'humano',
            'contenido' => trim($datos['texto']), 'usuario_id' => $request->user()->id,
        ]);
        $conversacion->touch();

        return response()->json(['modo' => 'humano', 'atendida_por' => $conversacion->atendidaPor?->nombre_completo ?? $request->user()->nombre_completo, 'mensaje' => $this->mensaje($mensaje)]);
    }

    /** Borra la conversación con sus mensajes (el cliente, si vuelve a escribir, empieza una nueva). */
    public function eliminar(Request $request, ConversacionIa $conversacion): RedirectResponse
    {
        $this->autorizar($request, $conversacion);
        $conversacion->delete();

        return redirect()->route('asistente.tienda')->with('success', 'Conversación eliminada.');
    }

    private function autorizar(Request $request, ConversacionIa $conversacion): void
    {
        abort_unless($conversacion->empresa_id === $request->user()->empresa_id && $conversacion->canal === 'tienda', 404);
    }

    private function conversacion(ConversacionIa $c): array
    {
        return [
            'id' => $c->id,
            'titulo' => $c->titulo,
            'modo' => $c->modo,
            'atendida_por' => $c->atendidaPor?->nombre_completo,
            'visitante' => substr((string) $c->visitante, 0, 6),
            'creado_en' => $c->creado_en?->toDateTimeString(),
            'mensajes' => $c->mensajes->map(fn (MensajeIa $m) => $this->mensaje($m))->values(),
        ];
    }

    private function mensaje(MensajeIa $m): array
    {
        $productos = $m->productos ?? [];

        return [
            'id' => $m->id,
            'rol' => $m->rol,
            'contenido' => $m->contenido,
            'modelo' => $m->modelo,
            'productos' => $productos === [] ? [] : Producto::query()->where('empresa_id', $m->empresa_id)->whereIn('codigo_interno', $productos)->get(['codigo_interno', 'nombre'])
                ->map(fn ($p) => ['codigo' => $p->codigo_interno, 'nombre' => $p->nombre])->values()->all(),
            'creado_en' => $m->creado_en?->toDateTimeString(),
        ];
    }
}
