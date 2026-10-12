<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Models\ConversacionIa;
use App\Models\Empresa;
use App\Models\MensajeIa;
use App\Services\CatalogoTiendaService;
use App\Services\IA\AsistenteIaService;
use App\Support\Tienda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El chatbot de la tienda en línea: un cliente anónimo conversa con el agente de la empresa.
 * Sin sesión ni CSRF (la tienda no usa cookies); el visitante se identifica con un id que guarda su navegador.
 * Si el negocio tomó la conversación, el bot calla y lo que escribe el cliente queda para que lo responda una persona.
 */
class AsistenteTiendaController extends Controller
{
    public function __construct(
        private readonly AsistenteIaService $asistente,
        private readonly CatalogoTiendaService $catalogo,
    ) {}

    public function enviar(Request $request): JsonResponse
    {
        /** @var Empresa $empresa */
        $empresa = $request->attributes->get('tienda');
        $config = Tienda::config($empresa);

        $agente = $this->asistente->agenteDeTienda($empresa, $config);
        if (! $agente) {
            return response()->json(['mensaje' => 'La tienda no tiene asistente activo.'], 404);
        }

        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:'.AsistenteIaService::MAX_CARACTERES],
            'visitante' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'conversacion_id' => ['nullable', 'string', 'max:40'],
        ]);

        $conversacion = $this->conversacionDe($empresa, $datos['visitante'], $datos['conversacion_id'] ?? null)
            ?? ConversacionIa::create(['empresa_id' => $empresa->id, 'agente_id' => $agente->id, 'canal' => 'tienda', 'visitante' => $datos['visitante']]);

        // una persona del negocio atiende: se guarda la pregunta y se le avisa al cliente que le responderán
        if ($conversacion->modo === 'humano') {
            MensajeIa::create(['conversacion_id' => $conversacion->id, 'empresa_id' => $empresa->id, 'rol' => 'user', 'contenido' => trim($datos['texto'])]);
            $conversacion->touch();

            return response()->json(['conversacion_id' => $conversacion->id, 'modo' => 'humano', 'mensaje' => null]);
        }

        $persona = [
            'nombre' => $config['chatbot_nombre'],
            'mostrar_precios' => (bool) $config['mostrar_precios'],
            'mostrar_stock' => (bool) $config['mostrar_stock'],
            'whatsapp' => filled($config['whatsapp']),
        ];

        try {
            $respuesta = $this->asistente->atender($conversacion, $empresa, null, $datos['texto'], 'tienda', $persona);
        } catch (ErrorDeNegocio $e) {
            if ($conversacion->mensajes()->doesntExist()) {
                $conversacion->delete();
            }

            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json([
            'conversacion_id' => $conversacion->id,
            'modo' => 'bot',
            'mensaje' => $this->mensaje($respuesta, $empresa, $config),
        ]);
    }

    /** Lo nuevo de la conversación (para cuando atiende una persona y el cliente espera su respuesta). */
    public function mensajes(Request $request): JsonResponse
    {
        /** @var Empresa $empresa */
        $empresa = $request->attributes->get('tienda');
        $datos = $request->validate([
            'visitante' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'conversacion_id' => ['required', 'string', 'max:40'],
            'desde' => ['nullable', 'uuid'],
        ]);

        $conversacion = $this->conversacionDe($empresa, $datos['visitante'], $datos['conversacion_id']);
        if (! $conversacion) {
            return response()->json(['mensaje' => 'La conversación no existe.'], 404);
        }
        $config = Tienda::config($empresa);

        return response()->json([
            'modo' => $conversacion->modo,
            'negocio' => $empresa->nombre_comercial ?: $empresa->razon_social,
            'mensajes' => $conversacion->mensajes()
                ->whereIn('rol', ['assistant', 'humano'])
                ->when($datos['desde'] ?? null, fn ($q, $desde) => $q->where('id', '>', $desde))
                ->get()
                ->map(fn (MensajeIa $m) => $this->mensaje($m, $empresa, $config))
                ->values(),
        ]);
    }

    private function conversacionDe(Empresa $empresa, string $visitante, ?string $id): ?ConversacionIa
    {
        // se retoma la conversación solo si es de este visitante y de esta tienda; si no, empieza otra
        return filled($id)
            ? ConversacionIa::where('id', $id)->where('empresa_id', $empresa->id)->where('canal', 'tienda')->where('visitante', $visitante)->first()
            : null;
    }

    private function mensaje(MensajeIa $m, Empresa $empresa, array $config): array
    {
        return [
            'id' => $m->id,
            'rol' => $m->rol,
            'contenido' => $m->contenido,
            'productos' => $this->tarjetas($empresa, $config, $m->productos ?? []),
            'creado_en' => $m->creado_en?->toDateTimeString(),
        ];
    }

    /** Las tarjetas de los productos recomendados, con los mismos datos que usa la tienda. */
    private function tarjetas(Empresa $empresa, array $config, array $codigos): array
    {
        if ($codigos === []) {
            return [];
        }

        return $this->catalogo->productos($empresa)->whereIn('productos.codigo_interno', $codigos)->get()
            ->sortBy(fn ($p) => array_search($p->codigo_interno, $codigos, true))
            ->map(fn ($p) => $this->catalogo->tarjeta($p, $config))
            ->map(fn (array $t) => [
                'nombre' => $t['nombre'],
                'categoria' => $t['categoria'],
                'imagen' => $t['imagen'],
                'precio' => $t['precio'],
                'presentacion' => $t['presentacion'],
                'disponible' => $t['disponible'],
                'url' => $t['url'],
                'pedido' => $t['pedido'],
            ])
            ->values()
            ->all();
    }
}
