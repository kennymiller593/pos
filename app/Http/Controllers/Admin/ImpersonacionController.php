<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ErrorDeNegocio;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\ImpersonacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Entrar como un usuario de una empresa (soporte) y volver a la plataforma. */
class ImpersonacionController extends Controller
{
    public function __construct(private readonly ImpersonacionService $impersonacion) {}

    public function entrar(Request $request, Empresa $empresa): RedirectResponse
    {
        $datos = $request->validate(['usuario_id' => ['nullable', 'uuid']]);

        try {
            $usuario = $this->impersonacion->entrar($request, $request->user(), $empresa, $datos['usuario_id'] ?? null);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/dashboard')->with('success', "Estás dentro de {$empresa->razon_social} como {$usuario->nombre_completo}.");
    }

    public function salir(Request $request): RedirectResponse
    {
        $superadmin = $this->impersonacion->salir($request);

        return $superadmin
            ? redirect()->route('admin.empresas.index')->with('success', 'Volviste a la plataforma.')
            : redirect('/login');
    }
}
