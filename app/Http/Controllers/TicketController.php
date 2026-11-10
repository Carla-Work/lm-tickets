<?php

/*
 * Pantallas del cliente: «Mis tickets», «Nuevo ticket» y el detalle de un ticket.
 * Cuándo tocarlo: al cambiar esas pantallas. Mockups: mis-tickets-a/b, nuevo-ticket-a, ticket-detalle-cliente-a/b.
 *
 * Trampas:
 *  - Toda lista parte de Ticket::visibleTo($usuario) (R1); un `Ticket::query()` suelto entregaría tickets
 *    ajenos. El detalle usa authorize('view') y cuenta lo mismo: un id ajeno da 403.
 *  - El hilo se lee con TicketMessage/TicketEvent::visibleTo: así las notas internas y los eventos
 *    internos no llegan a la vista del cliente (R2), aunque la plantilla se equivoque.
 *  - Los tickets de un agente o del administrador (bandeja) llegan en el paso 4b; aquí solo clientes.
 */

namespace App\Http\Controllers;

use App\Actions\Tickets\CreateTicket;
use App\Enums\Department;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Http\Requests\StoreTicketRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TicketController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'string', 'in:'.implode(',', array_column(TicketStatus::cases(), 'value'))],
            'departamento' => ['nullable', 'string', 'in:'.implode(',', array_column(Department::cases(), 'value'))],
            'producto' => ['nullable', 'integer'],
            'usuario' => ['nullable', 'integer'],
        ]);

        $base = Ticket::query()->visibleTo($user);
        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $query = (clone $base)->with(['product', 'requester'])
            ->when($filters['estado'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['departamento'] ?? null, fn ($q, $v) => $q->where('department', $v))
            ->when($filters['producto'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            // «Abierto por» solo tiene sentido (y solo se aplica) al administrador de empresa; para un usuario
            // normal ya está limitado a los suyos por visibleTo.
            ->when($filters['usuario'] ?? null, fn ($q, $v) => $q->where('requester_id', $v))
            ->when($filters['q'] ?? null, function ($q, $v) {
                // Busca por asunto o por número (2026-0312, con o sin «#»). `escape` evita que % y _ del usuario sean comodines.
                $like = '%'.addcslashes(ltrim($v, '#'), '%_\\').'%';
                $q->where(fn ($w) => $w->where('subject', 'like', $like)
                    ->orWhereRaw("CONCAT(year, '-', LPAD(sequence, 4, '0')) like ?", [$like]));
            })
            ->orderByDesc('last_activity_at')->orderByDesc('id');

        $company = $user->company;

        return view('tickets.index', [
            'user' => $user,
            'tickets' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'total' => (clone $base)->count(),
            'summary' => [
                'pending' => (int) ($counts[TicketStatus::PendingCustomer->value] ?? 0),
                'open' => (int) ($counts[TicketStatus::Open->value] ?? 0),
                'in_progress' => (int) ($counts[TicketStatus::InProgress->value] ?? 0),
                'finished' => (int) (($counts[TicketStatus::Resolved->value] ?? 0) + ($counts[TicketStatus::Closed->value] ?? 0)),
            ],
            'filters' => $filters,
            'products' => $company?->products()->orderBy('name')->get() ?? collect(),
            'requesters' => $company !== null && $user->hasRole(UserRole::CompanyAdmin)
                ? $company->users()->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Ticket::class);
        $user = $request->user();
        abort_unless($user !== null, 403);

        return view('tickets.create', [
            'user' => $user,
            'categories' => Category::query()->where('active', true)->orderBy('name')->get()->groupBy(fn (Category $c) => $c->department->value),
            'products' => $user->company?->products()->where('active', true)->orderBy('name')->get() ?? collect(),
        ]);
    }

    public function store(StoreTicketRequest $request, CreateTicket $createTicket): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        // La validación (R6) ya garantizó que categoría y producto existen y son coherentes con el solicitante.
        $ticket = $createTicket(
            $user,
            Department::from($request->string('department')->toString()),
            Category::findOrFail($request->integer('category_id')),
            $request->filled('product_id') ? Product::findOrFail($request->integer('product_id')) : null,
            $request->string('subject')->trim()->toString(),
            $request->string('description')->trim()->toString(),
        );

        return redirect()->route('tickets.show', $ticket)->with('status', "Ticket #{$ticket->number} enviado. Te avisaremos cuando haya respuesta.");
    }

    public function show(Request $request, Ticket $ticket): View
    {
        Gate::authorize('view', $ticket);
        $user = $request->user();
        abort_unless($user !== null, 403);

        $ticket->load(['product', 'requester.company', 'assignee']);

        // Mensajes y eventos mezclados por fecha: así el hilo cuenta la historia en orden (mockup).
        $thread = TicketMessage::query()->visibleTo($user)->where('ticket_id', $ticket->id)->with('author')->get()
            ->toBase()->concat(TicketEvent::query()->visibleTo($user)->where('ticket_id', $ticket->id)->get())
            ->sortBy(fn ($item) => [$item->created_at?->getTimestamp(), $item instanceof TicketMessage ? 0 : 1, $item->id])
            ->values();

        return view('tickets.show', ['user' => $user, 'ticket' => $ticket, 'thread' => $thread]);
    }
}
