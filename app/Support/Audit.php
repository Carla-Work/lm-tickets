<?php

/*
 * Anota un hecho en la auditoría. Es el ÚNICO sitio que escribe en `audit_logs`.
 * Cuándo tocarlo: casi nunca; para auditar algo nuevo se añade su clave a AuditLog::EVENTS y se llama aquí.
 *
 * Trampas:
 *  - Un fallo al auditar NO debe romper la acción (entrar, invitar…): se registra con report() y se sigue.
 *    El contrapeso es el test de cada hecho, que falla si deja de escribirse.
 *  - Nunca pases contraseñas, claves ni códigos en `$properties`.
 */

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Throwable;

class Audit
{
    /** @param  array<string, mixed>  $properties */
    public static function record(string $event, string $description, ?User $actor = null, ?User $subject = null, ?string $ip = null, array $properties = []): void
    {
        try {
            AuditLog::create([
                'event' => $event,
                'description' => $description,
                'actor_id' => $actor?->id,
                'subject_id' => $subject?->id,
                'ip' => $ip,
                'properties' => $properties === [] ? null : $properties,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
