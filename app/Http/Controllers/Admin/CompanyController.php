<?php

/*
 * «Empresas» (Administración, solo el administrador): lista, alta, detalle, datos, productos y baja.
 * Cuándo tocarlo: al cambiar estas pantallas. Mockups validados: admin-empresas-a-lista, admin-empresa-b-detalle,
 * admin-empresa-c-nueva (sobre el marco actual del panel, a petición de la usuaria 2026-11-10).
 *
 * Trampas:
 *  - Cada método autoriza con CompanyPolicy; las acciones (App\Actions\Companies) vuelven a autorizar.
 *  - La baja pide ESCRIBIR el nombre de la empresa (decidido por Claude, pendiente de que la usuaria lo
 *    confirme: decisión abierta nº 9). Corta el acceso de todos sus usuarios.
 *  - El mockup traía «Último acceso» por usuario: no se guarda ese dato, así que la columna no existe.
 */

namespace App\Http\Controllers\Admin;

use App\Actions\Companies\CreateCompany;
use App\Actions\Companies\SetCompanyActive;
use App\Actions\Companies\UpdateCompany;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyProductsRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Product;
use App\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CompanyController extends Controller
{
    private const PER_PAGE = 15;

    /** Estados vivos de un ticket, para «Tickets abiertos». */
    private const LIVE = [TicketStatus::Open, TicketStatus::InProgress, TicketStatus::PendingCustomer];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Company::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:activa,pendiente,baja'],
            'producto' => ['nullable', 'integer'],
        ]);

        $live = array_map(fn (TicketStatus $s) => $s->value, self::LIVE);
        $query = Company::query()
            ->with(['products' => fn ($q) => $q->orderBy('name')])
            ->withCount([
                'users',
                'users as activated_users_count' => fn (Builder $q) => $q->whereNotNull('activated_at'),
                'tickets as open_tickets_count' => fn (Builder $q) => $q->whereIn('status', $live),
            ])
            ->when($filters['q'] ?? null, function (Builder $q, string $v) {
                $like = '%'.addcslashes($v, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('tax_id', 'like', $like));
            })
            ->when($filters['producto'] ?? null, fn (Builder $q, $v) => $q->whereHas('products', fn (Builder $p) => $p->where('products.id', $v)))
            ->when($filters['estado'] ?? null, fn (Builder $q, string $v) => match ($v) {
                'baja' => $q->whereNotNull('deactivated_at'),
                'pendiente' => $q->whereNull('deactivated_at')->whereDoesntHave('users', fn (Builder $u) => $u->whereNotNull('activated_at')),
                default => $q->whereNull('deactivated_at')->whereHas('users', fn (Builder $u) => $u->whereNotNull('activated_at')),
            })
            ->orderBy('name');

        return view('admin.companies.index', [
            'user' => $request->user(),
            'companies' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'total' => Company::query()->count(),
            'filters' => $filters,
            'products' => Product::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Company::class);

        return view('admin.companies.create', [
            'user' => $request->user(),
            'products' => Product::query()->where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(StoreCompanyRequest $request, CreateCompany $create): RedirectResponse
    {
        Gate::authorize('create', Company::class);
        $user = $request->user();
        abort_unless($user !== null, 403);

        /** @var array{name: string, tax_id: string, phone?: ?string, contact_email?: ?string} $data */
        $data = $request->safe()->only(['name', 'tax_id', 'phone', 'contact_email']);
        $company = $create($user, $data, array_map('intval', (array) $request->input('productos', [])), $request->string('user_name')->toString(), $request->string('user_email')->toString());

        return redirect()->route('admin.companies.show', $company)->with('status', "Empresa «{$company->name}» creada. Hemos enviado la invitación a {$request->string('user_email')}.");
    }

    public function show(Request $request, Company $company): View
    {
        // `view` también deja pasar a un cliente a SU empresa («Mi empresa»); esta pantalla es de administración,
        // así que además se exige `viewAny` (solo el administrador). Quitarlo abriría el detalle a los clientes.
        Gate::authorize('viewAny', Company::class);
        Gate::authorize('view', $company);

        $tickets = Ticket::query()->where('company_id', $company->id);
        $assignedIds = $company->products()->pluck('products.id')->all();

        return view('admin.companies.show', [
            'user' => $request->user(),
            'company' => $company,
            'users' => $company->users()->orderByRaw('activated_at is null')->orderBy('name')->get(),
            // Activos + los desactivados que la empresa ya tiene (para poder quitárselos).
            'products' => Product::query()->where(fn (Builder $q) => $q->where('active', true)->orWhereIn('id', $assignedIds))->orderBy('name')->get(),
            'assignedIds' => $assignedIds,
            'stats' => [
                'open' => (clone $tickets)->whereIn('status', array_map(fn (TicketStatus $s) => $s->value, self::LIVE))->count(),
                'total' => (clone $tickets)->count(),
                'last' => (clone $tickets)->max('last_activity_at'),
            ],
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company, UpdateCompany $update): RedirectResponse
    {
        Gate::authorize('update', $company);
        $user = $request->user();
        abort_unless($user !== null, 403);

        /** @var array{name: string, tax_id: string, phone?: ?string, contact_email?: ?string} $data */
        $data = $request->safe()->only(['name', 'tax_id', 'phone', 'contact_email']);
        $update->details($user, $company, $data);

        return redirect()->route('admin.companies.show', $company)->with('status', 'Datos guardados.');
    }

    public function updateProducts(UpdateCompanyProductsRequest $request, Company $company, UpdateCompany $update): RedirectResponse
    {
        Gate::authorize('assignProducts', $company);
        $user = $request->user();
        abort_unless($user !== null, 403);

        $update->products($user, $company, array_map('intval', (array) $request->input('productos', [])));

        return redirect()->route('admin.companies.show', $company)->with('status', 'Productos guardados.');
    }

    public function deactivate(Request $request, Company $company, SetCompanyActive $set): RedirectResponse
    {
        Gate::authorize('deactivate', $company);
        $user = $request->user();
        abort_unless($user !== null, 403);

        // Confirmación extra: escribir el nombre. Sin JavaScript funciona igual y evita una baja por un clic.
        $request->validate(['confirmacion' => ['required', 'string']], [], ['confirmacion' => 'confirmación']);
        if (mb_strtolower(trim($request->string('confirmacion')->toString())) !== mb_strtolower($company->name)) {
            return back()->withErrors(['confirmacion' => 'Escribe el nombre de la empresa tal como aparece arriba para confirmar la baja.']);
        }

        $set->deactivate($user, $company);

        return redirect()->route('admin.companies.show', $company)->with('status', "Empresa «{$company->name}» dada de baja. Sus usuarios ya no pueden acceder.");
    }

    public function reactivate(Request $request, Company $company, SetCompanyActive $set): RedirectResponse
    {
        Gate::authorize('deactivate', $company);
        $user = $request->user();
        abort_unless($user !== null, 403);

        $set->reactivate($user, $company);

        return redirect()->route('admin.companies.show', $company)->with('status', "Empresa «{$company->name}» reactivada.");
    }
}
