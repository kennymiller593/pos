<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** Abre el .xlsx que devuelve una descarga para revisar su contenido. */
trait LeeExcel
{
    protected function abrirExcel(TestResponse $respuesta): Spreadsheet
    {
        $respuesta->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $respuesta->headers->get('content-type'));

        $archivo = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($archivo, $respuesta->streamedContent());

        try {
            return IOFactory::load($archivo);
        } finally {
            @unlink($archivo);
        }
    }

    /** Filas de una hoja como listas de valores (sin las vacías). */
    protected function filasDe(Spreadsheet $libro, int $hoja = 0): array
    {
        return array_values(array_filter(
            $libro->getSheet($hoja)->toArray(null, true, false),
            fn ($fila) => array_filter($fila, fn ($c) => $c !== null && $c !== '') !== [],
        ));
    }
}
