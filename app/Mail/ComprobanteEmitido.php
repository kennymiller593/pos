<?php

namespace App\Mail;

use App\Models\Comprobante;
use App\Services\ComprobantePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/** Comprobante en PDF (y su XML firmado, si existe) enviado al cliente. */
class ComprobanteEmitido extends Mailable
{
    use Queueable;
    use SerializesModels;

    public const TIPOS = ['00' => 'Nota de venta', '01' => 'Factura electrónica', '03' => 'Boleta de venta electrónica', '07' => 'Nota de crédito electrónica'];

    public function __construct(public readonly Comprobante $comprobante) {}

    public function numero(): string
    {
        return "{$this->comprobante->serie}-".str_pad($this->comprobante->correlativo, 6, '0', STR_PAD_LEFT);
    }

    public function envelope(): Envelope
    {
        $empresa = $this->comprobante->empresa;
        $tipo = self::TIPOS[$this->comprobante->tipo_comprobante_codigo] ?? 'Comprobante';

        return new Envelope(
            subject: "{$tipo} {$this->numero()} · ".($empresa->nombre_comercial ?: $empresa->razon_social),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.comprobante', with: [
            'comprobante' => $this->comprobante,
            'empresa' => $this->comprobante->empresa,
            'numero' => $this->numero(),
            'tipo' => self::TIPOS[$this->comprobante->tipo_comprobante_codigo] ?? 'Comprobante',
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $adjuntos = [
            Attachment::fromData(
                fn () => app(ComprobantePdfService::class)->a4($this->comprobante)->output(),
                "{$this->numero()}.pdf",
            )->withMime('application/pdf'),
        ];

        $xml = $this->comprobante->sunat?->xml_url;

        if ($xml && Storage::exists($xml)) {
            $adjuntos[] = Attachment::fromStorage($xml)->as(basename($xml))->withMime('application/xml');
        }

        return $adjuntos;
    }
}
