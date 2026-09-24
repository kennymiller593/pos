<?php

namespace Tests\Fakes;

use App\Models\Empresa;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Voided\Voided;

/** Doble del enviador: captura los documentos y responde lo que el test configure. */
class EnviadorSunatFalso extends EnviadorSunat
{
    public ?RespuestaSunat $respuesta = null;

    public ?RespuestaSunat $respuestaBaja = null;

    public ?RespuestaSunat $respuestaTicket = null;

    public ?\Throwable $excepcion = null;

    public ?DocumentInterface $ultimoInvoice = null;

    public Voided|Summary|null $ultimaBaja = null;

    public int $llamadas = 0;

    public int $consultasTicket = 0;

    public function enviar(Empresa $empresa, DocumentInterface $documento): RespuestaSunat
    {
        $this->llamadas++;
        $this->ultimoInvoice = $documento;

        if ($this->excepcion) {
            throw $this->excepcion;
        }

        return $this->respuesta ?? throw new \LogicException('El test no configuró una respuesta SUNAT.');
    }

    public function enviarBaja(Empresa $empresa, Voided|Summary $documento): RespuestaSunat
    {
        $this->ultimaBaja = $documento;

        return $this->respuestaBaja ?? throw new \LogicException('El test no configuró una respuesta de baja.');
    }

    public function consultarTicket(Empresa $empresa, string $ticket): RespuestaSunat
    {
        $this->consultasTicket++;

        return $this->respuestaTicket ?? throw new \LogicException('El test no configuró una respuesta de ticket.');
    }
}
