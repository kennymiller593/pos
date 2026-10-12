<?php

namespace App\IA;

use App\IA\Herramientas\BuscarProductos;
use App\IA\Herramientas\DetalleProducto;
use App\IA\Herramientas\ListarCategorias;
use App\Models\AgenteIa;
use App\Models\Empresa;
use App\Models\Sucursal;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * Un agente de la plataforma (agrónomo, químico farmacéutico, ...) puesto a trabajar para una empresa:
 * sus instrucciones más las normas fijas de inkaPos, con el catálogo de esa empresa como únicas herramientas.
 *
 * Trabaja en dos modos: "app" (atiende al trabajador en el mostrador) y "tienda" (atiende al cliente
 * en la tienda en línea, con nombre propio y sin datos internos).
 */
class AgenteDeRubro implements Agent, HasTools
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
        1. Solo recomiendas productos que existan en el catálogo de este negocio. Antes de nombrar un producto, búscalo con la herramienta buscar_productos; nunca inventes productos, precios, presentaciones ni disponibilidad. Si una búsqueda no da resultados, prueba con sinónimos o con el principio activo o la categoría antes de rendirte.
        2. Al recomendar, usa el nombre tal cual figura en el catálogo.
        3. Si el catálogo no tiene lo que se busca, dilo con claridad y ofrece la alternativa más cercana que sí exista.
        4. Dosis, modo de uso, advertencias y contraindicaciones: solo lo que diga la etiqueta o la descripción del producto. Si no está en el catálogo, indica que se revise la etiqueta o la ficha técnica del fabricante; no inventes dosis.
        5. No das diagnósticos ni recetas. Ante síntomas, enfermedades o dudas de salud (de personas, animales o cultivos) recomienda consultar al profesional que corresponde (médico, químico farmacéutico, veterinario, ingeniero agrónomo) y limítate a orientar sobre los productos.
        6. No respondes temas ajenos al negocio; si te lo piden, declina amablemente y vuelve al tema.
        7. Responde en español, de forma clara y breve, con Markdown sencillo: negritas para los nombres de producto, listas cortas; sin tablas. Fecha de hoy: {$this->fecha()}.
        8. Si recomiendas productos del catálogo, termina tu respuesta con una última línea exactamente así: "{$marca} código1, código2" con los códigos que devolvió la herramienta (máximo 4). Esa línea no se muestra: sirve para enseñar las tarjetas de esos productos con foto y botones. Si no recomiendas ninguno, no la pongas.
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
            Te llamas "{$nombre}" y eres el asistente virtual de la tienda en línea de {$negocio} ({$rubro}). Hablas con un cliente que está mirando el catálogo por internet, no con un trabajador: trátalo con cordialidad y cercanía, preséntate solo la primera vez y ayúdale a encontrar lo que busca.

            {$normas}

            ADEMÁS, POR SER LA TIENDA PÚBLICA:
            - Nunca menciones códigos internos, costos, márgenes ni nada interno del negocio (los códigos solo van en la línea final "{$marca}").
            - {$precios}
            - {$stock}
            - {$pedido}
            - No prometas envíos, plazos ni formas de pago que no estén en el catálogo; para eso remite al contacto de la tienda.

            INSTRUCCIONES DEL AGENTE:
            {$this->agente->instrucciones}
            TXT;
        }

        $local = $this->sucursal ? " El usuario atiende en la sucursal \"{$this->sucursal->nombre}\"; el stock que consultes es el de esa sucursal." : '';

        return <<<TXT
        Eres "{$this->agente->nombre}", el asistente de {$negocio} ({$rubro}) dentro del sistema inkaPos. Quien te escribe es un trabajador del negocio que atiende a clientes en el mostrador; tu respuesta le sirve para asesorar y vender.{$local} Al recomendar, indica presentación, precio (en soles, S/) y si hay stock; si no hay stock, dilo.

        {$normas}

        INSTRUCCIONES DEL AGENTE:
        {$this->agente->instrucciones}
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
        return max(2, $this->agente->max_pasos ?? 6);
    }

    public function temperature(): float
    {
        return 0.3;
    }

    public function maxTokens(): int
    {
        return 1200;
    }

    private function fecha(): string
    {
        return now()->locale('es')->translatedFormat('d \d\e F \d\e Y');
    }
}
