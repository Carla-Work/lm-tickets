<?php

/*
 * Cambiar el departamento de un ticket (R12, R13, R5).
 * Cuándo tocarla: al cambiar qué ocurre al reclasificar (avisos al cliente llegan en el paso 5).
 *
 * Trampas:
 *  - La categoría CUELGA del departamento (R6): al cambiar de departamento hay que elegir una categoría del
 *    nuevo; si se dejara la antigua, el ticket quedaría incoherente. Se valida aquí, no solo en el formulario.
 *  - Si la persona asignada NO puede atender el departamento nuevo (p. ej. un ticket de Soporte pasa a Contabilidad,
 *    que solo lleva su equipo, o al revés) la asignación se VACÍA (decidido 2026-11-10): sería un ticket con un
 *    responsable que ya no puede abrirlo. Queda su propio evento interno de asignación (`reason` = department_changed).
 *  - El cambio es visible al cliente (R12: «se avisa al cliente»); el correo llega en el paso 5.
 *  - Tras el cambio quien lo hizo puede haber perdido acceso (p. ej. un agente lo pasa a Contabilidad): el controlador
 *    lo tiene en cuenta.
 */

namespace App\Actions\Tickets;

use App\Enums\Department;
use App\Enums\TicketEventType;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Support\TicketNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangeDepartment
{
    public function __construct(private readonly TicketNotifier $notifier) {}

    public function __invoke(User $actor, Ticket $ticket, Department $to, Category $category): Ticket
    {
        $previous = null; // el departamento de ANTES, tomado del ticket bloqueado (no del que llegó, que puede estar desfasado)

        $ticket = DB::transaction(function () use ($actor, $ticket, $to, $category, &$previous): Ticket {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($actor)->authorize('changeDepartment', $ticket);

            $from = $previous = $ticket->department;
            if ($from === $to) {
                throw ValidationException::withMessages(['categoria' => 'El ticket ya está en ese departamento. Elige un tipo de otro departamento.']);
            }
            if ($category->department !== $to || ! $category->active) {
                throw ValidationException::withMessages(['categoria' => 'Elige una categoría activa del departamento nuevo.']);
            }

            $previousCategory = $ticket->category_id;
            $previousAssignee = $ticket->assigned_to === null ? null : User::find($ticket->assigned_to);

            $ticket->department = $to;
            $ticket->category_id = $category->id;
            $clearsAssignee = $previousAssignee !== null && ! $previousAssignee->canWorkOn($to);
            if ($clearsAssignee) {
                $ticket->assigned_to = null;
            }
            $ticket->last_activity_at = now();
            $ticket->save();

            $event = new TicketEvent;
            $event->ticket_id = $ticket->id;
            $event->actor_id = $actor->id;
            $event->type = TicketEventType::DepartmentChanged;
            $event->data = ['from' => $from->value, 'to' => $to->value, 'category_from' => $previousCategory, 'category_to' => $category->id];
            $event->visible_to_customer = true;
            $event->save();

            if ($clearsAssignee) {
                $unassigned = new TicketEvent;
                $unassigned->ticket_id = $ticket->id;
                $unassigned->actor_id = $actor->id;
                $unassigned->type = TicketEventType::Assigned;
                $unassigned->data = ['from' => $previousAssignee->id, 'from_name' => $previousAssignee->name, 'to' => null, 'to_name' => null, 'reason' => 'department_changed'];
                $unassigned->visible_to_customer = false;
                $unassigned->save();
            }

            return $ticket;
        });

        $this->notifier->departmentChanged($ticket, $actor, $previous); // después de la transacción

        return $ticket;
    }
}
