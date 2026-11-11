<?php

/*
 * Quién puede hacer qué sobre un ticket. LA matriz está en docs/plan/autorizacion.md; esto la implementa.
 * Cuándo tocarlo: solo si la usuaria cambia una regla (R1, R2, R4, R10-R13, R15). R10/R11/R13 revisadas el
 * 2026-11-10: «Otros» pasa a ser «Contabilidad», lo atiende un equipo propio y el administrador ya no tiene excepción. Cada cambio exige
 * cambiar la matriz del documento y el test de TicketActionsTest.
 *
 * Reglas de oro (no «simplificar» sin leer el documento):
 *  - Lo no permitido se deniega. Ante una duda sin decidir, lo más restrictivo.
 *  - Estas comprobaciones deben coincidir SIEMPRE con el ámbito Ticket::visibleTo (lo vigila
 *    TicketVisibilityTest): la policy responde por un ticket, el ámbito filtra las listas.
 *  - «Lo gestiona» = el agente ASIGNADO que puede atender ese departamento. El administrador nunca.
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
            UserRole::Agent, UserRole::Admin => $user->canWorkOn($ticket->department), // R11, R13: según equipo; el administrador, todo
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
            default => false,                               // R10: el administrador es de solo lectura (sin excepción desde 2026-11-10)
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
     * Reasignar (R11): cualquier agente, cualquier ticket que ve (también del otro equipo); el administrador, en
     * cualquiera (decidido). A QUIÉN se puede asignar lo decide AssignTicket (que pueda atender el departamento).
     * Bloqueado en estados finales.
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        if ($ticket->status->isFinal()) {
            return false;
        }

        return $user->hasRole(UserRole::Agent, UserRole::Admin) && $this->view($user, $ticket);
    }

    /**
     * Cambiar el departamento (R12): cualquier agente en lo que ve. El administrador NO (desde 2026-11-10 ve y
     * reasigna, como en todos los departamentos). Bloqueado en estados finales.
     */
    public function changeDepartment(User $user, Ticket $ticket): bool
    {
        if ($ticket->status->isFinal()) {
            return false;
        }

        return match ($user->getAttribute('role')) {
            UserRole::Agent => $this->view($user, $ticket),
            default => false,
        };
    }

    /**
     * Cambiar la prioridad INTERNA (P1): cualquier agente que atienda el departamento, como mover de departamento (R12).
     * El administrador NO (R10: ve y reasigna, no gestiona) y el cliente nunca la ve ni la toca. Bloqueado en finales.
     */
    public function changePriority(User $user, Ticket $ticket): bool
    {
        return $this->canClassify($user, $ticket);
    }

    /** Poner o quitar etiquetas internas (P1): mismas reglas que la prioridad. */
    public function changeTags(User $user, Ticket $ticket): bool
    {
        return $this->canClassify($user, $ticket);
    }

    private function canClassify(User $user, Ticket $ticket): bool
    {
        return ! $ticket->status->isFinal() && $user->hasRole(UserRole::Agent) && $this->view($user, $ticket);
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

    /** «Lo gestiona»: SOLO el agente asignado, y siempre que pueda atender el departamento. El administrador nunca (R10). */
    private function handles(User $user, Ticket $ticket): bool
    {
        return $user->hasRole(UserRole::Agent) && $ticket->assigned_to === $user->id && $user->canWorkOn($ticket->department);
    }
}
