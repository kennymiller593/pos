<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Auditoria;
use App\Models\Rol;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\SuscripcionService;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UsuarioController extends Controller
{
    public function index(Request $request): Response
    {
        $empresaId = $request->user()->empresa_id;

        return Inertia::render('Usuarios/Index', [
            'usuarios' => Usuario::query()
                ->where('empresa_id', $empresaId)
                ->with(['rol:id,codigo,nombre', 'sucursales:sucursales.id,nombre'])
                ->orderBy('nombre_completo')
                ->paginate(10),
            'roles' => Rol::orderBy('id')->get(['id', 'codigo', 'nombre']),
            'permisosPorRol' => Permisos::resumenPorRol(),
            'sucursales' => Sucursal::where('empresa_id', $empresaId)->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function store(Request $request, SuscripcionService $suscripciones): RedirectResponse
    {
        $datos = $this->validar($request);
        $sucursales = $datos['sucursal_ids'] ?? [];

        try {
            $suscripciones->verificarLimite($request->user()->empresa, 'usuarios');
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        $usuario = Usuario::create([
            'empresa_id' => $request->user()->empresa_id,
            'sucursal_id' => $sucursales[0] ?? null,
            'rol_id' => $datos['rol_id'],
            'email' => $datos['email'],
            'password_hash' => $datos['password'],
            'nombre_completo' => $datos['nombre_completo'],
            'activo' => $datos['activo'],
        ]);

        $usuario->sucursales()->sync($sucursales);

        Auditoria::registrar($request->user(), 'usuario.creado', 'usuario', $usuario->id, [
            'nombre' => $usuario->nombre_completo,
            'email' => $usuario->email,
            'rol' => Rol::find($datos['rol_id'])?->nombre,
        ]);

        return back()->with('success', 'Usuario creado.');
    }

    public function update(Request $request, Usuario $usuario): RedirectResponse
    {
        abort_unless($usuario->empresa_id === $request->user()->empresa_id, 403);

        $datos = $this->validar($request, $usuario);
        $esUnoMismo = $usuario->id === $request->user()->id;

        if ($esUnoMismo && ! $datos['activo']) {
            return back()->with('error', 'No puedes desactivarte a ti mismo.');
        }

        $rolAdmin = Rol::where('codigo', 'admin')->value('id');
        if ($esUnoMismo && (int) $datos['rol_id'] !== (int) $rolAdmin) {
            return back()->with('error', 'No puedes quitarte tu propio rol de administrador.');
        }

        $sucursales = $datos['sucursal_ids'] ?? [];
        $antes = $usuario->only(['nombre_completo', 'email', 'rol_id', 'activo']);
        $sucursalesAntes = $usuario->sucursales()->pluck('sucursales.id')->sort()->values()->all();

        $usuario->fill([
            'sucursal_id' => $sucursales[0] ?? null,
            'rol_id' => $datos['rol_id'],
            'email' => $datos['email'],
            'nombre_completo' => $datos['nombre_completo'],
            'activo' => $datos['activo'],
        ]);

        if (filled($datos['password'] ?? null)) {
            $usuario->password_hash = $datos['password'];
        }

        $cambioPassword = filled($datos['password'] ?? null);
        $usuario->save();
        $usuario->sucursales()->sync($sucursales);

        $cambios = [];
        foreach ($antes as $campo => $valorAntes) {
            $valorAhora = $usuario->{$campo};
            if ($valorAntes != $valorAhora) {
                $cambios[$campo] = ['de' => $valorAntes, 'a' => $valorAhora];
            }
        }
        if (isset($cambios['rol_id'])) {
            $roles = Rol::whereIn('id', [$cambios['rol_id']['de'], $cambios['rol_id']['a']])->pluck('nombre', 'id');
            $cambios['rol'] = [
                'de' => $roles[$cambios['rol_id']['de']] ?? $cambios['rol_id']['de'],
                'a' => $roles[$cambios['rol_id']['a']] ?? $cambios['rol_id']['a'],
            ];
            unset($cambios['rol_id']);
        }
        if ($sucursalesAntes !== collect($sucursales)->sort()->values()->all()) {
            $cambios['sucursales'] = ['de' => count($sucursalesAntes) ?: 'todas', 'a' => count($sucursales) ?: 'todas'];
        }
        if ($cambioPassword) {
            $cambios['password'] = 'cambiada';
        }

        if ($cambios) {
            Auditoria::registrar($request->user(), 'usuario.actualizado', 'usuario', $usuario->id, [
                'usuario' => $usuario->nombre_completo,
                'cambios' => $cambios,
            ]);
        }

        return back()->with('success', 'Usuario actualizado.');
    }

    private function validar(Request $request, ?Usuario $usuario = null): array
    {
        return $request->validate([
            'nombre_completo' => ['required', 'string', 'max:150'],
            'email' => [
                'required', 'email', 'max:150',
                Rule::unique('usuarios', 'email')->ignore($usuario?->id),
            ],
            'rol_id' => ['required', Rule::exists('roles', 'id')],
            'sucursal_ids' => ['nullable', 'array'],
            'sucursal_ids.*' => [
                'uuid',
                Rule::exists('sucursales', 'id')->where('empresa_id', $request->user()->empresa_id),
            ],
            'password' => [$usuario ? 'nullable' : 'required', 'confirmed', Password::min(8)],
            'activo' => ['required', 'boolean'],
        ], [
            'nombre_completo.required' => 'Ingresa el nombre.',
            'email.required' => 'Ingresa el correo.',
            'email.email' => 'El correo no es válido.',
            'email.unique' => 'Este correo ya está en uso.',
            'rol_id.required' => 'Elige un rol.',
            'password.required' => 'Ingresa una contraseña.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);
    }
}
