<?php

namespace App\IA;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/** Agente mínimo para el botón "Probar conexión" de un proveedor: solo tiene que contestar algo. */
class SondaIa implements Agent, HasProviderOptions
{
    use Promptable;
    use TemperaturaSegunModelo;

    public function instructions(): string
    {
        return 'Responde únicamente con la palabra OK.';
    }

    /** Un modelo que razona gasta tokens pensando antes de contestar: con 20 la respuesta saldría vacía. */
    public function maxTokens(): int
    {
        return $this->modeloRazona() ? 500 : 20;
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->razonamientoBajo($provider);
    }

    public function temperature(): ?float
    {
        return $this->temperaturaPermitida(0.0);
    }
}
