<?php

/*
 * Registro de servicios de la aplicación. Cuándo tocarlo: al añadir un servicio.
 * Aquí vive el filtro global de autorización (Gate::before), ver docs/plan/autorizacion.md.
 */

namespace App\Providers;

use App\Models\User;
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
        // Antes de cualquier policy: una cuenta que NO es operativa (de baja, sin activar, o de una empresa
        // de baja) no puede nada, aunque tenga una sesión abierta de antes de la baja. Devolver null deja
        // que sigan las policies; devolver false deniega sin más. NUNCA devolver true aquí.
        Gate::before(function (User $usuario): ?bool {
            return $usuario->isOperational() ? null : false;
        });
    }
}
