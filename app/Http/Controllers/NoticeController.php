<?php

/*
 * «Avisos» (la campana): lista de lo que también llega por correo, abrir un aviso y «marcar todos como leídos».
 * Cuándo tocarlo: al cambiar la página de avisos. Mockups: avisos-a-cliente y avisos-b-interno.
 *
 * Trampas:
 *  - Un aviso solo se enseña (y solo cuenta) si la persona PUEDE VER todavía ese ticket (Ticket::visibleTo): si a un
 *    agente le quitan acceso (p. ej. el ticket pasa a «Otros», R13) o a un cliente lo dan de baja, el aviso antiguo no
 *    debe seguir enseñando el asunto (R1, R13).
 *  - `$user->notifications()` ya limita a SUS avisos: abrir el de otra persona da 404.
 *  - Abrir un aviso (GET) lo marca leído: es idempotente. Ver un ticket también marca sus avisos (TicketController).
 */

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NoticeController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        $user = $this->user($request);

        $notices = $user->visibleNotices()->latest()->paginate(self::PER_PAGE);

        return view('notices.index', [
            'user' => $user,
            'notices' => $notices,
            'unread' => $user->visibleNotices()->whereNull('read_at')->count(),
        ]);
    }

    public function open(Request $request, string $id): RedirectResponse
    {
        $user = $this->user($request);

        /** @var DatabaseNotification $notice */
        $notice = $user->notifications()->findOrFail($id);
        $notice->markAsRead();

        $ticket = Ticket::query()->find((int) ($notice->data['ticket_id'] ?? 0));
        if ($ticket === null || ! $user->can('view', $ticket)) {
            return redirect()->route('notices.index')->with('status', 'Ya no tienes acceso a ese ticket.');
        }

        return redirect()->route('tickets.show', $ticket);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return redirect()->route('notices.index')->with('status', 'Todos los avisos marcados como leídos.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        return $user;
    }
}
