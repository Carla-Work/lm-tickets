<?php

/*
 * «Correo entrante» (Administración, solo el administrador): cuarentena de los correos que no crearon un ticket solos (P1).
 * Cuándo tocarlo: al cambiar estas pantallas. Mockups validados: correo-cuarentena-a-lista y correo-cuarentena-b-detalle.
 * Plan: docs/plan/p1-email-entrante.md. La lógica del filtro está en ProcessIncomingEmail; las decisiones en ResolveInboundEmail.
 *
 * Trampas:
 *  - El texto del correo lo escribe un remitente desconocido: se pinta SIEMPRE escapado y como texto plano, nunca como HTML.
 *  - El administrador NO responde al remitente (R10): invita, crea el ticket o marca spam. Nada de enviar correos libres desde aquí.
 *  - Los adjuntos de la cuarentena no se descargan desde la web (R3: solo con permiso y por controlador); se listan, y pasan al ticket al crearlo.
 *  - Cada acción vuelve a autorizarse con la policy (`decide`: solo en cuarentena).
 */

namespace App\Http\Controllers\Admin;

use App\Actions\Inbound\ResolveInboundEmail;
use App\Actions\Users\InviteUser;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateTicketFromInboundRequest;
use App\Http\Requests\Admin\InviteFromInboundRequest;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyDomain;
use App\Models\InboundEmail;
use App\Models\Product;
use App\Models\User;
use App\Support\Inbound\KeywordMatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InboundEmailController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', InboundEmail::class);

        return view('admin.inbound.index', [
            'user' => $request->user(),
            'pending' => InboundEmail::query()->where('status', InboundEmail::PENDING)->orderByDesc('received_at')->get(),
            'spam' => InboundEmail::query()->where('status', InboundEmail::SPAM)->orderByDesc('received_at')->get(),
            'ticketsThisWeek' => InboundEmail::query()->where('status', InboundEmail::TICKET)->where('created_at', '>=', now()->subDays(7))->count(),
            'enabled' => (bool) config('inbound.enabled'),
        ]);
    }

    public function show(Request $request, InboundEmail $inboundEmail): View
    {
        Gate::authorize('view', $inboundEmail);

        $department = $inboundEmail->department();
        $domain = CompanyDomain::query()->with('company')->where('domain', strtolower(substr(strrchr($inboundEmail->from_email, '@') ?: '', 1)))->first();
        $suggested = $domain?->company?->isActive() ? $domain->company : null;
        $known = User::query()->whereRaw('lower(email) = ?', [strtolower($inboundEmail->from_email)])->first();

        // Personas a elegir: la persona conocida del remitente primero, y las de la empresa sugerida; si no hay ninguna, todas las activas (agrupadas por empresa).
        $customers = User::query()->whereIn('role', [UserRole::CompanyUser->value, UserRole::CompanyAdmin->value])
            ->whereNotNull('activated_at')->whereNull('deactivated_at')
            ->whereHas('company', fn ($q) => $q->whereNull('deactivated_at'))->with('company')->orderBy('name')->get();

        $suggestedCustomerId = $known->id ?? $customers->firstWhere('company_id', $suggested?->id)?->id;
        $companyIdForProducts = ($customers->firstWhere('id', $suggestedCustomerId))?->company_id;
        $products = $companyIdForProducts === null ? collect() : (Company::find($companyIdForProducts)?->products()->where('active', true)->orderBy('name')->get() ?? collect());
        $keywordMatches = (new KeywordMatcher)->matches($inboundEmail->subject."\n".($inboundEmail->body ?? ''));

        return view('admin.inbound.show', [
            'user' => $request->user(),
            'email' => $inboundEmail->load('attachments'),
            'department' => $department,
            'suggestedCompany' => $suggested,
            'knownUser' => $known,
            'customers' => $customers->groupBy(fn (User $u) => $u->company?->name),
            'suggestedCustomerId' => $suggestedCustomerId,
            'categories' => Category::query()->where('active', true)->where('department', $department->value ?? '')->orderBy('name')->get(),
            'products' => $products,
            'suggestedProductId' => collect(array_keys($keywordMatches))->first(fn ($id) => $products->contains('id', $id)),
            'agents' => User::query()->where('role', UserRole::Agent->value)->whereNotNull('activated_at')->whereNull('deactivated_at')->orderBy('name')->get()
                ->filter(fn (User $u) => $department !== null && $u->canWorkOn($department))->groupBy(fn (User $u) => $u->team?->label()),
            'companies' => Company::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function spam(Request $request, InboundEmail $inboundEmail, ResolveInboundEmail $resolve): RedirectResponse
    {
        Gate::authorize('decide', $inboundEmail);
        $resolve->markSpam($this->actor($request), $inboundEmail);

        return redirect()->route('admin.inbound.index')->with('status', 'Correo marcado como spam. Se borrará pasado el plazo.');
    }

    public function notSpam(Request $request, InboundEmail $inboundEmail, ResolveInboundEmail $resolve): RedirectResponse
    {
        Gate::authorize('decide', $inboundEmail);
        $resolve->markNotSpam($this->actor($request), $inboundEmail);

        return redirect()->route('admin.inbound.show', $inboundEmail)->with('status', 'Vuelve a pendientes.');
    }

    public function invite(InviteFromInboundRequest $request, InboundEmail $inboundEmail, InviteUser $invite): RedirectResponse
    {
        Gate::authorize('decide', $inboundEmail);

        $user = $invite->handle(
            $request->string('name')->trim()->toString(),
            $inboundEmail->from_email,
            UserRole::from($request->string('role')->toString()),
            Company::findOrFail($request->integer('company_id')),
        );
        // Queda constancia en el propio correo; sigue pendiente hasta que alguien cree el ticket (no se crea solo, R18).
        $inboundEmail->forceFill(['reasons' => [...(array) $inboundEmail->reasons, "Invitación enviada a {$user->email} el ".now()->format('d/m/Y').'.']])->save();

        return redirect()->route('admin.inbound.show', $inboundEmail)->with('status', "Invitación enviada a {$user->email}. Cuando acepte, crea el ticket desde aquí.");
    }

    public function createTicket(CreateTicketFromInboundRequest $request, InboundEmail $inboundEmail, ResolveInboundEmail $resolve): RedirectResponse
    {
        Gate::authorize('decide', $inboundEmail);
        $customer = $request->customer();
        abort_unless($customer !== null, 403);

        $ticket = $resolve->createTicket(
            $this->actor($request), $inboundEmail, $customer,
            Category::findOrFail($request->integer('category_id')),
            $request->filled('product_id') ? Product::findOrFail($request->integer('product_id')) : null,
            User::findOrFail($request->integer('asignado')),
            $request->boolean('notificar'),
        );

        return redirect()->route('tickets.show', $ticket)->with('status', "Ticket #{$ticket->number} creado a nombre de {$customer->name} a partir del correo.");
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
