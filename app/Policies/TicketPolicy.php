<?php

/*
 * Quién puede hacer qué sobre un ticket. LA matriz está en docs/plan/autorizacion.md; esto la implementa.
 * Cuándo tocarlo: solo si la usuaria cambia una regla (R1, R2, R4, R10-R13, R15). Cada cambio exige
 * cambiar la matriz del documento y el test de TicketActionsTest.
 *
 * Reglas de oro (no «simplificar» sin leer el documento):
 *  - Lo no permitido se deniega. Ante una duda sin decidir, lo más restrictivo.
 *  - Estas comprobaciones deben coincidir SIEMPRE con el ámbito Ticket::visibleTo (lo vigila
 *    TicketVisibilityTest): la policy responde por un ticket, el ámbito filtra las listas.
 *  - «Lo gestiona» = el agente ASIGNADO (ticket que no es de «Otros») o el administrador (solo «Otros»).
 *    Un agente no responde en un ticket ajeno o sin asignar sin asignárselo antes.
 *  - Los estados finales (Resuelto, Cerrado) cierran puertas, y NADIE reabre (R15).
 *
 * Las comprobaciones de cuenta dada de baja las hace Gate::before (AppServiceProvider), no cada método.
 */

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // quien esté autenticado ve SU lista; la filtra Ticket::visibleTo
    }

    /** R1, R11, R13: quién ve este ticket. */
    public function view(User $user, Ticket $ticket): bool
    {
        return match ($user->getAttribute('role')) {
            UserRole::CompanyUser => $ticket->company_id === $user->company_id && $ticket->requester_id === $user->id,
            UserRole::CompanyAdmin => $ticket->company_id === $user->company_id,
            UserRole::Agent => ! $ticket->isOther(),   // R13: los agentes no ven «Otros»
            UserRole::Admin => true,
            default => false,
        };
    }

    /** R2: ver notas internas, eventos internos y adjuntos de notas. Solo el equipo, en lo que ve. */
    public function viewInternal(User $user, Ticket $ticket): bool
    {
        return $user->isInternal() && $this->view($user, $ticket);
    }

    /** Abrir un ticket: solo clientes. (R6, el producto, lo valida el paso 4.) */
    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::CompanyUser, UserRole::CompanyAdmin);
    }

    /** Mensaje PÚBLICO (lo ve el cliente). */
    public function reply(User $user, Ticket $ticket): bool
    {
        if ($user->hasRole(UserRole::CompanyUser, UserRole::CompanyAdmin)) {
            // R4: en un ticket final el cliente no escribe. Y solo en los que abrió él (propuesta: el
            // administrador de empresa lee los de sus compañeros pero no responde por ellos).
            return $this->isRequester($user, $ticket) && ! $ticket->status->isFinal();
        }

        return $this->handles($user, $ticket) && $ticket->status !== TicketStatus::Closed;
    }

    /** Nota INTERNA (solo el equipo la ve, R2). */
    public function addNote(User $user, Ticket $ticket): bool
    {
        if ($ticket->status === TicketStatus::Closed) {
            return false; // «Cerrado» es final: nadie escribe nada más
        }

        return match ($user->getAttribute('role')) {
            UserRole::Agent => $this->view($user, $ticket), // decidido: notas en cualquier ticket que ve, aunque sea ajeno
            UserRole::Admin => $ticket->isOther(),          // R10: fuera de «Otros» es de solo lectura
            default => false,
        };
    }

    /** El CLIENTE marca su ticket como resuelto (R16: explicando cómo). */
    public function markResolved(User $user, Ticket $ticket): bool
    {
        return $user->hasRole(UserRole::CompanyUser, UserRole::CompanyAdmin)
            && $this->isRequester($user, $ticket)
            && ! $ticket->status->isFinal();
    }

    /** El EQUIPO pasa el ticket a Resuelto (R16: explicando cómo). */
    public function resolve(User $user, Ticket $ticket): bool
    {
        return $this->handles($user, $ticket) && ! $ticket->status->isFinal();
    }

    /** Cambios de estado intermedios (abierto, en curso, pendiente de cliente). */
    public function updateStatus(User $user, Ticket $ticket): bool
    {
        return $this->handles($user, $ticket) && ! $ticket->status->isFinal();
    }

    /** Resuelto → Cerrado. Lo hace quien lo gestiona (decidido: «lo cierra un agente»). */
    public function close(User $user, Ticket $ticket): bool
    {
        return $this->handles($user, $ticket) && $ticket->status === TicketStatus::Resolved;
    }

    /**
     * Reasignar (R11): cualquier agente, cualquier ticket que ve (también del otro equipo). El administrador
     * también puede (decidido) salvo en «Otros», que no tiene asignado. Bloqueado en estados finales.
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        if ($ticket->status->isFinal()) {
            return false;
        }

        return match ($user->getAttribute('role')) {
            UserRole::Agent => $this->view($user, $ticket),
            UserRole::Admin => ! $ticket->isOther(),
            default => false,
        };
    }

    /**
     * Cambiar el departamento (R12): cualquier agente en lo que ve; el administrador SOLO en «Otros»
     * (para sacar de ahí un ticket mal categorizado). Bloqueado en estados finales.
     */
    public function changeDepartment(User $user, Ticket $ticket): bool
    {
        if ($ticket->status->isFinal()) {
            return false;
        }

        return match ($user->getAttribute('role')) {
            UserRole::Agent => $this->view($user, $ticket),
            UserRole::Admin => $ticket->isOther(),
            default => false,
        };
    }

    /**
     * Abrir un ticket nuevo RELACIONADO con uno final (nadie reabre, R15). El usuario, con el suyo; el
     * administrador de empresa, con cualquiera de su empresa. Los empleados no: solo el cliente.
     */
    public function createRelated(User $user, Ticket $ticket): bool
    {
        if (! $ticket->status->isFinal()) {
            return false;
        }

        return match ($user->getAttribute('role')) {
            UserRole::CompanyUser => $this->isRequester($user, $ticket),
            UserRole::CompanyAdmin => $ticket->company_id === $user->company_id,
            default => false,
        };
    }

    private function isRequester(User $user, Ticket $ticket): bool
    {
        return $ticket->requester_id === $user->id && $ticket->company_id === $user->company_id;
    }

    /** «Lo gestiona»: el agente asignado (no «Otros») o el administrador (solo «Otros»). */
    private function handles(User $user, Ticket $ticket): bool
    {
        return match ($user->getAttribute('role')) {
            UserRole::Agent => ! $ticket->isOther() && $ticket->assigned_to === $user->id,
            UserRole::Admin => $ticket->isOther(),
            default => false,
        };
    }
}
