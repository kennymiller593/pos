<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Acceso para un usuario creado por el administrador: correo, contraseña y botón al login.
 * Se envía en el momento (no por la cola) para que la contraseña no quede guardada en la tabla jobs.
 */
class BienvenidaUsuario extends Mailable
{
    public function __construct(
        public readonly string $nombre,
        public readonly string $email,
        public readonly string $password,
        public readonly string $empresa,
        public readonly ?string $rol,
        public readonly string $urlLogin,
        public readonly string $creadoPor,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Tu acceso a {$this->empresa} · ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.bienvenida-usuario');
    }
}
