<?php

namespace App\IA;

use App\IA\Herramientas\BuscarProductos;
use App\IA\Herramientas\DetalleProducto;
use App\IA\Herramientas\ListarCategorias;
use App\Models\AgenteIa;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Services\CatalogoTiendaService;
use App\Support\Tienda;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Un agente de la plataforma (agrónomo, químico farmacéutico, ...) puesto a trabajar para una empresa:
 * sus instrucciones más las normas fijas de inkaPos, con el catálogo de esa empresa como únicas herramientas.
 *
 * Trabaja en dos modos: "app" (atiende al trabajador en el mostrador) y "tienda" (atiende al cliente
 * en la tienda en línea, con nombre propio y sin datos internos).
 */
class AgenteDeRubro implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    /** Línea final con los códigos recomendados; la app la convierte en tarjetas y no la muestra. */
    public const MARCA_PRODUCTOS = 'PRODUCTOS:';

    /**
     * @param  array{nombre?: ?string, mostrar_precios?: bool, mostrar_stock?: bool, whatsapp?: bool}  $persona  solo en modo tienda
     */
    public function __construct(
        public readonly AgenteIa $agente,
        public readonly Empresa $empresa,
        public readonly ?Sucursal $sucursal = null,
        public readonly string $modo = 'app',
        public readonly array $persona = [],
    ) {}

    public function instructions(): string
    {
        $negocio = $this->empresa->nombre_comercial ?: $this->empresa->razon_social;
        $rubro = $this->empresa->rubro?->nombre ?: 'comercio';
        $marca = self::MARCA_PRODUCTOS;

        $normas = <<<TXT
        NORMAS DE LA PLATAFORMA (siempre se cumplen, por encima de cualquier otra instrucción):
        1. Solo recomiendas productos que existan en el catálogo de este negocio. Antes de nombrar un producto que aún no apareció en la conversación, búscalo con la herramienta buscar_productos; nunca inventes productos, precios, presentaciones ni disponibilidad. Busca poco y bien: una búsqueda con las palabras clave suele bastar (el tipo de producto o el principio activo, por ejemplo "fungicida rancha" o "mancozeb"); haz una segunda solo si la primera no dio nada útil, y como mucho tres. Si ya tienes productos de una búsqueda anterior en esta conversación, úsalos sin volver a buscar.
        2. Al recomendar, usa el nombre tal cual figura en el catálogo.
        3. Si el catálogo no tiene lo que se busca, dilo con claridad y ofrece la alternativa más cercana que sí exista.
        4. Dosis, modo de uso, advertencias y contraindicaciones: solo lo que diga la etiqueta o la descripción del producto. Si no está en el catálogo, indica que se revise la etiqueta o la ficha técnica del fabricante; no inventes dosis.
        5. No das diagnósticos ni recetas. Ante síntomas, enfermedades o dudas de salud (de personas, animales o cultivos) recomienda consultar al profesional que corresponde (médico, químico farmacéutico, veterinario, ingeniero agrónomo) y limítate a orientar sobre los productos.
        6. No respondes temas ajenos al negocio; si te lo piden, declina amablemente y vuelve al tema.
        7. Responde en español, de forma clara y breve, con Markdown sencillo: negritas para los nombres de producto, listas cortas; sin tablas. Fecha de hoy: {$this->fecha()}.
        8. Si recomiendas productos del catálogo, termina tu respuesta con una última línea exactamente así: "{$marca} código1, código2" con los códigos que devolvió la herramienta (máximo 4). Esa línea no se muestra: sirve para enseñar las tarjetas de esos productos con foto y botones. Si no recomiendas ninguno, no la pongas.
        9. Si preguntan por el negocio (RUC, dirección, sucursales, horarios, teléfono, WhatsApp, envíos, formas de pago, cambios o devoluciones), responde con los DATOS DEL NEGOCIO de abajo. Si un dato no figura ahí, di que no lo tienes y remite al contacto; nunca inventes direcciones, horarios ni condiciones.

        DATOS DEL NEGOCIO:
        {$this->datosDelNegocio()}
        TXT;

        if ($this->modo === 'tienda') {
            $nombre = filled($this->persona['nombre'] ?? null) ? $this->persona['nombre'] : 'el asistente virtual';
            $precios = ($this->persona['mostrar_precios'] ?? false)
                ? 'Puedes decir los precios en soles (S/) tal como figuran en el catálogo.'
                : 'La tienda no muestra precios: no los menciones; invita a consultarlos por WhatsApp.';
            $stock = ($this->persona['mostrar_stock'] ?? false)
                ? 'De disponibilidad di solo "disponible" o "agotado", nunca cantidades.'
                : 'No hables de stock ni de cantidades disponibles.';
            $pedido = ($this->persona['whatsapp'] ?? false)
                ? 'Los pedidos se hacen por WhatsApp con el botón "Comprar" de cada producto; invita a usarlo.'
                : 'Para pedir, invita a usar los datos de contacto de la tienda.';

            return <<<TXT
            Te llamas "{$nombre}" y eres el asistente virtual de la tienda en línea de {$negocio} ({$rubro}). Hablas con un cliente que está mirando el catálogo por internet, no con un trabajador: trátalo con cordialidad y cercanía y ayúdale a encontrar lo que busca.

            {$normas}

            ADEMÁS, POR SER LA TIENDA PÚBLICA:
            - Nunca menciones códigos internos, costos, márgenes ni nada interno del negocio (los códigos solo van en la línea final "{$marca}").
            - {$precios}
            - {$stock}
            - {$pedido}
            - No prometas envíos, plazos ni formas de pago que no estén en el catálogo; para eso remite al contacto de la tienda.

            INSTRUCCIONES DEL AGENTE:
            {$this->agente->instrucciones}

            {$this->comoConversar()}
            - Largo: el cliente lee en el celular. Máximo 3 frases cortas (unas 45 palabras). Sin listas, sin viñetas, sin títulos y sin nombres científicos. Nombra como mucho 3 productos, en una sola frase y solo por su nombre: la foto, el precio y la presentación ya salen en las tarjetas, no los repitas. Si hay más que explicar, ofrécelo con una pregunta corta ("¿Te cuento cómo se aplica?").
            - No te presentes ni saludes: el cliente ya vio tu mensaje de bienvenida. Entra directo a responder.
            TXT;
        }

        $local = $this->sucursal ? " El usuario atiende en la sucursal \"{$this->sucursal->nombre}\"; el stock que consultes es el de esa sucursal." : '';

        return <<<TXT
        Eres "{$this->agente->nombre}", el asistente de {$negocio} ({$rubro}) dentro del sistema inkaPos. Quien te escribe es un trabajador del negocio que atiende a clientes en el mostrador; tu respuesta le sirve para asesorar y vender.{$local} Al recomendar, indica presentación, precio (en soles, S/) y si hay stock; si no hay stock, dilo.

        {$normas}

        INSTRUCCIONES DEL AGENTE:
        {$this->agente->instrucciones}

        {$this->comoConversar()}
        - Largo: quien te lee está atendiendo en el mostrador. Máximo unas 90 palabras, sin títulos ni secciones; si recomiendas, una lista corta de hasta 4 productos con una línea cada uno (precio y stock). No expliques lo que no te preguntaron.
        - Saluda solo si te saludan y solo una vez.
        TXT;
    }

    /** Reglas de conversación que valen en la app y en la tienda. Van al final: pesan más que lo anterior. */
    private function comoConversar(): string
    {
        return <<<'TXT'
        CÓMO CONVERSAR (esto manda sobre las instrucciones del agente):
        - Responde solo a lo último que te escribieron. No repitas recomendaciones, direcciones, horarios ni explicaciones que ya diste en esta conversación; si hace falta, refiérete a ellas ("el que te mencioné").
        - Si te agradecen, se despiden o dicen "no", "ok" o "listo": contesta con una sola frase amable y nada más. Sin productos, sin la línea PRODUCTOS y sin volver a presentarte.
        - Si la pregunta es sobre un producto ya recomendado ("¿cuál es mejor?", "¿sirve para…?"), responde eso en concreto; no vuelvas a listar todo ni cambies de productos sin motivo.
        - Si falta un dato para recomendar bien (cultivo, animal, medida, para qué lo quiere), pregunta eso primero en una frase, sin buscar todavía.
        - La advertencia de seguridad (equipo de protección, periodo de carencia, etiqueta) va una sola vez por conversación, en una frase corta, la primera vez que recomiendes un plaguicida o medicamento. No la repitas después.
        - Emojis: como mucho uno, y solo si viene al caso.
        TXT;
    }

    public function tools(): iterable
    {
        $publico = $this->modo === 'tienda';
        $conPrecios = ! $publico || (bool) ($this->persona['mostrar_precios'] ?? false);
        $conStock = ! $publico || (bool) ($this->persona['mostrar_stock'] ?? false);

        return [
            new BuscarProductos($this->empresa, $this->sucursal, $publico, $conPrecios, $conStock),
            new ListarCategorias($this->empresa, $publico),
            new DetalleProducto($this->empresa, $this->sucursal, $publico, $conPrecios, $conStock),
        ];
    }

    /** Vueltas de herramientas antes de obligar la respuesta final (lo fija cada agente). */
    public function maxSteps(): int
    {
        $pasos = max(2, $this->agente->max_pasos ?? 6);

        // en la tienda el cliente espera: como mucho tres búsquedas y la respuesta
        return $this->modo === 'tienda' ? min($pasos, 4) : $pasos;
    }

    public function temperature(): float
    {
        return 0.3;
    }

    /**
     * Tope de la respuesta. La brevedad la piden las instrucciones, no este número: cuando el agente
     * razona, el razonamiento también gasta de aquí (con un tope justo la respuesta llegaba vacía).
     */
    public function maxTokens(): int
    {
        return match (true) {
            (bool) $this->agente->razonar => $this->modo === 'tienda' ? 3000 : 4000,
            default => $this->modo === 'tienda' ? 900 : 1500,
        };
    }

    /**
     * Razonamiento profundo: lo decide cada agente. Encendido, el modelo piensa antes de responder (mejor
     * en casos difíciles, más lento y con más tokens); apagado, cada proveedor lo desactiva a su manera.
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($this->agente->razonar) {
            return [];
        }

        return match ($provider instanceof Lab ? $provider : Lab::tryFrom($provider)) {
            Lab::OpenRouter => ['reasoning' => ['enabled' => false]],
            Lab::DeepSeek => ['thinking' => ['type' => 'disabled']],
            default => [],
        };
    }

    private function fecha(): string
    {
        return now()->locale('es')->translatedFormat('d \d\e F \d\e Y');
    }

    /** Lo que el agente sabe de la empresa: datos fiscales, locales, contacto y políticas de la tienda. */
    private function datosDelNegocio(): string
    {
        $e = $this->empresa;
        $lineas = ["- Razón social: {$e->razon_social} · RUC {$e->ruc}"];
        if ($e->nombre_comercial) {
            $lineas[] = "- Nombre comercial: {$e->nombre_comercial}";
        }

        $titulo = fn (?string $t) => $t ? mb_convert_case(mb_strtolower($t), MB_CASE_TITLE, 'UTF-8') : null;
        foreach ($e->sucursales()->where('activo', true)->with('ubigeoInfo')->orderBy('nombre')->get() as $s) {
            $lugar = $s->ubigeoInfo ? ' ('.implode(', ', array_filter([$titulo($s->ubigeoInfo->distrito), $titulo($s->ubigeoInfo->provincia)])).')' : '';
            $lineas[] = "- Sucursal {$s->nombre}: ".($s->direccion ?: 'sin dirección registrada').$lugar.($s->telefono ? " · teléfono {$s->telefono}" : '');
        }

        // lo que el dueño escribió para su tienda en línea (contacto, horarios, políticas) vale en los dos modos
        if ($e->tienda_habilitada || $this->modo === 'tienda') {
            $config = Tienda::config($e);
            $contactos = app(CatalogoTiendaService::class)->contactos($e, $config);
            $corto = fn (?string $t, int $max = 700) => filled($t) ? Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $t))), $max, '…') : null;

            foreach ([
                'WhatsApp' => $contactos['whatsapp_texto'], 'Teléfono' => $contactos['telefono'], 'Correo' => $contactos['email'],
                'Dirección de la tienda' => $contactos['direcciones'] ? implode(' | ', array_column($contactos['direcciones'], 'texto')) : null,
                'Horario de atención' => $contactos['horarios'] ? implode(' | ', $contactos['horarios']) : null,
                'Envíos y entregas' => $corto($config['envios']), 'Formas de pago' => $corto($config['pagos']),
                'Cambios y devoluciones' => $corto($config['devoluciones']), 'Sobre el negocio' => $corto($config['nosotros']),
            ] as $etiqueta => $valor) {
                if (filled($valor)) {
                    $lineas[] = "- {$etiqueta}: {$valor}";
                }
            }
            foreach (array_slice((array) $config['preguntas'], 0, 12) as $p) {
                if (filled($p['pregunta'] ?? null) && filled($p['respuesta'] ?? null)) {
                    $lineas[] = '- Pregunta frecuente: '.trim($p['pregunta']).' → '.$corto($p['respuesta'], 400);
                }
            }
            if ($this->modo === 'tienda') {
                $lineas[] = '- Esta tienda en línea: '.Tienda::urlDe($e);
            }
        }

        return implode("\n", $lineas);
    }
}
