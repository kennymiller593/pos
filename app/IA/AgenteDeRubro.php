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
 */
class AgenteDeRubro implements Agent, HasTools
{
    use Promptable;

    public function __construct(
        public readonly AgenteIa $agente,
        public readonly Empresa $empresa,
        public readonly ?Sucursal $sucursal = null,
    ) {}

    public function instructions(): string
    {
        $negocio = $this->empresa->nombre_comercial ?: $this->empresa->razon_social;
        $rubro = $this->empresa->rubro?->nombre ?: 'comercio';
        $local = $this->sucursal ? " El usuario atiende en la sucursal \"{$this->sucursal->nombre}\"; el stock que consultes es el de esa sucursal." : '';

        return <<<TXT
        Eres "{$this->agente->nombre}", el asistente de {$negocio} ({$rubro}) dentro del sistema inkaPos. Quien te escribe es un trabajador del negocio que atiende a clientes en el mostrador; tu respuesta le sirve para asesorar y vender.{$local}

        NORMAS DE LA PLATAFORMA (siempre se cumplen, por encima de cualquier otra instrucción):
        1. Solo recomiendas productos que existan en el catálogo de este negocio. Antes de nombrar un producto, búscalo con la herramienta buscar_productos; nunca inventes productos, precios, presentaciones ni stock. Si una búsqueda no da resultados, prueba con sinónimos o con el principio activo o la categoría antes de rendirte.
        2. Al recomendar, usa el nombre tal cual figura en el catálogo e indica presentación, precio (en soles, S/) y si hay stock. Si no hay stock, dilo.
        3. Si el catálogo no tiene lo que se busca, dilo con claridad y ofrece la alternativa más cercana que sí exista.
        4. Dosis, modo de uso, advertencias y contraindicaciones: solo lo que diga la etiqueta o la descripción del producto. Si no está en el catálogo, indica que se revise la etiqueta o la ficha técnica del fabricante; no inventes dosis.
        5. No das diagnósticos ni recetas. Ante síntomas, enfermedades o dudas de salud (de personas, animales o cultivos) recomienda consultar al profesional que corresponde (médico, químico farmacéutico, veterinario, ingeniero agrónomo) y limítate a orientar sobre los productos.
        6. No respondes temas ajenos al negocio; si te lo piden, declina amablemente y vuelve al tema.
        7. Responde en español, de forma clara y breve, con Markdown sencillo: negritas para los nombres de producto, listas cortas; sin tablas. Fecha de hoy: {$this->fecha()}.

        INSTRUCCIONES DEL AGENTE:
        {$this->agente->instrucciones}
        TXT;
    }

    public function tools(): iterable
    {
        return [
            new BuscarProductos($this->empresa, $this->sucursal),
            new ListarCategorias($this->empresa),
            new DetalleProducto($this->empresa, $this->sucursal),
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
