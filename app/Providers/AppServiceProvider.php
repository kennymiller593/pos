<?php

namespace App\Providers;

use App\Models\Usuario;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
    }
}
