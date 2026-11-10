<?php

/*
 * Decide QUIÉN recibe aviso de cada cosa que pasa en un ticket y con qué texto (paso 5). Es el único sitio.
 * Cuándo tocarlo: al cambiar a quién se avisa o cómo se redacta. Las acciones (CreateTicket, ReplyToTicket,
 * ChangeTicketStatus, AssignTicket, ChangeDepartment) lo llaman DESPUÉS de su transacción.
 *
 * Reglas (decididas con la usuaria el 2026-11-10, salvo lo marcado «propuesta»):
 *  - Al cliente se le avisa a SU solicitante, no a los administradores de su empresa ni a compañeros.
 *  - Nunca se avisa a quien hizo la acción (salvo la confirmación «Hemos recibido tu ticket», que es para él), ni a
 *    cuentas no operativas (de baja, empresa de baja).
 *  - Las NOTAS INTERNAS no avisan a nadie (R2): ningún método de aquí se llama al escribir una nota.
 *  - El cambio automático de estado cuando el cliente responde (R14) no avisa: el aviso ya es el de la respuesta.
 *  - «Ticket sin asignar» va al equipo del departamento (propuesta): Soporte → equipo Soporte; Aplicaciones y
 *    Páginas web → equipo Desarrollo y Diseño; Contabilidad → equipo Contabilidad (el administrador no recibe estos
 *    avisos: ve todos los tickets en su bandeja).
 *  - El cliente que responde avisa a quien lo lleva; si nadie, al equipo del departamento.
 *  - Que el cliente marque «resuelto» avisa a quien lo lleva para que lo cierre (propuesta de Claude, no está en el mockup).
 *  - Un fallo al avisar (cola, correo) se registra con report() y NO deshace ni rompe la acción ya guardada.
 */

namespace App\Support;

use App\Enums\Department;
use App\Enums\TicketNoticeKind;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketNotice;
use Illuminate\Support\Collection;

class TicketNotifier
{
    /** «Hemos recibido tu ticket» al solicitante + aviso al equipo de que hay uno sin asignar. */
    public function created(Ticket $ticket): void
    {
        $requester = $ticket->requester;
        // Sin «actor»: la confirmación es PARA quien abre el ticket, justo la excepción a «no avisar a quien actúa».
        $this->send([$requester], null, TicketNoticeKind::Received, $ticket,
            "Hemos recibido tu ticket #{$ticket->number}", $ticket->subject);

        $this->unassignedToTeam($ticket, $requester);
    }

    /** Mensaje PÚBLICO nuevo: del equipo → al solicitante; del cliente → a quien lo lleva (o al equipo). */
    public function replied(Ticket $ticket, User $author): void
    {
        if ($author->isInternal()) {
            $this->send([$ticket->requester], $author, TicketNoticeKind::Replied, $ticket,
                "{$author->name} ha respondido en #{$ticket->number}", $ticket->subject);

            return;
        }

        $this->send($this->handlersOrTeam($ticket), $author, TicketNoticeKind::ClientReplied, $ticket,
            "Respuesta del cliente en #{$ticket->number}", "{$ticket->subject} · {$author->name}");
    }

    /** Cambio de estado hecho por el EQUIPO → al solicitante; el cliente que resuelve → a quien lo lleva. */
    public function statusChanged(Ticket $ticket, User $actor, TicketStatus $to): void
    {
        if (! $actor->isInternal()) {
            if ($to === TicketStatus::Resolved) {
                $this->send($this->handlersOrTeam($ticket), $actor, TicketNoticeKind::ClientResolved, $ticket,
                    "El cliente ha marcado como resuelto el #{$ticket->number}", "{$ticket->subject} · {$actor->name}");
            }

            return;
        }

        $title = match ($to) {
            TicketStatus::InProgress => "Tu ticket #{$ticket->number} está en curso",
            TicketStatus::PendingCustomer => "Tu ticket #{$ticket->number} espera tu respuesta",
            TicketStatus::Resolved => "Tu ticket #{$ticket->number} está resuelto",
            TicketStatus::Closed => "Tu ticket #{$ticket->number} está cerrado",
            TicketStatus::Open => "Tu ticket #{$ticket->number} vuelve a estar abierto",
        };
        $this->send([$ticket->requester], $actor, TicketNoticeKind::StatusChanged, $ticket, $title, $ticket->subject);
    }

    /** R12: el cliente recibe aviso del cambio de departamento. Si el ticket queda sin asignar, se avisa al equipo nuevo. */
    public function departmentChanged(Ticket $ticket, User $actor, Department $from): void
    {
        $this->send([$ticket->requester], $actor, TicketNoticeKind::DepartmentChanged, $ticket,
            "El equipo ha cambiado el departamento de #{$ticket->number}",
            "De {$from->label()} a {$ticket->department->label()}");

        if ($ticket->assigned_to === null) {
            $this->unassignedToTeam($ticket, $actor);
        }
    }

    /** «Te han asignado»: solo a quien lo recibe, y no si se lo asigna él mismo. */
    public function assigned(Ticket $ticket, User $actor, ?User $to): void
    {
        if ($to === null) {
            return;
        }
        $this->send([$to], $actor, TicketNoticeKind::Assigned, $ticket,
            "{$actor->name} te ha asignado el #{$ticket->number}", "{$ticket->subject} · {$ticket->requester->company?->name}");
    }

    // ------------------------------------------------------------------

    private function unassignedToTeam(Ticket $ticket, User $actor): void
    {
        $this->send($this->teamFor($ticket->department), $actor, TicketNoticeKind::NewUnassigned, $ticket,
            "Ticket nuevo sin asignar: #{$ticket->number}",
            "{$ticket->subject} · {$ticket->requester->company?->name} · {$ticket->department->label()}");
    }

    /** @return Collection<int, User> */
    private function handlersOrTeam(Ticket $ticket): Collection
    {
        if ($ticket->assigned_to !== null) {
            return User::query()->whereKey($ticket->assigned_to)->get();
        }

        return $this->teamFor($ticket->department);
    }

    /**
     * Agentes del equipo que atiende ese departamento (Contabilidad tiene el suyo; el administrador no recibe estos avisos:
     * ve todos los tickets en su bandeja).
     *
     * @return Collection<int, User>
     */
    private function teamFor(Department $department): Collection
    {
        $query = User::query()->whereNotNull('activated_at')->whereNull('deactivated_at');

        return $query->where('role', UserRole::Agent->value)->where('team', $department->team()->value)->get();
    }

    /**
     * @param  iterable<User>  $recipients
     */
    private function send(iterable $recipients, ?User $actor, TicketNoticeKind $kind, Ticket $ticket, string $title, string $detail): void
    {
        $notice = new TicketNotice($kind, $ticket->id, $title, $detail);
        $seen = [];

        foreach ($recipients as $recipient) {
            // Sin duplicados, sin avisar a quien actúa y sin cuentas que no pueden entrar (de baja, empresa de baja).
            if (isset($seen[$recipient->id]) || ($actor !== null && $recipient->is($actor)) || ! $recipient->isOperational()) {
                continue;
            }
            $seen[$recipient->id] = true;
            try {
                $recipient->notify($notice);
            } catch (\Throwable $e) {
                // El ticket YA está guardado: que falle la cola o el correo no debe presentarse como «no se pudo
                // enviar tu mensaje» (el usuario lo reenviaría y duplicaría). Se registra para poder arreglarlo.
                report($e);
            }
        }
    }
}
