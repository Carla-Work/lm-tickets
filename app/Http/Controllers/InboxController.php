<?php

/*
 * «Bandeja de tickets» del equipo de Ling Marco (agentes y administrador).
 * Cuándo tocarlo: al cambiar la bandeja. Mockup validado: bandeja-a-lista-tabla.html.
 *
 * Trampas:
 *  - Toda la lista parte de Ticket::visibleTo($usuario) (R1, R11, R13): un agente de otro equipo NO ve
 *    Contabilidad por ninguna vía (y el de Contabilidad solo ve Contabilidad). Por eso también los recuentos, el selector de empresas y el de departamentos salen de
 *    ese mismo ámbito: un `Company::all()` le contaría a un agente qué empresas tienen tickets de un departamento que no ve.
 *  - Solo entra el equipo. Un cliente que escriba /bandeja recibe 403 (su lista es /tickets).
 *  - Fuera de aquí, a propósito: la marca «Respuesta del cliente» y la campana de avisos necesitan saber
 *    qué ha leído cada persona (paso 5); el detalle interno con acciones (responder, estado, asignar)
 *    es el paso 4c.
 */

namespace App\Http\Controllers;

use App\Enums\Department;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    private const PER_PAGE = 10;

    /** Valor del filtro «Asignado a» para los tickets sin persona asignada. */
    public const UNASSIGNED = 'sin';

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user !== null && $user->isInternal() && $user->isOperational(), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'string', 'in:'.implode(',', array_column(TicketStatus::cases(), 'value'))],
            'departamento' => ['nullable', 'string', 'in:'.implode(',', array_column(Department::cases(), 'value'))],
            'empresa' => ['nullable', 'integer'],
            'asignado' => ['nullable', 'string', 'regex:/^(sin|\d+)$/'],
        ]);

        $base = Ticket::query()->visibleTo($user);
        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $open = TicketStatus::Open->value;
        $inProgress = TicketStatus::InProgress->value;
        $pending = TicketStatus::PendingCustomer->value;

        $query = (clone $base)->with(['product', 'requester.company', 'assignee'])
            ->when($filters['estado'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['departamento'] ?? null, fn ($q, $v) => $q->where('department', $v))
            ->when($filters['empresa'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when($filters['asignado'] ?? null, fn ($q, $v) => $v === self::UNASSIGNED
                ? $q->whereNull('assigned_to')
                : $q->where('assigned_to', (int) $v))
            ->when($filters['q'] ?? null, function ($q, $v) {
                // Asunto, número (2026-0312, con o sin «#»), persona o empresa. `addcslashes` evita que % y _ sean comodines.
                $like = '%'.addcslashes(ltrim($v, '#'), '%_\\').'%';
                $q->where(fn ($w) => $w->where('subject', 'like', $like)
                    ->orWhereRaw("CONCAT(year, '-', LPAD(sequence, 4, '0')) like ?", [$like])
                    ->orWhereHas('requester', fn ($r) => $r->where('name', 'like', $like))
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $like)));
            })
            ->orderByDesc('last_activity_at')->orderByDesc('id');

        return view('inbox.index', [
            'user' => $user,
            'tickets' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'total' => (clone $base)->count(),
            'summary' => [
                // «Sin asignar» solo cuenta lo vivo: un ticket resuelto o cerrado sin persona ya no pide atención.
                'unassigned' => (clone $base)->whereNull('assigned_to')->whereIn('status', [$open, $inProgress, $pending])->count(),
                'open' => (int) ($counts[$open] ?? 0),
                'in_progress' => (int) ($counts[$inProgress] ?? 0),
                'pending' => (int) ($counts[$pending] ?? 0),
                'finished' => (int) (($counts[TicketStatus::Resolved->value] ?? 0) + ($counts[TicketStatus::Closed->value] ?? 0)),
            ],
            'filters' => $filters,
            'unread' => $user->unreadNotifications()->get(['data'])
                ->groupBy(fn ($n) => (int) ($n->data['ticket_id'] ?? 0))
                ->map(fn ($group) => $group->pluck('data.kind')->all())->all(),
            // Solo los departamentos que este empleado ve (R11, R13): un agente de Contabilidad solo Contabilidad, el resto sin ella.
            'departments' => collect(Department::cases())
                ->filter(fn (Department $d) => $user->canWorkOn($d))
                ->values()->all(),
            'companies' => Company::query()->whereIn('id', (clone $base)->select('company_id'))->orderBy('name')->get(['id', 'name']),
            'staff' => User::query()->whereIn('role', [UserRole::Agent->value, UserRole::Admin->value])
                ->whereNotNull('activated_at')->whereNull('deactivated_at')->orderBy('name')->get(),
        ]);
    }
}
