<?php

/*
 * Cambiar la prioridad interna de un ticket y sus etiquetas (P1). Cuándo tocarlo: al cambiar esas acciones.
 * Permiso: TicketPolicy::changePriority / changeTags (agente que atiende el departamento; nunca administrador ni cliente).
 * Trampa: ninguna de las dos acciones avisa al cliente ni toca su lista; la lógica está en ChangePriority y ChangeTags.
 */

namespace App\Http\Controllers;

use App\Actions\Tickets\ChangePriority;
use App\Actions\Tickets\ChangeTags;
use App\Enums\Priority;
use App\Http\Requests\ChangePriorityRequest;
use App\Http\Requests\ChangeTagsRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;

class TicketPriorityController extends Controller
{
    public function priority(ChangePriorityRequest $request, Ticket $ticket, ChangePriority $change): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $to = Priority::from($request->string('prioridad')->toString());
        $change($user, $ticket, $to);

        return redirect()->route('tickets.show', $ticket)->with('status', "Prioridad cambiada a «{$to->label()}».");
    }

    public function tags(ChangeTagsRequest $request, Ticket $ticket, ChangeTags $change): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $ids = array_map('intval', (array) $request->input('etiquetas', []));
        $changed = $change($user, $ticket, $ids);

        return redirect()->route('tickets.show', $ticket)->with('status', $changed ? 'Etiquetas guardadas.' : 'No había cambios en las etiquetas.');
    }
}
