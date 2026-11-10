<?php

/*
 * Cambiar el departamento de un ticket (R12). Cuándo tocarlo: al cambiar quién puede reclasificar (se cambia
 * TicketPolicy::changeDepartment y su test, no esto).
 *
 * Trampa: tras mover un ticket a un departamento que no es de su equipo (p. ej. a Contabilidad) el AGENTE que lo hizo ya no puede verlo (R13): redirigir a
 * tickets.show daría 403. Se comprueba con la policy `view` sobre el ticket YA cambiado y, si no puede, va a
 * la bandeja con un mensaje que lo explica.
 */

namespace App\Http\Controllers;

use App\Actions\Tickets\ChangeDepartment;
use App\Http\Requests\ChangeDepartmentRequest;
use App\Models\Category;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class TicketDepartmentController extends Controller
{
    public function update(ChangeDepartmentRequest $request, Ticket $ticket, ChangeDepartment $change): RedirectResponse
    {
        Gate::authorize('changeDepartment', $ticket);
        $user = $request->user();
        abort_unless($user !== null, 403);

        // El departamento nuevo es el de la categoría elegida: no se recibe aparte (mockup E).
        $category = Category::findOrFail($request->integer('categoria'));
        $to = $category->department;
        $ticket = $change($user, $ticket, $to, $category);

        if (! $user->can('view', $ticket)) {
            return redirect()->route('inbox')->with('status', "Ticket #{$ticket->number} movido a «{$to->label()}». Ya no lo ves: lo atiende otro equipo.");
        }

        return redirect()->route('tickets.show', $ticket)->with('status', "Departamento cambiado a «{$to->label()}». Se avisará al cliente.");
    }
}
