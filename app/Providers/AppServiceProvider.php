<?php

namespace App\Providers;

use App\Models\Usuario;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Toda habilidad se resuelve contra la matriz de permisos por rol
        // (App\Support\Permisos); no hay Gates ni Policies sueltas.
        Gate::before(fn (Usuario $usuario, string $habilidad) => $usuario->puede($habilidad) ? true : null);

        // Detras de un balanceador o Cloudflare, la IP real y el esquema vienen en cabeceras X-Forwarded-*
        // (los hosts de confianza van en bootstrap/app.php)
        if (config('app.trusted_proxies')) {
            TrustProxies::at(config('app.trusted_proxies'));
        }

        // En produccion todo enlace generado es https (detras de un proxy el request puede llegar como http)
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        // Politica de contrasenas unica para registro, usuarios y recuperacion
        Password::defaults(function () {
            $regla = Password::min(8)->letters()->numbers();

            return $this->app->isProduction() ? $regla->uncompromised() : $regla;
        });

        // Login: 5 intentos por minuto por correo+IP (no solo por IP, para que un ataque
        // distribuido no pase y un vecino de red no bloquee a todos)
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
    }
}
