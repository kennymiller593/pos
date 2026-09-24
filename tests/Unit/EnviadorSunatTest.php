<?php

namespace Tests\Unit;

use App\Models\Empresa;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\BaseResult;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\CdrResponse;
use Greenter\Model\Response\Error;
use Greenter\Model\Response\StatusResult;
use Greenter\Model\Sale\Invoice;
use Greenter\See;
use PHPUnit\Framework\TestCase;

/**
 * Prueba la traducción de las respuestas de Greenter sin tocar la red:
 * se reemplaza el See por un doble que devuelve el resultado configurado.
 */
class EnviadorSunatTest extends TestCase
{
    private function enviadorCon(?BaseResult $envio = null, ?StatusResult $estado = null, ?RespuestaSunat $cdrConsultado = null): EnviadorSunat
    {
        $see = new class($envio, $estado) extends See
        {
            public function __construct(private ?BaseResult $envio, private ?StatusResult $estado)
            {
                parent::__construct();
            }

            public function send(DocumentInterface $document): ?BaseResult
            {
                return $this->envio;
            }

            public function getStatus(?string $ticket): StatusResult
            {
                return $this->estado;
            }
        };

        return new class($see, $cdrConsultado) extends EnviadorSunat
        {
            public function __construct(private See $see, private ?RespuestaSunat $cdrConsultado)
            {
            }

            protected function armarSee(Empresa $empresa): See
            {
                return $this->see;
            }

            protected function consultarCdr(Empresa $empresa, string $tipo, string $serie, int $numero): ?RespuestaSunat
            {
                return $this->cdrConsultado;
            }
        };
    }

    private function cdr(string $codigo, string $descripcion, array $notas = []): CdrResponse
    {
        return (new CdrResponse())->setCode($codigo)->setDescription($descripcion)->setNotes($notas);
    }

    private function invoice(): Invoice
    {
        return (new Invoice())->setTipoDoc('03')->setSerie('B001')->setCorrelativo('7');
    }

    public function test_un_cdr_con_codigo_de_rechazo_no_es_aceptado(): void
    {
        // Greenter marca success en cuanto llega un CDR, aunque sea de rechazo
        $resultado = (new BillResult())->setSuccess(true)->setCdrZip('zip')->setCdrResponse($this->cdr('2335', 'El documento ya existe'));

        $respuesta = $this->enviadorCon($resultado)->enviar(new Empresa(), $this->invoice());

        $this->assertFalse($respuesta->aceptado);
        $this->assertFalse($respuesta->errorComunicacion);
        $this->assertSame('2335', $respuesta->codigo);
        $this->assertSame('zip', $respuesta->cdrZip);
    }

    public function test_un_cdr_con_codigo_cero_es_aceptado_y_4000_es_observado(): void
    {
        $aceptada = $this->enviadorCon((new BillResult())->setSuccess(true)->setCdrResponse($this->cdr('0', 'Aceptada')))
            ->enviar(new Empresa(), $this->invoice());
        $this->assertTrue($aceptada->aceptado);
        $this->assertSame([], $aceptada->observaciones);

        $observada = $this->enviadorCon((new BillResult())->setSuccess(true)->setCdrResponse($this->cdr('4000', 'Aceptada con observaciones', ['4096 - ubigeo'])))
            ->enviar(new Empresa(), $this->invoice());
        $this->assertTrue($observada->aceptado);
        $this->assertSame(['4096 - ubigeo'], $observada->observaciones);
    }

    public function test_un_error_de_comunicacion_admite_reintento_y_un_2000_no(): void
    {
        $caido = (new BillResult())->setError((new Error())->setCode('0110')->setMessage('No se pudo conectar'));
        $respuesta = $this->enviadorCon($caido)->enviar(new Empresa(), $this->invoice());
        $this->assertFalse($respuesta->aceptado);
        $this->assertTrue($respuesta->errorComunicacion);

        $rechazo = (new BillResult())->setError((new Error())->setCode('2017')->setMessage('RUC inválido'));
        $respuesta = $this->enviadorCon($rechazo)->enviar(new Empresa(), $this->invoice());
        $this->assertFalse($respuesta->aceptado);
        $this->assertFalse($respuesta->errorComunicacion);
    }

    public function test_ante_1033_se_recupera_el_cdr_del_comprobante_ya_registrado(): void
    {
        $yaRegistrado = (new BillResult())->setError((new Error())->setCode('1033')->setMessage('El comprobante fue registrado previamente'));
        $consultado = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'Aceptada', cdrZip: 'zip-recuperado');

        $respuesta = $this->enviadorCon($yaRegistrado, cdrConsultado: $consultado)->enviar(new Empresa(), $this->invoice());

        $this->assertTrue($respuesta->aceptado);
        $this->assertSame('zip-recuperado', $respuesta->cdrZip);

        // si la consulta no resuelve (p. ej. en beta), sigue pendiente para reintentar
        $respuesta = $this->enviadorCon($yaRegistrado, cdrConsultado: null)->enviar(new Empresa(), $this->invoice());
        $this->assertFalse($respuesta->aceptado);
        $this->assertTrue($respuesta->errorComunicacion);
        $this->assertSame('1033', $respuesta->codigo);
    }

    public function test_el_ticket_procesado_con_errores_no_confirma_la_baja(): void
    {
        // statusCode 99: SUNAT proceso la comunicacion pero el CDR trae el rechazo
        $estado = (new StatusResult())->setCode('99')->setSuccess(true)->setCdrZip('zip')
            ->setCdrResponse($this->cdr('2320', 'El comprobante no existe'));

        $respuesta = $this->enviadorCon(estado: $estado)->consultarTicket(new Empresa(), 'T-1');

        $this->assertFalse($respuesta->aceptado);
        $this->assertFalse($respuesta->enProceso);
        $this->assertFalse($respuesta->errorComunicacion);
        $this->assertSame('2320', $respuesta->codigo);
    }

    public function test_el_ticket_con_cdr_cero_confirma_la_baja_y_98_sigue_en_proceso(): void
    {
        $ok = (new StatusResult())->setCode('0')->setSuccess(true)->setCdrZip('zip')
            ->setCdrResponse($this->cdr('0', 'La comunicación de baja ha sido aceptada'));
        $respuesta = $this->enviadorCon(estado: $ok)->consultarTicket(new Empresa(), 'T-2');
        $this->assertTrue($respuesta->aceptado);
        $this->assertSame('zip', $respuesta->cdrZip);

        $enProceso = (new StatusResult())->setCode('98')->setError((new Error())->setCode('98')->setMessage('En proceso'));
        $respuesta = $this->enviadorCon(estado: $enProceso)->consultarTicket(new Empresa(), 'T-2');
        $this->assertTrue($respuesta->enProceso);
        $this->assertFalse($respuesta->aceptado);
    }
}
