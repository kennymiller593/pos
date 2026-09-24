<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Empresa;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\ConsultaRucService;
use App\Services\SuscripcionService;
use App\Support\DocumentoIdentidad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegistroController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Registro', [
            'rubros' => Rubro::orderBy('nombre')->get(['codigo', 'nombre']),
        ]);
    }

    public function store(Request $request, SuscripcionService $suscripciones, ConsultaRucService $consultas): RedirectResponse
    {
        // el correo se guarda y se compara siempre en minusculas
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        $datos = $request->validate([
            'ruc' => [
                'required', 'digits:11', Rule::unique('empresas', 'ruc'),
                function (string $atributo, mixed $valor, \Closure $falla) use ($consultas) {
                    if (! DocumentoIdentidad::rucValido((string) $valor)) {
                        $falla('El RUC no es válido (revisa el dígito final).');

                        return;
                    }

                    // si SUNAT responde, el RUC debe existir y estar activo; si el servicio
                    // no esta disponible no se bloquea el registro
                    $datos = $consultas->consultar((string) $valor);

                    if ($datos === ConsultaRucService::NO_EXISTE) {
                        $falla('Ese RUC no figura en SUNAT.');
                    } elseif (is_array($datos) && filled($datos['estado']) && mb_strtoupper($datos['estado']) !== 'ACTIVO') {
                        $falla("Ese RUC figura en SUNAT como {$datos['estado']}; solo se registran contribuyentes activos.");
                    }
                },
            ],
            'razon_social' => ['required', 'string', 'max:200'],
            'nombre_comercial' => ['nullable', 'string', 'max:200'],
            'rubro_codigo' => ['required', Rule::exists('rubros', 'codigo')],
            'regimen_tributario' => ['required', Rule::in(['RUS', 'RER', 'MYPE', 'GENERAL'])],
            'direccion' => ['nullable', 'string', 'max:250'],
            'ubigeo' => ['nullable', 'digits:6'],
            'nombre_completo' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('usuarios', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'ruc.required' => 'Ingresa el RUC.',
            'ruc.digits' => 'El RUC debe tener 11 dígitos.',
            'ruc.unique' => 'Este RUC ya está registrado.',
            'razon_social.required' => 'Ingresa la razón social.',
            'rubro_codigo.required' => 'Elige el rubro de tu negocio.',
            'ubigeo.digits' => 'El ubigeo debe tener 6 dígitos.',
            'nombre_completo.required' => 'Ingresa tu nombre.',
            'email.required' => 'Ingresa tu correo.',
            'email.email' => 'El correo no es válido.',
            'email.unique' => 'Este correo ya está registrado.',
            'password.required' => 'Ingresa una contraseña.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $usuario = DB::transaction(function () use ($datos, $suscripciones) {
            $empresa = Empresa::create([
                'ruc' => $datos['ruc'],
                'razon_social' => $datos['razon_social'],
                'nombre_comercial' => $datos['nombre_comercial'] ?? null,
                'regimen_tributario' => $datos['regimen_tributario'],
                'rubro_codigo' => $datos['rubro_codigo'],
                'activo' => true,
            ]);

            // toda empresa nueva arranca con la prueba gratuita
            $suscripciones->iniciarPrueba($empresa);

            $sucursal = Sucursal::create([
                'empresa_id' => $empresa->id,
                'codigo_sunat' => '0000',
                'nombre' => 'Principal',
                'direccion' => $datos['direccion'] ?? null,
                'ubigeo' => $datos['ubigeo'] ?? null,
                'activo' => true,
            ]);

            Caja::create([
                'empresa_id' => $empresa->id,
                'sucursal_id' => $sucursal->id,
                'nombre' => 'Caja 1',
                'activo' => true,
            ]);

            return Usuario::create([
                'empresa_id' => $empresa->id,
                'sucursal_id' => $sucursal->id,
                'rol_id' => Rol::where('codigo', 'admin')->value('id'),
                'email' => $datos['email'],
                'password_hash' => $datos['password'],
                'nombre_completo' => $datos['nombre_completo'],
                'activo' => true,
            ]);
        });

        Auth::login($usuario);
        $request->session()->regenerate();

        // el correo se confirma con un enlace; hay 3 dias de gracia para usar el sistema mientras tanto
        VerificacionCorreoController::enviar($usuario);

        return redirect('/')->with('success', '¡Bienvenido! Tu negocio quedó registrado. Te enviamos un correo para confirmar tu cuenta.');
    }
}
