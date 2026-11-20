<?php

namespace App\IA;

/**
 * Los modelos que razonan (OpenAI gpt-5, gpt-6, o1, o3...) rechazan el parámetro "temperature" con un 400
 * ("Unsupported parameter: 'temperature' is not supported with this model"). El agente sabe con qué modelo
 * se le va a llamar (paraModelo) y, si es uno de esos, no manda temperatura; a los demás sí.
 */
trait TemperaturaSegunModelo
{
    private ?string $modeloDeLaLlamada = null;

    /** El modelo con que se va a llamar (se pasa antes de prompt()). */
    public function paraModelo(string $modelo): static
    {
        $this->modeloDeLaLlamada = $modelo;

        return $this;
    }

    /** La temperatura deseada, o null si el modelo no la admite (así el paquete no la envía). */
    protected function temperaturaPermitida(float $temperatura): ?float
    {
        return self::admiteTemperatura($this->modeloDeLaLlamada) ? $temperatura : null;
    }

    /** El modelo de esta llamada razona (OpenAI gpt-5/6, o-series). */
    protected function modeloRazona(): bool
    {
        return ! self::admiteTemperatura($this->modeloDeLaLlamada);
    }

    /**
     * Opciones para OpenAI cuando el modelo razona: que piense poco (más rápido y barato; para estas tareas basta).
     * A un modelo que no razona no se le manda: lo rechazaría.
     */
    protected function razonamientoBajo(\Laravel\Ai\Enums\Lab|string $proveedor): array
    {
        $lab = $proveedor instanceof \Laravel\Ai\Enums\Lab ? $proveedor : \Laravel\Ai\Enums\Lab::tryFrom($proveedor);

        return $lab === \Laravel\Ai\Enums\Lab::OpenAI && $this->modeloRazona() ? ['reasoning' => ['effort' => 'low']] : [];
    }

    /** "gpt-6-luna", "openai/gpt-5-mini" u "o3" no la admiten; "gemini-3.8-flash", "deepseek-flash" o "gpt-4.1-mini" sí. */
    public static function admiteTemperatura(?string $modelo): bool
    {
        return $modelo === null || ! preg_match('/^(?:[\w.-]+\/)?(?:o\d|gpt-(?:[5-9]|\d{2,}))/i', trim($modelo));
    }
}
