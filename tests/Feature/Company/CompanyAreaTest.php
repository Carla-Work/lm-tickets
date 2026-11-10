<?php

/*
 * «Mi empresa» (cliente): productos en solo lectura (R7) y gestión de usuarios por el administrador de empresa,
 * siempre dentro de SU empresa (R1, R8, R18).
 * Cuándo tocarla: al cambiar quién gestiona los usuarios de una empresa; cambiar también la matriz de
 * docs/plan/autorizacion.md. Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Company;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class CompanyAreaTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    private function como(string $clave): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->a[$clave]);
    }

    #[TestDox('Los clientes ven «Mi empresa»; el equipo (agentes y administrador) recibe 403; el visitante va a entrar')]
    public function test_who_can_open_it(): void
    {
        foreach (['cuA', 'caA', 'cuB', 'caB'] as $clave) {
            $this->como($clave)->get(route('company.show'))->assertOk();
        }
        foreach (['agSup', 'agApp', 'admin'] as $clave) {
            $this->como($clave)->get(route('company.show'))->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->get(route('company.show'))->assertRedirect(route('login'));
    }

    #[TestDox('Ve solo los productos ACTIVOS de SU empresa; el usuario normal no ve la gestión de usuarios y el administrador de empresa sí')]
    public function test_products_and_scope(): void
    {
        $mio = Product::factory()->create(['name' => 'oneMIO']);
        $retirado = Product::factory()->inactive()->create(['name' => 'oneRETIRADO']);
        $ajeno = Product::factory()->create(['name' => 'oneAJENO']);
        $this->empresaA->products()->attach([$mio->id, $retirado->id]);
        $this->empresaB->products()->attach($ajeno);

        $this->como('cuA')->get(route('company.show'))->assertOk()->assertSee('oneMIO')->assertDontSee('oneRETIRADO')->assertDontSee('oneAJENO')
            ->assertDontSee('Añadir un usuario')->assertDontSee('Usuario A2');
        $this->como('caA')->get(route('company.show'))->assertOk()->assertSee('Añadir un usuario')->assertSee('Usuario A2')->assertDontSee('Usuario B');
    }

    #[TestDox('R8/R18/R1: el administrador de empresa invita a SU empresa (usuario o administrador de empresa), nunca a agente ni a otra empresa')]
    public function test_invite_scope(): void
    {
        Notification::fake();
        $datos = fn (array $extra = []) => $extra + ['name' => 'Nueva Persona', 'email' => 'nueva@empresa-a.example', 'role' => 'company_user'];

        $this->como('caA')->post(route('company.users.store'), $datos(['company_id' => $this->empresaB->id]))->assertRedirect(route('company.show'));
        $nueva = User::where('email', 'nueva@empresa-a.example')->firstOrFail();
        $this->assertSame($this->empresaA->id, $nueva->company_id, 'la empresa sale de quien invita, no del formulario');
        $this->assertNull($nueva->activated_at);
        Notification::assertSentTo($nueva, InvitationNotification::class);

        $this->como('caA')->post(route('company.users.store'), $datos(['email' => 'admin2@empresa-a.example', 'role' => 'company_admin']))->assertRedirect();
        $this->assertSame(UserRole::CompanyAdmin, User::where('email', 'admin2@empresa-a.example')->firstOrFail()->role);

        foreach (['admin', 'agent', 'agente', 'inventado'] as $rol) {
            $this->como('caA')->post(route('company.users.store'), $datos(['email' => "{$rol}@empresa-a.example", 'role' => $rol]))->assertSessionHasErrors('role');
            $this->assertNull(User::where('email', "{$rol}@empresa-a.example")->first());
        }
        $this->como('caA')->post(route('company.users.store'), $datos(['email' => $this->a['cuB']->email]))->assertSessionHasErrors('email');
    }

    #[TestDox('Quien no es administrador de empresa no invita ni cambia roles (403, aunque mande datos inválidos)')]
    public function test_others_cannot_manage(): void
    {
        $malos = ['name' => '', 'email' => 'no', 'role' => 'x'];
        foreach (['cuA', 'agSup', 'admin'] as $clave) {
            $this->como($clave)->post(route('company.users.store'), $malos)->assertForbidden();
            // El administrador de la aplicación SÍ puede cambiar roles (UserPolicy::changeCompanyRole), por eso no entra aquí.
            if ($clave !== 'admin') {
                $this->como($clave)->post(route('company.users.role', $this->a['cuA2']), $malos)->assertForbidden();
            }
        }
        $this->assertSame(UserRole::CompanyUser, $this->a['cuA2']->fresh()->role);
    }

    #[TestDox('Cambiar rol: usuario ↔ administrador de empresa dentro de la empresa, nunca el propio, ni de otra empresa, ni a agente; queda auditado')]
    public function test_change_role(): void
    {
        $this->como('caA')->post(route('company.users.role', $this->a['cuA2']), ['role' => 'company_admin'])->assertRedirect();
        $this->assertSame(UserRole::CompanyAdmin, $this->a['cuA2']->fresh()->role);
        $this->assertTrue(AuditLog::where('event', 'company_role_changed')->where('subject_id', $this->a['cuA2']->id)->exists());

        $this->como('caA')->post(route('company.users.role', $this->a['cuA2']), ['role' => 'company_user'])->assertRedirect();
        $this->assertSame(UserRole::CompanyUser, $this->a['cuA2']->fresh()->role);

        $this->como('caA')->post(route('company.users.role', $this->a['caA']), ['role' => 'company_user'])->assertForbidden();
        $this->como('caA')->post(route('company.users.role', $this->a['cuB']), ['role' => 'company_admin'])->assertForbidden();
        $this->como('caA')->post(route('company.users.role', $this->a['agSup']), ['role' => 'company_admin'])->assertForbidden();
        foreach (['agent', 'admin'] as $rol) {
            $this->como('caA')->post(route('company.users.role', $this->a['cuA2']), ['role' => $rol])->assertSessionHasErrors('role');
        }
        $this->assertTrue($this->a['cuA2']->fresh()->hasRole(UserRole::CompanyUser), 'el rol de un usuario no cambia por intentos rechazados');
        $this->assertTrue($this->a['caA']->fresh()->hasRole(UserRole::CompanyAdmin));
        $this->assertTrue($this->a['caB']->fresh()->hasRole(UserRole::CompanyAdmin));
    }

    #[TestDox('Baja y reenvío desde «Mi empresa»: solo dentro de la empresa (R1); la pantalla ofrece los botones que la policy permite')]
    public function test_row_actions(): void
    {
        $pendiente = User::factory()->invited()->forCompany($this->empresaA)->create(['name' => 'Persona Pendiente']);

        $this->como('caA')->get(route('company.show'))->assertSee('Persona Pendiente')->assertSee('Reenviar invitación')->assertSee('Eres tú');
        $this->como('caA')->post(route('users.deactivate', $pendiente))->assertRedirect();
        $this->assertNotNull($pendiente->fresh()->deactivated_at);
        $this->como('caA')->get(route('company.show'))->assertSee('Reactivar');

        $this->como('caB')->post(route('users.deactivate', $this->a['cuA2']))->assertForbidden();
        $this->como('caB')->post(route('users.invitation.resend', $pendiente))->assertForbidden();
    }

    #[TestDox('El menú del cliente enlaza «Mi empresa»; el del equipo no')]
    public function test_menu(): void
    {
        $this->como('cuA')->get(route('tickets.index'))->assertSee(route('company.show'));
        $this->como('agSup')->get(route('inbox'))->assertDontSee(route('company.show'));
    }
}
