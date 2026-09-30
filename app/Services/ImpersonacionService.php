<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * "Entrar como": el superadmin abre sesión como un usuario de una empresa para dar soporte,
 * sin conocer su contraseña. La sesión guarda quién es el superadmin real para poder volver,
 * expira sola a las HORAS y queda registrada en la auditoría de la plataforma y de la empresa.
 */
class ImpersonacionService
{
    public const SESION = 'impersonacion';

    public const HORAS = 2;

    public function entrar(Request $request, Usuario $superadmin, Empresa $empresa, ?string $usuarioId = null): Usuario
    {
        $consulta = Usuario::query()
            ->where('empresa_id', $empresa->id)
            ->where('es_superadmin', false)
            ->where('activo', true);

        // sin usuario elegido: el dueño (el administrador más antiguo)
        $usuario = $usuarioId
            ? $consulta->whereKey($usuarioId)->first()
            : $consulta->whereHas('rol', fn ($q) => $q->where('codigo', 'admin'))->orderBy('creado_en')->first();

        if (! $usuario) {
            throw new ErrorDeNegocio($usuarioId
                ? 'Ese usuario no está activo o no pertenece a la empresa.'
                : "{$empresa->razon_social} no tiene un administrador activo con el que entrar.");
        }

        $detalle = ['empresa' => $empresa->razon_social, 'usuario' => $usuario->nombre_completo, 'email' => $usuario->email];
        Auditoria::registrar($superadmin, 'plataforma.entro_como', 'usuario', $usuario->id, $detalle);
        $this->auditarEnEmpresa($empresa->id, $superadmin, 'soporte.ingreso', $usuario);

        Auth::guard('web')->login($usuario);
        $request->session()->regenerate();
        $request->session()->put(self::SESION, [
            'superadmin_id' => $superadmin->id,
            'superadmin' => $superadmin->nombre_completo,
            'usuario_id' => $usuario->id,
            'usuario' => $usuario->nombre_completo,
            'empresa_id' => $empresa->id,
            'empresa' => $empresa->nombre_comercial ?: $empresa->razon_social,
            'expira' => now()->addHours(self::HORAS)->toIso8601String(),
        ]);

        return $usuario;
    }

    /** Devuelve la sesión al superadmin (o null si ya no puede volver: se cierra la sesión). */
    public function salir(Request $request, string $motivo = 'manual'): ?Usuario
    {
        $datos = $request->session()->pull(self::SESION);
        $superadmin = $datos ? Usuario::find($datos['superadmin_id']) : null;
        $usuario = $datos ? Usuario::find($datos['usuario_id']) : null;

        if ($superadmin && $usuario) {
            Auditoria::registrar($superadmin, 'plataforma.salio_de', 'usuario', $usuario->id, [
                'empresa' => $datos['empresa'], 'usuario' => $usuario->nombre_completo, 'motivo' => $motivo,
            ]);
            $this->auditarEnEmpresa($datos['empresa_id'], $superadmin, 'soporte.salida', $usuario);
        }

        if (! $superadmin?->es_superadmin || ! $superadmin->activo) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        Auth::guard('web')->login($superadmin);
        $request->session()->regenerate();

        return $superadmin;
    }

    /** Datos de la sesión "entrar como" vigente, o null. */
    public function activa(Request $request): ?array
    {
        $datos = $request->session()->get(self::SESION);
        if (! $datos || $request->user()?->id !== $datos['usuario_id']) {
            return null;
        }

        return $datos;
    }

    public function expirada(array $datos): bool
    {
        return now()->greaterThan(Carbon::parse($datos['expira']));
    }

    private function auditarEnEmpresa(string $empresaId, Usuario $superadmin, string $accion, Usuario $usuario): void
    {
        try {
            Auditoria::create([
                'empresa_id' => $empresaId,
                'usuario_id' => $superadmin->id,
                'accion' => $accion,
                'entidad' => 'usuario',
                'entidad_id' => $usuario->id,
                'detalle' => ['soporte' => $superadmin->nombre_completo, 'como' => $usuario->nombre_completo],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
