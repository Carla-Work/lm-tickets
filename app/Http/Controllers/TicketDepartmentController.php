<?php

/*
 * Cambiar el departamento de un ticket (R12). Cuándo tocarlo: al cambiar quién puede reclasificar (se cambia
 * TicketPolicy::changeDepartment y su test, no esto).
 *
 * Trampa: tras pasar un ticket a «Otros» el AGENTE que lo hizo ya no puede verlo (R13): redirigir a
 * tickets.show daría 403. Se comprueba con la policy `view` sobre el ticket YA cambiado y, si no puede, va a
 * la bandeja con un mensaje que lo explica.
 */

namespace App\Http\Controllers;

use App\Actions\Tickets\ChangeDepartment;
use App\Enums\Department;
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

        $to = Department::from($request->string('departamento')->toString());
        $ticket = $change($user, $ticket, $to, Category::findOrFail($request->integer('categoria')));

        if (! $user->can('view', $ticket)) {
            return redirect()->route('inbox')->with('status', "Ticket #{$ticket->number} movido a «{$to->label()}». Ya no lo ves: lo gestiona el administrador.");
        }

        return redirect()->route('tickets.show', $ticket)->with('status', "Departamento cambiado a «{$to->label()}». Se avisará al cliente.");
    }
}
