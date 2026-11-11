<?php

/*
 * Valorar la atención de un ticket resuelto o cerrado (P1). Cuándo tocarlo: al cambiar esta acción.
 * Permiso: TicketPolicy::rate (solo quien abrió el ticket, con el ticket en estado final). La lógica, en RateTicket.
 */

namespace App\Http\Controllers;

use App\Actions\Tickets\RateTicket;
use App\Http\Requests\StoreRatingRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;

class TicketRatingController extends Controller
{
    public function store(StoreRatingRequest $request, Ticket $ticket, RateTicket $rate): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $rate($user, $ticket, $request->integer('puntuacion'), $request->input('comentario'));

        return redirect()->route('tickets.show', $ticket)->with('status', 'Gracias por tu valoración.');
    }
}
