<?php

namespace Tests\Unit;

use App\Support\NumeroALetras;
use PHPUnit\Framework\TestCase;

class NumeroALetrasTest extends TestCase
{
    public function test_convierte_importes_a_letras(): void
    {
        $casos = [
            '0.00' => 'CERO CON 00/100 SOLES',
            '0.50' => 'CERO CON 50/100 SOLES',
            '1.00' => 'UNO CON 00/100 SOLES',
            '16.95' => 'DIECISÉIS CON 95/100 SOLES',
            '21.10' => 'VEINTIUNO CON 10/100 SOLES',
            '33.00' => 'TREINTA Y TRES CON 00/100 SOLES',
            '100.00' => 'CIEN CON 00/100 SOLES',
            '101.25' => 'CIENTO UNO CON 25/100 SOLES',
            '123.45' => 'CIENTO VEINTITRÉS CON 45/100 SOLES',
            '500.00' => 'QUINIENTOS CON 00/100 SOLES',
            '1000.00' => 'MIL CON 00/100 SOLES',
            '1999.99' => 'MIL NOVECIENTOS NOVENTA Y NUEVE CON 99/100 SOLES',
            '21000.00' => 'VEINTIÚN MIL CON 00/100 SOLES',
            '101000.00' => 'CIENTO UN MIL CON 00/100 SOLES',
            '123456.78' => 'CIENTO VEINTITRÉS MIL CUATROCIENTOS CINCUENTA Y SEIS CON 78/100 SOLES',
            '1000000.00' => 'UN MILLÓN CON 00/100 SOLES',
            '2500000.10' => 'DOS MILLONES QUINIENTOS MIL CON 10/100 SOLES',
        ];

        foreach ($casos as $monto => $esperado) {
            $this->assertSame($esperado, NumeroALetras::enSoles((float) $monto), "monto {$monto}");
        }
    }

    public function test_redondea_centimos_que_llegan_a_cien(): void
    {
        $this->assertSame('DOS CON 00/100 SOLES', NumeroALetras::enSoles(1.999));
    }
}
