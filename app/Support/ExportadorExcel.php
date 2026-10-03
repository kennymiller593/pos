<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Libro de Excel (.xlsx) con una o más hojas: título, subtítulo, encabezado fijo con filtro,
 * datos y un resumen al pie. Los números y fechas van como tales (se pueden sumar y ordenar
 * en Excel) y el texto nunca se interpreta como fórmula.
 */
class ExportadorExcel
{
    private const VERDE = '059669';

    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private Spreadsheet $libro;

    private int $hojas = 0;

    public function __construct()
    {
        $this->libro = new Spreadsheet;
        $this->libro->getProperties()->setCreator('inkaPos')->setTitle('Exportación');
    }

    /**
     * @param  list<string>  $columnas
     * @param  iterable<array|\ArrayAccess>  $filas  cada fila, una lista de celdas en el orden de $columnas
     * @param  list<array{etiqueta: string, valor: mixed}>  $resumen
     */
    public function hoja(string $nombre, string $titulo, ?string $subtitulo, array $columnas, iterable $filas, array $resumen = []): static
    {
        $hoja = $this->hojas === 0 ? $this->libro->getActiveSheet() : $this->libro->createSheet();
        $this->hojas++;
        // Excel no admite estos caracteres ni mas de 31 letras en el nombre de la hoja
        $hoja->setTitle(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $nombre), 0, 31));

        $hoja->setCellValueExplicit('A1', $titulo, DataType::TYPE_STRING);
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        if ($subtitulo) {
            $hoja->setCellValueExplicit('A2', $subtitulo, DataType::TYPE_STRING);
            $hoja->getStyle('A2')->getFont()->getColor()->setRGB('64748B');
        }

        $filaEncabezado = 4;
        $ultimaColumna = max(1, count($columnas));
        $anchos = [];

        foreach ($columnas as $i => $columna) {
            $hoja->setCellValueExplicit([$i + 1, $filaEncabezado], (string) $columna, DataType::TYPE_STRING);
            $anchos[$i] = mb_strlen((string) $columna) + 2;
        }
        $rangoEncabezado = "A{$filaEncabezado}:".Coordinate::stringFromColumnIndex($ultimaColumna).$filaEncabezado;
        $hoja->getStyle($rangoEncabezado)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::VERDE]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $hoja->getRowDimension($filaEncabezado)->setRowHeight(20);

        $fila = $filaEncabezado;
        foreach ($filas as $datos) {
            $fila++;
            foreach (array_values((array) $datos) as $i => $celda) {
                $this->escribir($hoja, $i + 1, $fila, $celda);
                $anchos[$i] = max($anchos[$i] ?? 0, mb_strlen($this->texto($celda)) + 2);
            }
        }

        if ($fila > $filaEncabezado) {
            $hoja->setAutoFilter("{$rangoEncabezado[0]}{$filaEncabezado}:".Coordinate::stringFromColumnIndex($ultimaColumna).$fila);
        }
        $hoja->freezePane('A'.($filaEncabezado + 1));

        if ($resumen !== []) {
            $fila += 2;
            foreach ($resumen as $linea) {
                $hoja->setCellValueExplicit([1, $fila], (string) $linea['etiqueta'], DataType::TYPE_STRING);
                $hoja->getStyle([1, $fila])->getFont()->setBold(true);
                $this->escribir($hoja, 2, $fila, $linea['valor']);
                $anchos[0] = max($anchos[0] ?? 0, mb_strlen((string) $linea['etiqueta']) + 2);
                $fila++;
            }
        }

        foreach ($anchos as $i => $ancho) {
            $hoja->getColumnDimensionByColumn($i + 1)->setWidth(min(60, max(8, $ancho)));
        }

        return $this;
    }

    public function descargar(string $nombreArchivo): StreamedResponse
    {
        $this->libro->setActiveSheetIndex(0);
        $nombre = str_ends_with($nombreArchivo, '.xlsx') ? $nombreArchivo : "{$nombreArchivo}.xlsx";

        return response()->streamDownload(fn () => (new Xlsx($this->libro))->save('php://output'), $nombre, ['Content-Type' => self::MIME]);
    }

    /** Numero, fecha o texto segun el valor (acepta tambien los textos ya formateados: "1,234.50", "S/ 10.00", "12.5%", "02/10/2026"). */
    private function escribir(Worksheet $hoja, int $columna, int $fila, mixed $valor): void
    {
        $celda = [$columna, $fila];

        if ($valor === null || $valor === '') {
            return;
        }

        if ($valor instanceof \DateTimeInterface) {
            $hoja->setCellValue($celda, Date::PHPToExcel($valor));
            $hoja->getStyle($celda)->getNumberFormat()->setFormatCode($valor->format('H:i') === '00:00' ? 'DD/MM/YYYY' : 'DD/MM/YYYY HH:MM');

            return;
        }

        if (is_int($valor) || is_float($valor)) {
            $hoja->setCellValue($celda, $valor);
            $hoja->getStyle($celda)->getNumberFormat()->setFormatCode(is_int($valor) ? NumberFormat::FORMAT_NUMBER : '#,##0.00');

            return;
        }

        if (is_bool($valor)) {
            $hoja->setCellValueExplicit($celda, $valor ? 'Sí' : 'No', DataType::TYPE_STRING);

            return;
        }

        $texto = trim((string) $valor);

        if (preg_match('/^(S\/\s*)?(-?[\d,]+(\.\d+)?)$/', $texto, $m) && is_numeric(str_replace(',', '', $m[2]))) {
            $numero = (float) str_replace(',', '', $m[2]);
            $hoja->setCellValue($celda, $numero);
            $decimales = isset($m[3]) && $m[3] !== '' ? strlen($m[3]) - 1 : 0;
            $hoja->getStyle($celda)->getNumberFormat()->setFormatCode(
                ($m[1] !== '' ? '"S/ "' : '').($decimales > 0 ? '#,##0.'.str_repeat('0', $decimales) : '#,##0'),
            );

            return;
        }

        if (preg_match('/^(-?[\d,]+(\.\d+)?)%$/', $texto, $m)) {
            $hoja->setCellValue($celda, (float) str_replace(',', '', $m[1]) / 100);
            $hoja->getStyle($celda)->getNumberFormat()->setFormatCode('0.0%');

            return;
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})(?: (\d{2}):(\d{2}))?$/', $texto, $m)) {
            $fecha = \DateTime::createFromFormat('Y-m-d H:i', "{$m[3]}-{$m[2]}-{$m[1]} ".($m[4] ?? '00').':'.($m[5] ?? '00'));
            if ($fecha) {
                $hoja->setCellValue($celda, Date::PHPToExcel($fecha));
                $hoja->getStyle($celda)->getNumberFormat()->setFormatCode(isset($m[4]) ? 'DD/MM/YYYY HH:MM' : 'DD/MM/YYYY');

                return;
            }
        }

        // texto tal cual: un nombre que empiece con "=" no se vuelve formula
        $hoja->setCellValueExplicit($celda, $texto, DataType::TYPE_STRING);
    }

    private function texto(mixed $valor): string
    {
        return match (true) {
            $valor instanceof \DateTimeInterface => $valor->format('d/m/Y H:i'),
            is_bool($valor) => 'No',
            is_float($valor) => number_format($valor, 2),
            default => (string) $valor,
        };
    }
}
