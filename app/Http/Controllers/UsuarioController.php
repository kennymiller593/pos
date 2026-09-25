<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Mail\BienvenidaUsuario;
use App\Models\Auditoria;
use App\Models\Rol;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\SuscripcionService;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
                ->select('usuarios.*')
                ->selectRaw(Usuario::sqlTieneHistorial().' AS con_historial')
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
        $enviarCorreo = $request->boolean('enviar_correo');
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
            // lo crea un administrador ya verificado: no hace falta confirmar el correo
            'email_verificado_en' => now(),
            'nombre_completo' => $datos['nombre_completo'],
            'activo' => $datos['activo'],
        ]);

        $usuario->sucursales()->sync($sucursales);

        $rol = Rol::find($datos['rol_id'])?->nombre;

        Auditoria::registrar($request->user(), 'usuario.creado', 'usuario', $usuario->id, [
            'nombre' => $usuario->nombre_completo,
            'email' => $usuario->email,
            'rol' => $rol,
            'correo_enviado' => $enviarCorreo,
        ]);

        if (! $enviarCorreo) {
            return back()->with('success', 'Usuario creado.');
        }

        // el usuario ya existe: si el correo falla se avisa, pero no se deshace la creacion
        try {
            $empresa = $request->user()->empresa;
            Mail::to($usuario->email)->send(new BienvenidaUsuario(
                nombre: $usuario->nombre_completo,
                email: $usuario->email,
                password: $datos['password'],
                empresa: $empresa->nombre_comercial ?: $empresa->razon_social,
                rol: $rol,
                urlLogin: route('login'),
                creadoPor: $request->user()->nombre_completo,
            ));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', "Usuario creado, pero no se pudo enviar el correo a {$usuario->email}. Compártele sus datos de acceso por otro medio.");
        }

        return back()->with('success', "Usuario creado. Le enviamos sus datos de acceso a {$usuario->email}.");
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

    /**
     * Elimina un usuario que nunca trabajo en el sistema (por ejemplo, creado por error).
     * Si ya tiene ventas, cajas, compras, etc., solo se puede desactivar para no perder el historial.
     */
    public function destroy(Request $request, Usuario $usuario): RedirectResponse
    {
        abort_unless($usuario->empresa_id === $request->user()->empresa_id, 403);

        if ($usuario->id === $request->user()->id) {
            return back()->with('error', 'No puedes eliminarte a ti mismo.');
        }

        if ($usuario->es_superadmin) {
            return back()->with('error', 'Este usuario administra la plataforma y no se puede eliminar.');
        }

        if ($usuario->tieneHistorial()) {
            return back()->with('error', "{$usuario->nombre_completo} ya tiene movimientos registrados, por eso no se puede eliminar. Desactívalo para que ya no pueda ingresar.");
        }

        DB::transaction(function () use ($usuario) {
            $usuario->sucursales()->detach();
            DB::table('recuperaciones_password')->where('email', $usuario->email)->delete();
            $usuario->delete();
        });

        Auditoria::registrar($request->user(), 'usuario.eliminado', 'usuario', $usuario->id, [
            'nombre' => $usuario->nombre_completo,
            'email' => $usuario->email,
            'rol' => $usuario->rol?->nombre,
        ]);

        return back()->with('success', "Usuario {$usuario->nombre_completo} eliminado.");
    }

    private function validar(Request $request, ?Usuario $usuario = null): array
    {
        // el correo se guarda y se compara siempre en minusculas
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

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
            'password' => [$usuario ? 'nullable' : 'required', 'confirmed', Password::defaults()],
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
