<?php

/*
 * «Nuevo ticket para un cliente»: una persona del equipo abre un ticket a nombre de un cliente y lo asigna a un agente
 * (P1, 2026-11-12). Cuándo tocarlo: al cambiar esas pantallas. Mockups: ticket-nuevo-interno-a/b/c.
 * Plan: docs/plan/p1-ticket-creado-por-agente.md. La acción es CreateTicket (con StaffCreation).
 *
 * Trampas:
 *  - Dos pasos A PROPÓSITO (el paso 1 elige a la persona, el 2 el formulario): sin JavaScript el producto, que depende de la
 *    empresa, no se puede filtrar en una sola pantalla. El paso 2 solo enseña los productos de ESA empresa; el servidor lo
 *    vuelve a comprobar (StoreStaffTicketRequest, R6/R1).
 *  - La empresa sale del cliente elegido, nunca de un campo enviado (R1). Una persona inexistente, de baja o ajena a un cliente
 *    da el MISMO mensaje: no se delata qué cuentas existen (R17).
 *  - El agente solo ve los departamentos que atiende (el de Soporte o Desarrollo y Diseño no ve Contabilidad, R13).
 *  - El administrador crea y asigna (debe elegir agente: él no atiende, R10); el agente puede dejárselo a sí mismo.
 */

namespace App\Http\Controllers;

use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\StaffCreation;
use App\Enums\Department;
use App\Enums\TicketChannel;
use App\Enums\UserRole;
use App\Http\Requests\StoreStaffTicketRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StaffTicketController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        Gate::authorize('createForCustomer', Ticket::class);
        $actor = $request->user();
        abort_unless($actor !== null, 403);

        $customers = User::query()
            ->whereIn('role', [UserRole::CompanyUser->value, UserRole::CompanyAdmin->value])
            ->whereNotNull('activated_at')->whereNull('deactivated_at')
            ->whereHas('company', fn ($q) => $q->whereNull('deactivated_at'))
            ->with('company')->orderBy('name')->get();

        // Paso 1: sin persona elegida.
        if (! $request->filled('solicitante')) {
            return view('tickets.staff-who', ['user' => $actor, 'customers' => $customers->sortBy(fn (User $u) => $u->company?->name)->groupBy(fn (User $u) => $u->company?->name)]);
        }

        $customer = $customers->firstWhere('id', $request->integer('solicitante'));
        if ($customer === null) {
            return redirect()->route('tickets.staff.create')->withErrors(['solicitante' => 'Elige a una persona activa de una empresa activa.']);
        }

        $allowed = $actor->workableDepartments();
        $categories = Category::query()->offered()->whereIn('department', $allowed)->orderBy('name')->get()->groupBy(fn (Category $c) => $c->department->value);
        $agents = User::query()->where('role', UserRole::Agent->value)->whereNotNull('activated_at')->whereNull('deactivated_at')->orderBy('name')->get();

        return view('tickets.staff-create', [
            'user' => $actor, // lo pide el layout del panel
            'actor' => $actor,
            'customer' => $customer,
            'departments' => array_values(array_filter(Department::cases(), fn (Department $d) => in_array($d->value, $allowed, true))),
            'categories' => $categories,
            'products' => $customer->company?->products()->where('active', true)->orderBy('name')->get() ?? collect(),
            'channels' => TicketChannel::forStaff(),
            'agents' => $agents->groupBy(fn (User $u) => $u->team?->label()),
        ]);
    }

    public function store(StoreStaffTicketRequest $request, CreateTicket $createTicket): RedirectResponse
    {
        $actor = $request->user();
        $customer = $request->customer();
        abort_unless($actor !== null && $customer !== null, 403);

        // La validación ya garantizó categoría, producto y asignado coherentes con el cliente y el departamento; la acción
        // lo recomprueba con los datos releídos.
        $ticket = $createTicket(
            $customer,
            Department::from($request->string('department')->toString()),
            Category::findOrFail($request->integer('category_id')),
            $request->filled('product_id') ? Product::findOrFail($request->integer('product_id')) : null,
            $request->string('subject')->trim()->toString(),
            $request->string('description')->trim()->toString(),
            $request->file('adjuntos', []),
            null,
            new StaffCreation(
                $actor,
                TicketChannel::from($request->string('canal')->toString()),
                User::findOrFail($request->integer('asignado')),
                $request->boolean('notificar'),
            ),
        );

        return redirect()->route('tickets.show', $ticket)->with('status', "Ticket #{$ticket->number} creado a nombre de {$customer->name} y asignado a {$ticket->assignee?->name}.");
    }
}
