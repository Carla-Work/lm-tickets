<?php

/*
 * «Usuarios» (Administración, solo el administrador): los empleados de Ling Marco. Alta de agentes, cambio de
 * equipo y traspaso del rol de administrador. Dar de baja / reactivar va por UserStatusController.
 * Cuándo tocarlo: al cambiar esta pantalla. Mockup validado: admin-usuarios-a-empleados.html.
 *
 * Trampas:
 *  - Desviaciones del mockup (pendientes de confirmar): sin «Último acceso» (no se guarda) y el traspaso pide
 *    además el equipo con el que se queda quien deja de ser administrador (la base exige equipo a un agente).
 *  - El traspaso se confirma escribiendo TRASPASAR.
 *  - Tras traspasar, quien lo hizo ya es agente: se le manda a la bandeja, no a una pantalla que ya no puede ver.
 */

namespace App\Http\Controllers\Admin;

use App\Actions\Users\ChangeAgentTeam;
use App\Actions\Users\InviteUser;
use App\Actions\Users\TransferAdmin;
use App\Enums\Team;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteEmployeeRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);
        abort_unless($request->user()?->hasRole(UserRole::Admin), 403); // viewAny también deja pasar al administrador de empresa

        return view('admin.staff.index', [
            'user' => $request->user(),
            'staff' => User::query()->whereIn('role', [UserRole::Admin->value, UserRole::Agent->value])
                ->orderByRaw("role = 'admin' desc")->orderByRaw('deactivated_at is not null')->orderBy('name')->get(),
        ]);
    }

    public function store(InviteEmployeeRequest $request, InviteUser $invite): RedirectResponse
    {
        $employee = $invite->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            UserRole::Agent,
            null,
            Team::from($request->string('team')->toString()),
        );

        return redirect()->route('admin.staff.index')->with('status', "Invitación enviada a {$employee->email}.");
    }

    public function changeTeam(Request $request, User $user, ChangeAgentTeam $change): RedirectResponse
    {
        Gate::authorize('changeTeam', $user);
        $actor = $request->user();
        abort_unless($actor !== null, 403);
        $data = $request->validate(['team' => ['required', Rule::enum(Team::class)]], [], ['team' => 'equipo']);

        $change($actor, $user, Team::from($data['team']));

        return redirect()->route('admin.staff.index')->with('status', "Equipo de {$user->name} cambiado.");
    }

    public function transfer(Request $request, TransferAdmin $transfer): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor !== null && $actor->hasRole(UserRole::Admin), 403);

        $data = $request->validate([
            'nuevo' => ['required', 'integer'],
            'equipo' => ['required', Rule::enum(Team::class)],
            'confirmacion' => ['required', 'string'],
        ], [], ['nuevo' => 'nuevo administrador', 'equipo' => 'equipo', 'confirmacion' => 'confirmación']);

        if (mb_strtoupper(trim($data['confirmacion'])) !== 'TRASPASAR') {
            return back()->withErrors(['confirmacion' => 'Escribe TRASPASAR para confirmar.'])->withInput();
        }

        $target = User::query()->find((int) $data['nuevo']);
        // Mismo mensaje para «no existe», «no es agente» o «no está en activo»: la policy decide, no se explica.
        if ($target === null || ! Gate::forUser($actor)->allows('transferAdmin', $target)) {
            return back()->withErrors(['nuevo' => 'Elige un agente en activo.'])->withInput();
        }

        $transfer($actor, $target, Team::from($data['equipo']));

        return redirect()->route('inbox')->with('status', "El rol de administrador pasa a {$target->name}. Ahora eres agente.");
    }
}
