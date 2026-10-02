<?php

namespace Tests\Fakes;

use App\Models\Empresa;
use App\Services\Sunat\EnviadorGuia;
use App\Services\Sunat\RespuestaSunat;
use Greenter\Model\Despatch\Despatch;

/** Doble del enviador de guías: captura el documento y responde lo que el test configure. */
class EnviadorGuiaFalso extends EnviadorGuia
{
    public ?RespuestaSunat $respuesta = null;

    public ?RespuestaSunat $respuestaTicket = null;

    public ?\Throwable $excepcion = null;

    public ?Despatch $ultimaGuia = null;

    public int $envios = 0;

    public int $consultas = 0;

    public function enviar(Empresa $empresa, Despatch $guia): RespuestaSunat
    {
        $this->envios++;
        $this->ultimaGuia = $guia;

        if ($this->excepcion) {
            throw $this->excepcion;
        }

        return $this->respuesta ?? throw new \LogicException('El test no configuró una respuesta SUNAT.');
    }

    public function consultarTicket(Empresa $empresa, string $ticket): RespuestaSunat
    {
        $this->consultas++;

        return $this->respuestaTicket ?? throw new \LogicException('El test no configuró una respuesta de ticket.');
    }
}
