<?php

namespace App\Mail;

use App\Models\Reclamacion;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Copia de la hoja del Libro de Reclamaciones: al consumidor y a la tienda. También lleva la respuesta, cuando la hay. */
class ReclamacionRegistrada extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param  'cliente'|'tienda'|'respuesta'  $para */
    public function __construct(public readonly Reclamacion $reclamacion, public readonly string $para = 'cliente') {}

    public function envelope(): Envelope
    {
        $empresa = $this->reclamacion->empresa;
        $tipo = $this->reclamacion->tipo === 'queja' ? 'Queja' : 'Reclamo';
        $nombre = $empresa->nombre_comercial ?: $empresa->razon_social;

        return new Envelope(subject: match ($this->para) {
            'tienda' => "Nuevo {$this->reclamacion->tipo} en el Libro de Reclamaciones · N.º {$this->reclamacion->codigo()}",
            'respuesta' => "Respuesta a tu {$this->reclamacion->tipo} N.º {$this->reclamacion->codigo()} · {$nombre}",
            default => "{$tipo} N.º {$this->reclamacion->codigo()} registrado · {$nombre}",
        });
    }

    public function content(): Content
    {
        return new Content(view: 'mail.reclamacion', with: [
            'r' => $this->reclamacion,
            'empresa' => $this->reclamacion->empresa,
            'para' => $this->para,
        ]);
    }
}
