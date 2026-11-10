<?php

/*
 * «Mi empresa» (cliente): sus productos contratados (solo lectura, R7) y, para el administrador de empresa,
 * la gestión de los usuarios de SU empresa. Mockup validado: cliente-mi-empresa-a-administrador.html.
 * Cuándo tocarlo: al cambiar esta pantalla. Quién puede qué: UserPolicy y CompanyPolicy (matriz en autorizacion.md).
 *
 * Trampas:
 *  - La empresa sale SIEMPRE de la cuenta de quien mira o invita, nunca de la URL ni del formulario (R1).
 *  - El usuario normal solo ve los productos (decisión abierta nº 8: se aplicó lo más restrictivo; el mockup solo
 *    dibuja la vista del administrador de empresa). Pendiente de confirmar.
 *  - Cambiar rol, dar de baja y reenviar invitación comprueban la policy sobre la persona objetivo.
 *  - Sin «Último acceso» (no se guarda ese dato).
 */

namespace App\Http\Controllers;

use App\Actions\Users\ChangeCompanyRole;
use App\Actions\Users\InviteUser;
use App\Enums\UserRole;
use App\Http\Requests\InviteCompanyUserRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CompanyAreaController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $company = $user?->company;
        abort_unless($user !== null && $company !== null, 403); // el equipo de Ling Marco no tiene «Mi empresa»
        Gate::authorize('view', $company);

        return view('company.show', [
            'user' => $user,
            'company' => $company,
            'products' => $company->products()->where('active', true)->orderBy('name')->get(),
            'canManage' => $user->hasRole(UserRole::CompanyAdmin),
            'members' => $user->hasRole(UserRole::CompanyAdmin)
                ? $company->users()->orderByRaw('activated_at is null')->orderBy('name')->get()
                : collect(),
        ]);
    }

    public function store(InviteCompanyUserRequest $request, InviteUser $invite): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $user->company !== null, 403);

        $role = UserRole::from($request->string('role')->toString());
        Gate::authorize('invite', [User::class, $role, $user->company]);

        $new = $invite->handle($request->string('name')->toString(), $request->string('email')->toString(), $role, $user->company);

        return redirect()->route('company.show')->with('status', "Invitación enviada a {$new->email}.");
    }

    public function changeRole(Request $request, User $target, ChangeCompanyRole $change): RedirectResponse
    {
        Gate::authorize('changeCompanyRole', $target);
        $actor = $request->user();
        abort_unless($actor !== null, 403);
        $data = $request->validate(['role' => ['required', Rule::in([UserRole::CompanyUser->value, UserRole::CompanyAdmin->value])]], [], ['role' => 'rol']);

        $change($actor, $target, UserRole::from($data['role']));

        return back()->with('status', "Rol de {$target->name} cambiado.");
    }
}
