<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Services\PlataformaService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Mueve el acceso de superadmin a una cuenta exclusiva de plataforma con otro correo:
 *   php artisan superadmin:migrar actual@empresa.pe nuevo@inkanet.pro --nombre="Administrador inkaPos"
 * Genera la contraseña y la muestra una sola vez.
 */
class MigrarSuperadmin extends Command
{
    protected $signature = 'superadmin:migrar {email_actual : Cuenta que hoy es superadmin} {email_nuevo : Correo de la cuenta exclusiva de plataforma} {--nombre= : Nombre de la cuenta nueva}';

    protected $description = 'Crea una cuenta exclusiva de plataforma y le pasa el acceso de superadmin';

    public function handle(PlataformaService $plataforma): int
    {
        $actual = Usuario::where('email', mb_strtolower(trim($this->argument('email_actual'))))->first();
        $emailNuevo = mb_strtolower(trim($this->argument('email_nuevo')));

        if (! $actual?->es_superadmin) {
            $this->error('Esa cuenta no existe o no es superadmin.');

            return self::FAILURE;
        }

        if (! filter_var($emailNuevo, FILTER_VALIDATE_EMAIL) || Usuario::where('email', $emailNuevo)->exists()) {
            $this->error('El correo nuevo no es válido o ya está en uso.');

            return self::FAILURE;
        }

        $password = Str::password(14, symbols: false);
        $nuevo = $plataforma->migrarSuperadmin($actual, $emailNuevo, $this->option('nombre') ?: 'Administrador de la plataforma', $password);

        $this->info("Cuenta de plataforma creada: {$nuevo->email}");
        $this->line("Contraseña (guárdala, no se vuelve a mostrar): {$password}");
        $this->line("{$actual->email} vuelve a ser una cuenta normal de su empresa.");

        return self::SUCCESS;
    }
}
