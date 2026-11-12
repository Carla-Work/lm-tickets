<?php

/*
 * Registro de servicios de la aplicación. Cuándo tocarlo: al añadir un servicio.
 * Aquí vive el filtro global de autorización (Gate::before), ver docs/plan/autorizacion.md.
 */

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Inbound\ImapMailboxClient;
use App\Support\Inbound\MailboxClient;
use App\Support\MailLogger;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Un único MailLogger por proceso: recuerda qué correos ha abierto para marcarlos fallidos si la cola falla.
        $this->app->singleton(MailLogger::class);

        // Cómo se leen los buzones del correo entrante (P1). Los tests lo sustituyen por uno falso: ninguno habla con un servidor IMAP.
        $this->app->bind(MailboxClient::class, ImapMailboxClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Accesibilidad de los formularios: `@errorAttrs('campo')` dentro de un <input>/<select>/<textarea> escribe
        // aria-invalid y aria-describedby SOLO si ese campo tiene error, apuntando al <p class="error-campo" id="err-campo">
        // que lo explica. Sin ello un lector de pantalla no sabe qué campo falló. El id es SIEMPRE «err-» + nombre del
        // campo: si cambias uno de los dos lados, cambia el otro (lo vigila Accessibility/FieldErrorsTest).
        Blade::directive('errorAttrs', fn (string $campo): string => "<?php if (\$errors->has({$campo})) { echo 'aria-invalid=\"true\" aria-describedby=\"err-'.e({$campo}).'\"'; } ?>");

        // Antes de cualquier policy: una cuenta que NO es operativa (de baja, sin activar, o de una empresa
        // de baja) no puede nada, aunque tenga una sesión abierta de antes de la baja. Devolver null deja
        // que sigan las policies; devolver false deniega sin más. NUNCA devolver true aquí.
        Gate::before(function (User $usuario): ?bool {
            return $usuario->isOperational() ? null : false;
        });

        // «Registros» (correos, auditoría, copias): solo el administrador. Los registros llevan datos de
        // clientes y el cuerpo de los avisos; un agente no los necesita. Ver docs/plan/autorizacion.md.
        Gate::define('view-records', fn (User $usuario): bool => $usuario->hasRole(UserRole::Admin));

        // Informes (P1): todo el equipo (agentes y administrador), cada cual solo con lo que ve (R11, R13); el cliente, no. La tabla
        // «por agente» la limita TicketReport al administrador.
        Gate::define('view-reports', fn (User $usuario): bool => $usuario->isInternal());

        // Registro de correos: se abre antes de enviar y se cierra al confirmarse (ver App\Support\MailLogger).
        Event::listen(MessageSending::class, [MailLogger::class, 'sending']);
        Event::listen(MessageSent::class, [MailLogger::class, 'sent']);
        Event::listen(JobFailed::class, [MailLogger::class, 'failed']);
    }
}
