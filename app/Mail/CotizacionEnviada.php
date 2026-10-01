<?php

namespace App\Mail;

use App\Models\Cotizacion;
use App\Services\CotizacionService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Cotización en PDF enviada al cliente. */
class CotizacionEnviada extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Cotizacion $cotizacion) {}

    public function envelope(): Envelope
    {
        $empresa = $this->cotizacion->empresa;

        return new Envelope(
            subject: "Cotización {$this->cotizacion->codigo()} · ".($empresa->nombre_comercial ?: $empresa->razon_social),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.cotizacion', with: [
            'cotizacion' => $this->cotizacion,
            'empresa' => $this->cotizacion->empresa,
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => app(CotizacionService::class)->pdf($this->cotizacion)->output(),
                "{$this->cotizacion->codigo()}.pdf",
            )->withMime('application/pdf'),
        ];
    }
}
