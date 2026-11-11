<?php

/*
 * «Bandeja de tickets» del equipo de Ling Marco (agentes y administrador).
 * Cuándo tocarlo: al cambiar la bandeja. Mockups validados: bandeja-a-lista-tabla.html y bandeja-b-filtros-avanzados.html
 * (filtros y búsqueda: App\Support\TicketFilters).
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
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user !== null && $user->isInternal() && $user->isOperational(), 403);

        // Reglas y consulta de filtros: UN solo sitio (TicketFilters), compartido con «Mis tickets». Ver sus trampas (R2, R13).
        $filters = $request->validate(TicketFilters::rules(team: true));

        $base = Ticket::query()->visibleTo($user);
        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $open = TicketStatus::Open->value;
        $inProgress = TicketStatus::InProgress->value;
        $pending = TicketStatus::PendingCustomer->value;

        $query = TicketFilters::apply((clone $base)->with(['product', 'requester.company', 'assignee']), $filters, $user);

        // Las listas de los selectores salen del ámbito de esta persona (R13): un agente de otro equipo no ve ni
        // los productos ni las categorías ni las empresas de lo que no puede ver.
        $companies = Company::query()->whereIn('id', (clone $base)->select('company_id'))->orderBy('name')->get(['id', 'name']);
        $products = Product::query()->whereIn('id', (clone $base)->select('product_id'))->orderBy('name')->get(['id', 'name']);
        $categories = Category::query()->whereIn('department', $user->workableDepartments())->orderBy('name')->get(['id', 'name', 'department'])
            ->groupBy(fn (Category $c) => $c->department->value);
        $staff = User::query()->whereIn('role', [UserRole::Agent->value, UserRole::Admin->value])
            ->whereNotNull('activated_at')->whereNull('deactivated_at')->orderBy('name')->get();

        $names = [
            'empresa' => fn (string $id) => $companies->firstWhere('id', (int) $id)?->name,
            'asignado' => fn (string $id) => $staff->firstWhere('id', (int) $id)?->name,
            'producto' => fn (string $id) => $products->firstWhere('id', (int) $id)?->name,
            'categoria' => fn (string $id) => $categories->flatten()->firstWhere('id', (int) $id)?->name,
        ];

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
            'companies' => $companies,
            'products' => $products,
            'categories' => $categories,
            'staff' => $staff,
            'active' => TicketFilters::active($request, 'inbox', $filters, $names, customer: false),
            'quickViews' => TicketFilters::quickViews($request, 'inbox', [
                ['label' => 'Todos', 'params' => []],
                ['label' => 'Mis tickets', 'params' => ['asignado' => $user->id, 'vivos' => 1]],
                ['label' => 'Sin asignar', 'params' => ['asignado' => TicketFilters::UNASSIGNED, 'vivos' => 1]],
                ['label' => 'Sin actividad en 7 días', 'params' => ['quieta' => 7]],
            ]),
        ]);
    }
}
