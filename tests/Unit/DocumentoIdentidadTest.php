<?php

namespace Tests\Unit;

use App\Support\DocumentoIdentidad;
use PHPUnit\Framework\TestCase;

class DocumentoIdentidadTest extends TestCase
{
    public function test_ruc_con_digito_verificador_correcto(): void
    {
        $this->assertTrue(DocumentoIdentidad::rucValido('20123456786'));
        $this->assertTrue(DocumentoIdentidad::rucValido('20100070970')); // RUC real de prueba
        $this->assertTrue(DocumentoIdentidad::rucValido('10467903425'));

        $this->assertFalse(DocumentoIdentidad::rucValido('20123456789')); // digito final incorrecto
        $this->assertFalse(DocumentoIdentidad::rucValido('2012345678'));  // 10 digitos
        $this->assertFalse(DocumentoIdentidad::rucValido('30123456786')); // prefijo invalido
        $this->assertFalse(DocumentoIdentidad::rucValido('2012345678A'));
    }

    public function test_formato_por_tipo_de_documento(): void
    {
        $this->assertTrue(DocumentoIdentidad::esValido('1', '12345678'));
        $this->assertFalse(DocumentoIdentidad::esValido('1', '1234567'));
        $this->assertFalse(DocumentoIdentidad::esValido('1', '1234567A'));

        $this->assertTrue(DocumentoIdentidad::esValido('6', '20123456786'));
        $this->assertFalse(DocumentoIdentidad::esValido('6', '20123456789'));

        $this->assertTrue(DocumentoIdentidad::esValido('4', 'CE123456'));
        $this->assertTrue(DocumentoIdentidad::esValido('7', 'PA1234567'));
        $this->assertFalse(DocumentoIdentidad::esValido('7', 'PA1'));

        $this->assertTrue(DocumentoIdentidad::esValido('0', ''));
        $this->assertFalse(DocumentoIdentidad::esValido('0', '123'));
    }
}
