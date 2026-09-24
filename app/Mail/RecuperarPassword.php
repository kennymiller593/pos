<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecuperarPassword extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $url)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Restablece tu contraseña · POS App');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.recuperar-password');
    }
}
