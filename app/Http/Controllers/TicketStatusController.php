<?php

/*
 * Cambios de estado: el cliente marca «resuelto» (R16); el equipo cambia estado, resuelve y cierra.
 * Cuándo tocarlo: al cambiar quién puede cambiar qué estado (se cambia TicketPolicy y su test, no esto).
 *
 * Trampa: el estado de destino del CLIENTE lo fija este código (Resuelto), no el formulario: si se leyera
 * del formulario, un cliente podría enviar «closed» o «in_progress» y saltarse las reglas del equipo.
 */

namespace App\Http\Controllers;

use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\TicketStatus;
use App\Http\Requests\ChangeStatusRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TicketStatusController extends Controller
{
    /** El cliente marca su ticket como resuelto, explicando cómo (R16). */
    public function resolve(ChangeStatusRequest $request, Ticket $ticket, ChangeTicketStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('markResolved', $ticket);
        $user = $request->user();
        abort_unless($user !== null, 403);

        $changeStatus($user, $ticket, TicketStatus::Resolved, 'markResolved', $request->input('solucion'));

        return redirect()->route('tickets.show', $ticket)->with('status', 'Ticket marcado como resuelto. Si el problema vuelve, abre uno nuevo.');
    }

    /**
     * El EQUIPO cambia el estado. La habilidad que se exige depende del destino (resolver y cerrar tienen la
     * suya): así «cerrar» no se concede por tener permiso para pasar a «En curso». Que el salto sea válido
     * (R15: nadie reabre) lo comprueba ChangeTicketStatus.
     */
    public function update(ChangeStatusRequest $request, Ticket $ticket, ChangeTicketStatus $changeStatus): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $user->isInternal(), 403);

        $to = $request->enum('estado', TicketStatus::class);
        if ($to === null) {
            throw ValidationException::withMessages(['estado' => 'Elige el estado al que pasar.']);
        }
        $ability = match ($to) {
            TicketStatus::Resolved => 'resolve',
            TicketStatus::Closed => 'close',
            default => 'updateStatus',
        };
        Gate::authorize($ability, $ticket);

        $changeStatus($user, $ticket, $to, $ability, $request->input('solucion'));

        return redirect()->route('tickets.show', $ticket)->with('status', "Estado cambiado a «{$to->label()}».");
    }
}
