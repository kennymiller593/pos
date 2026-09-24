<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\SuscripcionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Página pública de presentación del producto, con los planes tal como están en la BD. */
class LandingController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('inicio');
        }

        return Inertia::render('Landing', [
            'planes' => Plan::query()
                ->where('activo', true)
                ->where('codigo', '!=', 'prueba')
                ->orderBy('orden')
                ->get(['codigo', 'nombre', 'descripcion', 'precio_mensual', 'max_sucursales', 'max_usuarios', 'max_comprobantes_mes']),
            'diasPrueba' => SuscripcionService::DIAS_PRUEBA,
            'contacto' => [
                'whatsapp' => config('app.soporte_whatsapp'),
                'email' => config('app.soporte_email'),
            ],
        ]);
    }
}
