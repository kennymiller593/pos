<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use Illuminate\Console\Command;

/**
 * Marca (o desmarca) a un usuario como administrador de la plataforma:
 *   php artisan superadmin:asignar correo@dominio.pe
 *   php artisan superadmin:asignar correo@dominio.pe --quitar
 */
class AsignarSuperadmin extends Command
{
    protected $signature = 'superadmin:asignar {email : Correo del usuario} {--quitar : Retirar el acceso de superadmin}';

    protected $description = 'Da o quita a un usuario el acceso al panel de la plataforma (/admin)';

    public function handle(): int
    {
        $usuario = Usuario::where('email', mb_strtolower(trim($this->argument('email'))))->first();

        if (! $usuario) {
            $this->error('No existe un usuario con ese correo.');

            return self::FAILURE;
        }

        $usuario->forceFill(['es_superadmin' => ! $this->option('quitar')])->save();

        $this->info($this->option('quitar')
            ? "{$usuario->email} ya no es superadmin."
            : "{$usuario->email} ahora es superadmin: puede entrar a /admin.");

        return self::SUCCESS;
    }
}
