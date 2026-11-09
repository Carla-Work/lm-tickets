<?php

/*
 * Una cuenta que ya no puede usar la aplicación (dada de baja, sin activar, o de una empresa de baja) no
 * puede NADA, aunque conserve una sesión de antes. Defensa en dos capas: Gate::before (policies) y el
 * middleware EnsureAccountIsOperational (rutas).
 * Cuándo tocarlo: nunca para «dejar pasar». Si falla un test, se arregla el código.
 */

namespace Tests\Feature\Authorization;

use App\Enums\Department;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class DeactivatedAccessTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    /** @return array<string, User> cuentas que no son operativas, con el rol más poderoso de su tipo */
    private function nonOperational(): array
    {
        $companyOff = Company::factory()->deactivated()->create();

        return [
            'cliente de baja' => User::factory()->forCompany($this->empresaA)->deactivated()->create(),
            'administrador de empresa de baja' => User::factory()->companyAdmin()->forCompany($this->empresaA)->deactivated()->create(),
            'agente de baja' => User::factory()->agent()->deactivated()->create(),
            'administrador de baja' => User::factory()->admin()->deactivated()->create(),
            'invitación sin aceptar' => User::factory()->forCompany($this->empresaA)->invited()->create(),
            'agente sin activar' => User::factory()->agent()->invited()->create(),
            'cliente de empresa de baja' => User::factory()->forCompany($companyOff)->create(),
            'administrador de empresa de empresa de baja' => User::factory()->companyAdmin()->forCompany($companyOff)->create(),
        ];
    }

    #[TestDox('Una cuenta no operativa no puede NINGUNA habilidad sobre un ticket, ni siquiera las de su rol')]
    public function test_non_operational_accounts_have_no_ticket_abilities(): void
    {
        $ticket = $this->ticket(Department::WebApps, assignedTo: 'agApp');
        $abilities = ['view', 'viewInternal', 'reply', 'addNote', 'markResolved', 'resolve', 'updateStatus', 'close', 'assign', 'changeDepartment', 'createRelated'];

        foreach ($this->nonOperational() as $name => $user) {
            $this->assertFalse($user->can('create', Ticket::class), "$name abriendo tickets");
            foreach ($abilities as $ability) {
                $this->assertFalse($user->can($ability, $ticket), "$name «{$ability}»");
            }
        }
    }

    #[TestDox('Una cuenta no operativa no puede ninguna acción de administración (ni el administrador de baja)')]
    public function test_non_operational_accounts_have_no_admin_abilities(): void
    {
        foreach ($this->nonOperational() as $name => $user) {
            $this->assertFalse($user->can('create', Company::class), "$name creando empresas");
            $this->assertFalse($user->can('update', $this->empresaA), "$name editando empresas");
            $this->assertFalse($user->can('assignProducts', $this->empresaA), "$name asignando productos");
            $this->assertFalse($user->can('deactivate', $this->a['cuA']), "$name dando de baja");
            $this->assertFalse($user->can('transferAdmin', $this->a['agSup']), "$name traspasando el rol");
        }
    }

    #[TestDox('Los ámbitos visibleTo no devuelven NADA a una cuenta no operativa')]
    public function test_non_operational_accounts_see_nothing_in_lists(): void
    {
        $this->ticket();

        foreach ($this->nonOperational() as $name => $user) {
            $this->assertSame(0, Ticket::visibleTo($user)->count(), "$name ve tickets en una lista");
        }
    }

    #[TestDox('Una cuenta de baja con la sesión abierta pierde el acceso en la siguiente petición y vuelve a la pantalla de acceso')]
    public function test_deactivated_user_with_open_session_is_logged_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('panel'))->assertOk();

        $user->forceFill(['deactivated_at' => now()])->save();

        $this->get(route('panel'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[TestDox('Si se da de baja a la EMPRESA, sus usuarios con sesión abierta la pierden en la siguiente petición')]
    public function test_company_deactivation_logs_out_its_users(): void
    {
        $user = User::factory()->forCompany($this->empresaA)->create();
        $this->actingAs($user)->get(route('panel'))->assertOk();

        $this->empresaA->forceFill(['deactivated_at' => now()])->save();

        // Se vuelve a cargar el usuario desde la base de datos, como pasa en cada petición real: la
        // instancia del test conserva en memoria la relación `company` ya cargada y vería la empresa de alta.
        $this->actingAs($user->fresh())->get(route('panel'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[TestDox('Un administrador de baja con la sesión abierta (y 2FA activado) también la pierde')]
    public function test_deactivated_admin_is_logged_out(): void
    {
        // Se reutiliza el administrador de crearActores(): un SEGUNDO administrador activo lo rechaza la base de datos (R9).
        $admin = $this->a['admin'];
        $admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(), 'deactivated_at' => now()])->save();

        $this->actingAs($admin)->get(route('panel'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[TestDox('El filtro global nunca CONCEDE: una cuenta operativa sigue sujeta a las policies (no es un pase libre)')]
    public function test_gate_before_never_grants(): void
    {
        $ticketOfOther = Ticket::factory()->create(['company_id' => $this->empresaB->id, 'requester_id' => $this->a['cuB']->id]);

        $this->assertTrue($this->a['cuA']->isOperational());
        $this->assertFalse($this->a['cuA']->can('view', $ticketOfOther), 'ser operativo no da acceso a lo ajeno');
    }
}
