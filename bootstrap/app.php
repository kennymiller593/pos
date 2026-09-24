<?php

use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SuscripcionVigente;
use App\Http\Middleware\UsuarioActivo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware): void {
        // Solo el dominio de la app genera URLs absolutas (enlaces de correo, redirecciones): sin esto un
        // Host falso en la peticion envenena el enlace de recuperacion de contrasena. La lista se lee al
        // atender cada peticion (config() aun no existe aqui). No aplica en local ni en tests.
        $middleware->trustHosts(at: fn () => config('app.trusted_hosts'));

        // los proxies de confianza se configuran en AppServiceProvider por la misma razon
        $middleware->web(append: [
            CabecerasSeguridad::class,
            UsuarioActivo::class,
            SuscripcionVigente::class,
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Con Inertia, un 419 (sesion vencida, p. ej. la caja abierta toda la manana) o un error
        // del servidor no deben mostrar el HTML crudo de Laravel dentro de un modal.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $estado = $response->getStatusCode();

            if ($estado === 419) {
                return back()->with('error', 'Tu sesión expiró. Vuelve a intentarlo.');
            }

            if (! app()->environment(['local', 'testing']) && in_array($estado, [403, 404, 500, 503], true)) {
                return Inertia::render('Error', ['estado' => $estado])
                    ->toResponse($request)
                    ->setStatusCode($estado);
            }

            return $response;
        });
    })->create();
