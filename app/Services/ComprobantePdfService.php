<?php

namespace App\Services;

use App\Models\Comprobante;
use App\Models\Empresa;
use App\Support\NumeroALetras;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Barryvdh\Snappy\PdfWrapper;

/**
 * Representación impresa en A4 de un comprobante (la usan la descarga desde
 * Comprobantes y el envío por correo) y su QR reglamentario.
 */
class ComprobantePdfService
{
    /** PDF A4 listo para ->inline(), ->download() u ->output(). */
    public function a4(Comprobante $comprobante): PdfWrapper
    {
        $comprobante->loadMissing([
            'empresa',
            'detalles:id,comprobante_id,descripcion,unidad_codigo,cantidad,precio_unitario,descuento,total',
            'pagos.medioPago:codigo,nombre',
            'sucursal:id,nombre,direccion',
            'comprobanteRef:id,serie,correlativo,tipo_comprobante_codigo',
            'sunat:comprobante_id,estado',
        ]);

        $empresa = $comprobante->empresa;
        $numero = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);

        return SnappyPdf::loadView('pdf.comprobante-a4', [
            'comprobante' => $comprobante,
            'empresa' => $empresa,
            'numero' => $numero,
            'logo' => $empresa->logoParaPdf(),
            'qr' => $this->qr($comprobante, $empresa),
            'hash' => $comprobante->hash_cpe,
            'letras' => NumeroALetras::enSoles((float) $comprobante->total),
            'motivoNota' => NotaCreditoService::MOTIVOS[trim((string) $comprobante->motivo_nota)] ?? null,
        ])
            ->setOption('page-size', 'A4')
            ->setOption('margin-top', '12')
            ->setOption('margin-bottom', '12')
            ->setOption('margin-left', '14')
            ->setOption('margin-right', '14')
            ->setOption('encoding', 'utf-8');
    }

    /**
     * QR reglamentario de la representación impresa (solo comprobantes electrónicos):
     * RUC | tipo | serie | correlativo | IGV | total | fecha | doc. cliente | hash.
     */
    public function qr(Comprobante $comprobante, Empresa $empresa): ?string
    {
        if (! in_array($comprobante->tipo_comprobante_codigo, ['01', '03', '07'], true)) {
            return null;
        }

        $contenido = implode('|', [
            $empresa->ruc,
            $comprobante->tipo_comprobante_codigo,
            $comprobante->serie,
            $comprobante->correlativo,
            number_format((float) $comprobante->total_igv, 2, '.', ''),
            number_format((float) $comprobante->total, 2, '.', ''),
            $comprobante->fecha_emision->format('Y-m-d'),
            trim((string) $comprobante->cliente_tipo_doc) ?: '0',
            $comprobante->cliente_numero_doc ?: '-',
            (string) $comprobante->hash_cpe,
        ]);

        $svg = (new Writer(new ImageRenderer(new RendererStyle(300, 1), new SvgImageBackEnd)))
            ->writeString($contenido);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
