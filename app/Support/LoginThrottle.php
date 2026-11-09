<?php

/*
 * Bloqueo por intentos fallidos de acceso (R20).
 * Cuándo tocarlo: al cambiar la política de bloqueo (los números están en config/access.php).
 *
 * Decisiones y trampas:
 *  - La clave es CORREO + IP, no solo el correo: si fuera solo el correo, cualquiera podría
 *    bloquear a un usuario legítimo a base de fallar adrede (denegación de servicio).
 *  - Cuenta TODOS los fallos, exista o no el correo: así el bloqueo no revela qué cuentas existen (R17).
 *  - Estando bloqueado se rechaza el intento ANTES de mirar la contraseña: aunque sea la correcta.
 */

namespace App\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Str;

class LoginThrottle
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public static function key(string $email, string $ip): string
    {
        return Str::transliterate(Str::lower($email).'|'.$ip);
    }

    public function isLocked(string $key): bool
    {
        return $this->limiter->tooManyAttempts($key, (int) config('access.login.max_attempts'));
    }

    public function secondsLeft(string $key): int
    {
        return $this->limiter->availableIn($key);
    }

    public function fail(string $key): void
    {
        $this->limiter->hit($key, (int) config('access.login.lockout_seconds'));
    }

    public function clear(string $key): void
    {
        $this->limiter->clear($key);
    }
}
