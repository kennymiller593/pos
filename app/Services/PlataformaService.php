<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/** Cuentas de la plataforma (superadmin). */
class PlataformaService
{
    /**
     * Mueve el acceso de superadmin de una cuenta a un correo nuevo: crea la cuenta
     * exclusiva de plataforma y la cuenta actual vuelve a ser una cuenta normal de su empresa.
     * La cuenta nueva queda colgada de la misma empresa (la columna es obligatoria), pero no
     * cuenta para sus límites ni aparece en su lista de usuarios.
     */
    public function migrarSuperadmin(Usuario $actual, string $email, string $nombre, string $password): Usuario
    {
        return DB::transaction(function () use ($actual, $email, $nombre, $password) {
            $nuevo = Usuario::create([
                'empresa_id' => $actual->empresa_id,
                'sucursal_id' => null,
                'rol_id' => Rol::where('codigo', 'admin')->value('id'),
                'email' => mb_strtolower(trim($email)),
                'password_hash' => $password,
                'nombre_completo' => trim($nombre),
                'activo' => true,
                'email_verificado_en' => now(),
            ]);
            $nuevo->forceFill(['es_superadmin' => true])->save();

            $actual->forceFill(['es_superadmin' => false])->save();

            Auditoria::registrar($actual, 'plataforma.superadmin_migrado', 'usuario', $nuevo->id, [
                'de' => $actual->email,
                'a' => $nuevo->email,
            ]);

            return $nuevo;
        });
    }
}
