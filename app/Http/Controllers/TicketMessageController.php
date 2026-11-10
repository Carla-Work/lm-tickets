<?php

/*
 * Escribir en un ticket: respuesta pública (cliente o equipo) y nota interna (solo el equipo, R2).
 * Cuándo tocarlo: al cambiar quién puede escribir (se cambia TicketPolicy::reply/addNote y su test, no esto).
 *
 * Trampa: aquí solo se autoriza y se redirige; las reglas (R4, R14) viven en ReplyToTicket, que las vuelve a
 * comprobar bajo bloqueo. El mensaje se lee de la petición validada, nunca de `$request->all()`.
 */

namespace App\Http\Controllers;

use App\Actions\Tickets\ReplyToTicket;
use App\Enums\MessageKind;
use App\Http\Requests\StoreMessageRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class TicketMessageController extends Controller
{
    public function store(StoreMessageRequest $request, Ticket $ticket, ReplyToTicket $reply): RedirectResponse
    {
        Gate::authorize('reply', $ticket);
        $user = $request->user();
        abort_unless($user !== null, 403);

        $reply($user, $ticket, trim($request->string('mensaje')->toString()), MessageKind::Public, $request->file('adjuntos', []));

        return redirect()->route('tickets.show', $ticket)->with('status', $user->isInternal()
            ? 'Respuesta enviada al cliente.'
            : 'Respuesta enviada. Te avisaremos cuando haya novedades.');
    }

    /** Nota interna: la ve solo el equipo (R2). Un cliente no llega aquí: addNote le deniega. */
    public function storeNote(StoreMessageRequest $request, Ticket $ticket, ReplyToTicket $reply): RedirectResponse
    {
        Gate::authorize('addNote', $ticket);
        $user = $request->user();
        abort_unless($user !== null, 403);

        // Las notas no llevan adjuntos (decidido): ReplyToTicket descarta cualquier archivo que llegue aquí.
        $reply($user, $ticket, trim($request->string('mensaje')->toString()), MessageKind::Internal);

        return redirect()->route('tickets.show', $ticket)->with('status', 'Nota guardada. Solo la ve el equipo de Ling Marco.');
    }
}
