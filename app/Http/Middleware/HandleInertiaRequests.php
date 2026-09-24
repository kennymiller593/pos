<?php

namespace App\Http\Middleware;

use App\Models\AperturaCaja;
use App\Models\Sucursal;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'ticket' => fn () => $request->session()->get('ticket'),
            ],
            'auth' => [
                'user' => function () use ($request) {
                    $usuario = $request->user();

                    if (! $usuario) {
                        return null;
                    }

                    $usuario->loadMissing(['empresa', 'sucursal', 'rol']);

                    // sucursal en la que esta operando: la de su caja abierta,
                    // o su sucursal predeterminada si aun no abre caja
                    $aperturaAbierta = AperturaCaja::query()
                        ->where('usuario_id', $usuario->id)
                        ->whereNull('cerrada_en')
                        ->with(['caja:id,nombre,sucursal_id', 'caja.sucursal:id,nombre'])
                        ->latest('abierta_en')
                        ->first();

                    // selector superior: sucursales a las que puede entrar y la elegida en sesion
                    $permitidas = $usuario->sucursalesPermitidas();
                    $accesibles = Sucursal::query()
                        ->where('empresa_id', $usuario->empresa_id)
                        ->where('activo', true)
                        ->when($permitidas, fn ($q) => $q->whereIn('id', $permitidas))
                        ->orderBy('nombre')
                        ->get(['id', 'nombre']);

                    $consultaId = $request->session()->get('sucursal_activa_id');
                    if ($consultaId && ! $accesibles->contains('id', $consultaId)) {
                        $consultaId = null;
                    }

                    return [
                        'id' => $usuario->id,
                        'nombre_completo' => $usuario->nombre_completo,
                        'email' => $usuario->email,
                        'rol' => $usuario->rol?->codigo,
                        'empresa' => [
                            'id' => $usuario->empresa->id,
                            'razon_social' => $usuario->empresa->razon_social,
                            'nombre_comercial' => $usuario->empresa->nombre_comercial,
                            'logo_url' => $usuario->empresa->logo_url,
                            'facturacion_electronica' => (bool) $usuario->empresa->facturacion_electronica,
                            'entorno_sunat' => $usuario->empresa->entorno_sunat,
                        ],
                        'sucursal' => $usuario->sucursal ? [
                            'id' => $usuario->sucursal->id,
                            'nombre' => $usuario->sucursal->nombre,
                        ] : null,
                        'sucursal_activa' => $aperturaAbierta?->caja?->sucursal ? [
                            'nombre' => $aperturaAbierta->caja->sucursal->nombre,
                            'caja' => $aperturaAbierta->caja->nombre,
                        ] : ($usuario->sucursal ? [
                            'nombre' => $usuario->sucursal->nombre,
                            'caja' => null,
                        ] : null),
                        'sucursales_accesibles' => $accesibles,
                        'sucursal_consulta_id' => $consultaId,
                        'puede_ver_todas' => $permitidas === null,
                    ];
                },
            ],
        ];
    }
}
