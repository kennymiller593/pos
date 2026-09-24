<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificarCorreo extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $url, public readonly string $nombre) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirma tu correo · '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.verificar-correo');
    }
}
