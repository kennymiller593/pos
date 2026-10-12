<?php

namespace App\IA;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/** Agente mínimo para el botón "Probar conexión" de un proveedor: solo tiene que contestar algo. */
class SondaIa implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Responde únicamente con la palabra OK.';
    }

    public function maxTokens(): int
    {
        return 20;
    }

    public function temperature(): float
    {
        return 0.0;
    }
}
