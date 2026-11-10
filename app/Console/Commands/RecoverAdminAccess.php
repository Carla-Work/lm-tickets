<?php

/*
 * Recuperar el acceso del administrador desde el SERVIDOR (R9, R21): `php artisan admin:recover`.
 * Cuándo tocarlo: al cambiar cómo se recupera el acceso. Procedimiento completo: docs/plan/recuperacion-admin.md.
 *
 * Para qué sirve: solo hay un administrador. Si pierde la contraseña y el correo de recuperación no le llega, o
 * pierde el móvil del 2FA y los códigos de recuperación, nadie podría gestionar cuentas. Esto es la salida de
 * último recurso y exige acceso al servidor (la persona que puede ejecutarlo ya controla la base de datos).
 *
 * Qué hace y qué NO hace:
 *  - NO fija ni muestra ninguna contraseña. Imprime un enlace de recuperación de UN SOLO USO, el mismo que
 *    enviaría «he olvidado mi contraseña» (caduca en 60 min): el administrador elige su contraseña él mismo.
 *  - `--disable-2fa` borra el segundo factor (clave y códigos): sin él, tras la contraseña seguiría pidiendo código.
 *  - `--reactivate` quita la baja si la cuenta estuviera de baja (la aplicación no lo permite, solo una edición
 *    directa de la base de datos); sin reactivar, el enlace no serviría.
 *  - Solo actúa sobre una cuenta con rol administrador. No puede crear administradores ni ascender a nadie
 *    (para eso está el traspaso, R9).
 *  - Deja una fila en la auditoría (`admin_recovered`, sin actor) con lo que se hizo, nunca el enlace.
 *
 * Trampa: el enlace sale por la consola, que puede quedar en un historial o en un registro; es de un solo uso y
 * caduca, pero conviene usarlo enseguida.
 */

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

class RecoverAdminAccess extends Command
{
    protected $signature = 'admin:recover
        {--email= : Correo del administrador (si se omite, el administrador en activo)}
        {--disable-2fa : Borrar también el segundo factor (clave y códigos de recuperación)}
        {--reactivate : Quitar la baja si la cuenta está de baja}
        {--force : No pedir confirmación}';

    protected $description = 'Recupera el acceso del administrador: imprime un enlace de un solo uso para elegir contraseña nueva';

    public function handle(): int
    {
        $admin = $this->findAdmin();
        if ($admin === null) {
            return self::FAILURE;
        }

        $this->line("Administrador: {$admin->name} <{$admin->email}>");
        $this->line('Segundo factor: '.($admin->hasTwoFactorEnabled() ? 'ACTIVADO' : 'no activado'));
        $this->line('Estado: '.($admin->isDeactivated() ? 'DE BAJA' : 'en activo'));

        if ($admin->isDeactivated() && ! $this->option('reactivate')) {
            $this->error('La cuenta está de baja: el enlace no serviría. Repite con --reactivate si quieres reactivarla.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('¿Generar el enlace de recuperación'.($this->option('disable-2fa') ? ' y borrar el segundo factor' : '').'?')) {
            $this->warn('Cancelado. No se ha cambiado nada.');

            return self::FAILURE;
        }

        $done = ['enlace de recuperación generado'];
        if ($admin->isDeactivated()) {
            $admin->forceFill(['deactivated_at' => null])->save();
            $done[] = 'cuenta reactivada';
        }
        if ($this->option('disable-2fa') && $admin->hasTwoFactorEnabled()) {
            $admin->clearTwoFactor();
            $done[] = 'segundo factor borrado';
        }

        $token = Password::broker('users')->createToken($admin);
        Audit::record('admin_recovered', "Recuperación del acceso de {$admin->email} desde el servidor: ".implode(', ', $done).'.', null, $admin);

        $this->newLine();
        $this->info('Enlace de un solo uso (caduca en '.config('auth.passwords.users.expire').' minutos):');
        $this->line(route('password.reset', ['token' => $token, 'email' => $admin->email]));

        return self::SUCCESS;
    }

    private function findAdmin(): ?User
    {
        $query = User::query()->where('role', UserRole::Admin->value);

        if ($email = $this->option('email')) {
            $admin = $query->where('email', (string) $email)->first();
            if ($admin === null) {
                $this->error('No hay ningún administrador con ese correo.');
            }

            return $admin;
        }

        $admins = $query->whereNull('deactivated_at')->get();
        if ($admins->count() !== 1) {
            $this->error($admins->isEmpty()
                ? 'No hay ningún administrador en activo. Indica el correo con --email=...'
                : 'Hay varios administradores en activo (no debería ocurrir, R9). Indica el correo con --email=...');

            return null;
        }

        return $admins->first();
    }
}
