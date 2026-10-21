<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\VerificacionCorreoController;
use App\Services\IA\AsistenteIaService;
use App\Services\ImpersonacionService;
use App\Services\SuscripcionService;
use App\Support\ContextoSucursal;
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
            'appName' => config('app.name'),
            // WhatsApp de soporte de inkaPos (botón "Soporte" del menú)
            'soporteWhatsapp' => config('app.soporte_whatsapp'),
            // sesion "entrar como" del superadmin (franja superior con el boton para volver)
            'impersonacion' => fn () => app(ImpersonacionService::class)->activa($request),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'ticket' => fn () => $request->session()->get('ticket'),
                'venta' => fn () => $request->session()->get('venta'),
            ],
            'auth' => [
                'user' => function () use ($request) {
                    $usuario = $request->user();

                    if (! $usuario) {
                        return null;
                    }

                    $usuario->loadMissing(['empresa', 'sucursal', 'rol']);

                    // sucursal actual (selector de la barra superior): una sola noción para todo el sistema
                    $contexto = ContextoSucursal::de($request);

                    return [
                        'id' => $usuario->id,
                        'nombre_completo' => $usuario->nombre_completo,
                        'email' => $usuario->email,
                        'rol' => $usuario->rol?->codigo,
                        'permisos' => $usuario->permisos(),
                        'es_superadmin' => (bool) $usuario->es_superadmin,
                        'empresa' => [
                            'id' => $usuario->empresa->id,
                            'razon_social' => $usuario->empresa->razon_social,
                            'nombre_comercial' => $usuario->empresa->nombre_comercial,
                            'logo_url' => $usuario->empresa->logo_url,
                            'facturacion_electronica' => (bool) $usuario->empresa->facturacion_electronica,
                            'regimen_tributario' => $usuario->empresa->regimen_tributario,
                            'entorno_sunat' => $usuario->empresa->entorno_sunat,
                            // adicional de pago: decide si el menu muestra "Tienda en linea"
                            'tienda_habilitada' => (bool) $usuario->empresa->tienda_habilitada,
                            // adicional de pago: decide si el menu muestra "Asistente"
                            'ia_habilitada' => app(AsistenteIaService::class)->tieneAgentes($usuario->empresa),
                            // con más de una sucursal activa se muestran el selector y las columnas "Sucursal"
                            'multisucursal' => $contexto->multisucursal,
                        ],
                        'sucursal' => $usuario->sucursal ? [
                            'id' => $usuario->sucursal->id,
                            'nombre' => $usuario->sucursal->nombre,
                        ] : null,
                        ...$contexto->paraLayout(),
                        // estado del plan para el aviso de vencimiento en el layout
                        'suscripcion' => app(SuscripcionService::class)->resumen($usuario->empresa),
                        // aviso de "confirma tu correo" mientras dura la gracia
                        // recorrido guiado de bienvenida: se abre solo la primera vez que entra
                        'recorrido_pendiente' => ! $usuario->es_superadmin && $usuario->recorrido_visto_en === null,
                        'correo_verificado' => (bool) $usuario->email_verificado_en,
                        'dias_para_verificar' => $usuario->email_verificado_en || ! $usuario->creado_en
                            ? null
                            : max(0, (int) now()->startOfDay()->diffInDays($usuario->creado_en->copy()->addDays(VerificacionCorreoController::DIAS_GRACIA)->startOfDay(), false)),
                    ];
                },
            ],
        ];
    }
}
