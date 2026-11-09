<?php

/*
 * Quién puede ver un mensaje del hilo. Regla R2: las notas internas son SOLO del equipo.
 * Cuándo tocarlo: nunca para «dejar que el cliente vea» una nota. Escribir en el hilo se autoriza con
 * TicketPolicy (reply / addNote); esta policy solo decide la VISIBILIDAD.
 */

namespace App\Policies;

use App\Enums\MessageKind;
use App\Models\TicketMessage;
use App\Models\User;

class TicketMessagePolicy
{
    public function view(User $user, TicketMessage $message): bool
    {
        if (! $user->can('view', $message->ticket)) {
            return false; // sin ver el ticket, no se ve ningún mensaje suyo
        }

        return $message->kind === MessageKind::Public || $user->can('viewInternal', $message->ticket);
    }
}
