<?php

/*
 * Asignar, reasignar o dejar sin asignar un ticket, y «Asignármelo» (R11).
 * Cuándo tocarlo: al cambiar quién puede asignar (se cambia TicketPolicy::assign y su test, no esto).
 *
 * Trampa: el destino sale del formulario, pero que sea un agente en activo lo comprueba AssignTicket; aquí
 * un id inexistente da error de validación, no un 404 que delataría qué ids existen.
 */

namespace App\Http\Controllers;

use App\Actions\Tickets\AssignTicket;
use App\Http\Requests\AssignTicketRequest;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TicketAssignmentController extends Controller
{
    public function update(AssignTicketRequest $request, Ticket $ticket, AssignTicket $assign): RedirectResponse
    {
        Gate::authorize('assign', $ticket);
        $user = $request->user();
        abort_unless($user !== null, 403);

        $value = $request->string('asignado')->toString();
        $to = $value === 'sin' ? null : User::query()->find((int) $value);
        if ($value !== 'sin' && $to === null) {
            throw ValidationException::withMessages(['asignado' => 'Solo se puede asignar a un agente en activo.']);
        }

        $assign($user, $ticket, $to);

        return redirect()->route('tickets.show', $ticket)->with('status', $to === null
            ? 'El ticket queda sin asignar.'
            : ($to->is($user) ? 'Te has asignado el ticket.' : "Ticket asignado a {$to->name}."));
    }
}
