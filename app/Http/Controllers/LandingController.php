<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\SuscripcionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Página pública de presentación del producto, con los planes tal como están en la BD.
 * Es Blade puro (sin Inertia ni Vue) para que los buscadores reciban todo el contenido en el HTML.
 */
class LandingController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('inicio');
        }

        return view('landing', [
            'marca' => config('app.name'),
            'planes' => Plan::query()
                ->where('activo', true)
                ->where('publico', true)
                ->where('codigo', '!=', 'prueba')
                ->orderBy('orden')
                ->get(['codigo', 'nombre', 'descripcion', 'precio_mensual', 'max_sucursales', 'max_usuarios', 'max_comprobantes_mes']),
            'diasPrueba' => SuscripcionService::DIAS_PRUEBA,
            'whatsapp' => preg_replace('/\D/', '', (string) config('app.soporte_whatsapp')) ?: null,
            'email' => config('app.soporte_email') ?: null,
        ]);
    }
}
