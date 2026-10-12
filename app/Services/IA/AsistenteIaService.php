<?php

namespace App\Services\IA;

use App\Exceptions\ErrorDeNegocio;
use App\IA\AgenteDeRubro;
use App\Models\AgenteEmpresa;
use App\Models\AgenteIa;
use App\Models\ConversacionIa;
use App\Models\Empresa;
use App\Models\MensajeIa;
use App\Models\ModeloIa;
use App\Models\ProveedorIa;
use App\Models\Sucursal;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * Atiende las consultas al asistente: comprueba la activación y el tope mensual, recorre la cadena de
 * modelos del agente (si uno falla pasa al siguiente) y guarda cada respuesta con su proveedor, tokens y costo.
 * Sirve tanto al trabajador dentro de la app como al cliente en la tienda en línea (modo "tienda").
 */
class AsistenteIaService
{
    /** Segundos máximos por proveedor: si no contesta, se pasa al siguiente. */
    public const TIMEOUT_POR_PROVEEDOR = 25;

    /** Mensajes anteriores que se le recuerdan al modelo. */
    private const HISTORIAL = 20;

    /** Largo máximo de una pregunta. */
    public const MAX_CARACTERES = 2000;

    /** Tarjetas de producto que se muestran como máximo por respuesta. */
    public const MAX_PRODUCTOS = 4;

    public function __construct(private readonly ProveedorIaService $proveedores) {}

    /** Agentes que la empresa tiene activos (y que la plataforma no desactivó). */
    public function agentesDe(Empresa $empresa)
    {
        return AgenteIa::query()
            ->where('activo', true)
            ->whereHas('activaciones', fn ($q) => $q->where('empresa_id', $empresa->id)->where('activo', true))
            ->orderBy('nombre')
            ->get();
    }

    public function tieneAgentes(Empresa $empresa): bool
    {
        return AgenteEmpresa::query()->where('empresa_id', $empresa->id)->where('activo', true)
            ->whereHas('agente', fn ($q) => $q->where('activo', true))->exists();
    }

    /** El agente que atiende la tienda en línea, si el dueño encendió el chatbot y el agente sigue activo. */
    public function agenteDeTienda(Empresa $empresa, array $config): ?AgenteIa
    {
        if (! ($config['chatbot_activo'] ?? false)) {
            return null;
        }
        $agentes = $this->agentesDe($empresa);

        return $agentes->firstWhere('id', $config['chatbot_agente'] ?? null) ?? ($agentes->count() === 1 ? $agentes->first() : null);
    }

    /** Consultas respondidas este mes (lo que descuenta del tope), en la app y en la tienda. */
    public function consultasDelMes(Empresa $empresa, ?AgenteIa $agente = null): int
    {
        return MensajeIa::query()
            ->where('empresa_id', $empresa->id)
            ->where('rol', 'assistant')
            ->where('creado_en', '>=', now()->startOfMonth())
            ->when($agente, fn ($q) => $q->whereHas('conversacion', fn ($c) => $c->where('agente_id', $agente->id)))
            ->count();
    }

    /** Costo acumulado del mes en dólares. */
    public function costoDelMes(Empresa $empresa): float
    {
        return (float) MensajeIa::query()
            ->where('empresa_id', $empresa->id)
            ->where('rol', 'assistant')
            ->where('creado_en', '>=', now()->startOfMonth())
            ->sum('costo_usd');
    }

    public function limiteDe(Empresa $empresa, AgenteIa $agente): int
    {
        $activacion = AgenteEmpresa::where('empresa_id', $empresa->id)->where('agente_id', $agente->id)->first();

        return $activacion?->limite_mensual ?: $agente->limite_mensual;
    }

    /**
     * Responde a un trabajador dentro de la app y guarda ambos mensajes.
     *
     * @throws ErrorDeNegocio
     */
    public function responder(ConversacionIa $conversacion, Usuario $usuario, string $texto): MensajeIa
    {
        return $this->atender($conversacion, $usuario->empresa, $usuario->sucursal, $texto);
    }

    /**
     * Responde dentro de una conversación (de la app o de la tienda) y guarda ambos mensajes.
     *
     * @param  array{nombre?: ?string, mostrar_precios?: bool, mostrar_stock?: bool, whatsapp?: bool}  $persona  solo en modo tienda
     *
     * @throws ErrorDeNegocio
     */
    public function atender(ConversacionIa $conversacion, Empresa $empresa, ?Sucursal $sucursal, string $texto, string $modo = 'app', array $persona = []): MensajeIa
    {
        $agente = $conversacion->agente;
        $texto = trim($texto);

        if ($texto === '' || mb_strlen($texto) > self::MAX_CARACTERES) {
            throw new ErrorDeNegocio('Escribe una consulta de hasta '.self::MAX_CARACTERES.' caracteres.');
        }
        if (! $agente->activo || ! AgenteEmpresa::where('empresa_id', $empresa->id)->where('agente_id', $agente->id)->where('activo', true)->exists()) {
            throw new ErrorDeNegocio($modo === 'tienda' ? 'El asistente no está disponible por ahora.' : 'Este agente ya no está activo para tu empresa.');
        }

        $limite = $this->limiteDe($empresa, $agente);
        if ($this->consultasDelMes($empresa, $agente) >= $limite) {
            throw new ErrorDeNegocio($modo === 'tienda'
                ? 'El asistente alcanzó su límite de consultas de este mes. Escríbenos por WhatsApp y te atendemos.'
                : "Se alcanzó el tope de {$limite} consultas de este mes para \"{$agente->nombre}\". Vuelve a intentarlo el próximo mes o pide a la plataforma ampliarlo.");
        }

        $historial = $conversacion->mensajes()->latest('creado_en')->limit(self::HISTORIAL)->get()->reverse()->values()
            ->map(fn (MensajeIa $m) => $m->rol === 'user' ? new UserMessage($m->contenido) : new AssistantMessage($m->contenido))
            ->all();

        $resultado = $this->consultar($agente, $empresa, $sucursal, $texto, $historial, $modo, $persona);

        return DB::transaction(function () use ($conversacion, $empresa, $texto, $resultado) {
            MensajeIa::create(['conversacion_id' => $conversacion->id, 'empresa_id' => $empresa->id, 'rol' => 'user', 'contenido' => $texto]);
            $respuesta = MensajeIa::create(['conversacion_id' => $conversacion->id, 'empresa_id' => $empresa->id, 'rol' => 'assistant'] + $resultado);

            if (blank($conversacion->titulo) || $conversacion->titulo === 'Nueva conversación') {
                $conversacion->titulo = Str::limit(preg_replace('/\s+/', ' ', $texto), 80, '…');
            }
            $conversacion->touch();

            return $respuesta;
        });
    }

    /**
     * Recorre la cadena de modelos del agente hasta que uno responda. Devuelve los datos listos para guardar.
     *
     * @param  list<UserMessage|AssistantMessage>  $historial
     * @return array{contenido: string, productos: list<string>, proveedor_codigo: string, modelo: string, tokens_entrada: int, tokens_salida: int, costo_usd: ?float, duracion_ms: int, herramientas: array, intentos: array}
     *
     * @throws ErrorDeNegocio
     */
    public function consultar(AgenteIa $agente, Empresa $empresa, ?Sucursal $sucursal, string $texto, array $historial = [], string $modo = 'app', array $persona = []): array
    {
        $eslabones = $agente->eslabones();
        if ($eslabones === []) {
            throw new ErrorDeNegocio('El agente no tiene modelos configurados.');
        }

        $proveedores = ProveedorIa::whereIn('codigo', array_column($eslabones, 'proveedor'))->get()->keyBy('codigo');
        $intentos = [];
        $inicioTotal = hrtime(true);

        foreach ($eslabones as $eslabon) {
            $proveedor = $proveedores->get($eslabon['proveedor']);
            if (! $proveedor || ! $proveedor->disponible()) {
                $intentos[] = ['proveedor' => $eslabon['proveedor'], 'modelo' => $eslabon['modelo'], 'error' => ! $proveedor ? 'no existe' : ($proveedor->enPausa() ? 'en pausa por fallos' : 'sin clave o desactivado')];

                continue;
            }

            $inicio = hrtime(true);
            try {
                $respuesta = (new AgenteDeRubro($agente, $empresa, $sucursal, $modo, $persona))
                    ->withMessages($historial)
                    ->prompt($texto, provider: $this->proveedores->construir($proveedor), model: $eslabon['modelo'], timeout: self::TIMEOUT_POR_PROVEEDOR);
            } catch (Throwable $e) {
                $this->proveedores->registrarFallo($proveedor, $e);
                $intentos[] = ['proveedor' => $proveedor->codigo, 'modelo' => $eslabon['modelo'], 'error' => ProveedorIaService::resumirError($e), 'ms' => (int) ((hrtime(true) - $inicio) / 1_000_000)];

                continue;
            }

            $this->proveedores->registrarExito($proveedor);

            return $this->armar($respuesta, $proveedor, $eslabon['modelo'], (int) ((hrtime(true) - $inicioTotal) / 1_000_000), $intentos);
        }

        report(new \RuntimeException('Asistente IA sin respuesta: '.json_encode($intentos, JSON_UNESCAPED_UNICODE)));

        throw new ErrorDeNegocio('El asistente no está disponible en este momento. Inténtalo de nuevo en unos minutos.');
    }

    private function armar(AgentResponse $respuesta, ProveedorIa $proveedor, string $modelo, int $ms, array $intentos): array
    {
        $modeloUsado = $respuesta->meta->model ?: $modelo;
        $precio = ModeloIa::where('proveedor_codigo', $proveedor->codigo)->whereIn('modelo', array_unique([$modelo, $modeloUsado]))->first();
        $entrada = (int) $respuesta->usage->inputTokens;
        $salida = (int) $respuesta->usage->outputTokens;

        [$texto, $productos] = self::separarProductos($respuesta->text);
        if ($texto === '') {
            $texto = 'No pude preparar una respuesta. ¿Puedes reformular la consulta?';
        }

        return [
            'contenido' => $texto,
            'productos' => $productos,
            'proveedor_codigo' => $proveedor->codigo,
            'modelo' => $modelo,
            'tokens_entrada' => $entrada,
            'tokens_salida' => $salida,
            'costo_usd' => $precio?->costo($entrada, $salida),
            'duracion_ms' => $ms,
            'herramientas' => $respuesta->toolCalls->map(fn ($t) => ['nombre' => $t->name, 'argumentos' => $t->arguments])->values()->all(),
            'intentos' => $intentos,
        ];
    }

    /**
     * Saca la línea final "PRODUCTOS: cod1, cod2" del texto y devuelve [texto limpio, códigos].
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function separarProductos(string $texto): array
    {
        $texto = trim($texto);
        $marca = preg_quote(AgenteDeRubro::MARCA_PRODUCTOS, '/');
        if (! preg_match('/(?:^|\n)[\s*_]*'.$marca.'[\s*_]*([^\n]*)$/iu', $texto, $m, PREG_OFFSET_CAPTURE)) {
            return [$texto, []];
        }

        $codigos = collect(preg_split('/[\s,;]+/', trim(str_replace(['*', '_', '`'], '', $m[1][0]))))
            ->map(fn ($c) => trim($c, ' .:-'))
            ->filter()
            ->unique()
            ->take(self::MAX_PRODUCTOS)
            ->values()
            ->all();

        return [trim(substr($texto, 0, $m[0][1])), $codigos];
    }
}
