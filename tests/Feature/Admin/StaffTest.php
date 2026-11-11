<?php

/*
 * Administración › Usuarios (empleados): alta de agentes, equipo, baja/reactivación y traspaso del rol de
 * administrador. Reglas R8, R9, R1, R18, R21.
 * Cuándo tocarla: al cambiar quién gestiona empleados o qué implica la baja/traspaso; cambiar también la matriz
 * de docs/plan/autorizacion.md. Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 *
 * Trampa: al cambiar de persona dentro del test hay que soltar el usuario en caché del guard
 * (`$this->app['auth']->forgetGuards()`), o la petición sigue siendo de la anterior.
 */

namespace Tests\Feature\Admin;

use App\Actions\Users\UnassignTickets;
use App\Enums\Department;
use App\Enums\Team;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\TicketEvent;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class StaffTest extends TestCase
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

    private function admins(): int
    {
        return User::where('role', UserRole::Admin->value)->whereNull('deactivated_at')->count();
    }

    #[TestDox('Solo el administrador usa las pantallas de empleados: el resto recibe 403 aunque mande datos inválidos; el visitante va a entrar')]
    public function test_only_admin_manages_staff(): void
    {
        $agente = $this->a['agApp'];
        $rutas = [
            ['get', route('admin.staff.index')], ['post', route('admin.staff.store')], ['post', route('admin.staff.transfer')],
            ['post', route('admin.staff.team', $agente)], ['post', route('users.deactivate', $agente)], ['post', route('users.reactivate', $agente)],
        ];

        foreach (['agSup', 'cuA', 'cuA2', 'caA', 'cuB', 'caB'] as $clave) {
            foreach ($rutas as [$metodo, $url]) {
                $this->como($clave)->{$metodo}($url, ['name' => '', 'email' => 'no', 'team' => 'x', 'nuevo' => 'x'])->assertForbidden();
            }
        }
        $this->assertNull($agente->fresh()->deactivated_at);

        foreach ($rutas as [$metodo, $url]) {
            $this->app['auth']->forgetGuards();
            $this->{$metodo}($url)->assertRedirect(route('login'));
        }
        $this->como('admin')->get(route('admin.staff.index'))->assertOk()->assertSee('Agente Soporte')->assertSee('Traspasar el rol');
    }

    #[TestDox('R8/R18: se crea un AGENTE invitado con su equipo y sin empresa; el rol no se puede pedir por el formulario; el correo no se repite')]
    public function test_invite_employee(): void
    {
        Notification::fake();

        $this->como('admin')->post(route('admin.staff.store'), ['name' => 'Nuevo Agente', 'email' => 'nuevo@lingmarco.example', 'team' => 'applications', 'role' => 'admin', 'company_id' => $this->empresaA->id])
            ->assertRedirect(route('admin.staff.index'));

        $nuevo = User::where('email', 'nuevo@lingmarco.example')->firstOrFail();
        $this->assertSame(UserRole::Agent, $nuevo->role, 'el rol lo fija el servidor');
        $this->assertSame(Team::Applications, $nuevo->team);
        $this->assertNull($nuevo->company_id);
        $this->assertNull($nuevo->activated_at);
        Notification::assertSentTo($nuevo, InvitationNotification::class);
        $this->assertSame(1, $this->admins());

        $this->como('admin')->post(route('admin.staff.store'), ['name' => 'Otro', 'email' => 'nuevo@lingmarco.example', 'team' => 'support'])->assertSessionHasErrors('email');
        $this->como('admin')->post(route('admin.staff.store'), ['name' => 'Otro', 'email' => 'otro@lingmarco.example', 'team' => 'inventado'])->assertSessionHasErrors('team');
    }

    #[TestDox('Se puede crear un agente en el equipo Contabilidad y pasar a otro agente a Contabilidad; el agente queda confinado a ese departamento')]
    public function test_accounting_team_can_be_assigned(): void
    {
        Notification::fake();

        $this->como('admin')->post(route('admin.staff.store'), ['name' => 'Comercial', 'email' => 'comercial@lingmarco.example', 'team' => 'accounting'])->assertRedirect();
        $nuevo = User::where('email', 'comercial@lingmarco.example')->firstOrFail();
        $this->assertSame(Team::Accounting, $nuevo->team);
        $this->assertSame(['other'], $nuevo->workableDepartments());

        $this->como('admin')->post(route('admin.staff.team', $this->a['agSup']), ['team' => 'accounting'])->assertRedirect();
        $this->assertSame(['other'], $this->a['agSup']->fresh()->workableDepartments());
    }

    #[TestDox('Cambiar el equipo: solo de un agente, queda auditado y los tickets que lleva no se mueven')]
    public function test_change_team(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->como('admin')->post(route('admin.staff.team', $this->a['agSup']), ['team' => 'applications'])->assertRedirect();
        $this->assertSame(Team::Applications, $this->a['agSup']->fresh()->team);
        $this->assertSame($this->a['agSup']->id, $ticket->fresh()->assigned_to);
        $this->assertTrue(AuditLog::where('event', 'team_changed')->where('subject_id', $this->a['agSup']->id)->exists());

        $this->como('admin')->post(route('admin.staff.team', $this->a['agSup']), ['team' => 'inventado'])->assertSessionHasErrors('team');
        $this->como('admin')->post(route('admin.staff.team', $this->a['cuA']), ['team' => 'support'])->assertForbidden();
        $this->como('admin')->post(route('admin.staff.team', $this->a['admin']), ['team' => 'support'])->assertForbidden();
    }

    #[TestDox('Al cambiar de equipo se liberan (con evento interno) los tickets no cerrados que el agente ya no puede atender y se conservan los que sí (D-12)')]
    public function test_team_change_releases_tickets_it_can_no_longer_work_on(): void
    {
        $deSoporte = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $cerrado = $this->ticket(Department::Support, TicketStatus::Closed, 'agSup');

        // De Soporte a Contabilidad: ya no atiende «Soporte» (solo «Contabilidad»).
        $this->como('admin')->post(route('admin.staff.team', $this->a['agSup']), ['team' => 'accounting'])->assertRedirect();

        $this->assertNull($deSoporte->fresh()->assigned_to, 'el ticket de Soporte queda libre');
        $this->assertSame($this->a['agSup']->id, $cerrado->fresh()->assigned_to, 'los cerrados conservan quién los llevó');
        $evento = TicketEvent::where('ticket_id', $deSoporte->id)->where('type', TicketEventType::Assigned->value)->latest('id')->firstOrFail();
        $this->assertSame('team_changed', $evento->data['reason']);
        $this->assertFalse($evento->visible_to_customer);
        $this->assertSame(1, AuditLog::where('event', 'team_changed')->firstOrFail()->properties['tickets_released']);

        // El historial interno lo explica con palabras (si faltara el texto de la vista saldría una frase vacía).
        $this->como('admin')->get(route('tickets.show', $deSoporte))->assertOk()->assertSee('cambió de equipo y ya no atiende este departamento');
        $this->app['auth']->forgetGuards();

        // Entre Soporte y Desarrollo y Diseño atienden lo mismo: no se libera nada.
        $this->app['auth']->forgetGuards();
        $otro = $this->ticket(Department::WebApps, TicketStatus::InProgress, 'agApp');
        $this->como('admin')->post(route('admin.staff.team', $this->a['agApp']), ['team' => 'support'])->assertRedirect();
        $this->assertSame($this->a['agApp']->id, $otro->fresh()->assigned_to);

        // Y de Contabilidad a otro equipo se libera el de Contabilidad.
        $deConta = $this->ticket(Department::Other, TicketStatus::InProgress, 'agCon');
        $this->como('admin')->post(route('admin.staff.team', $this->a['agCon']), ['team' => 'support'])->assertRedirect();
        $this->assertNull($deConta->fresh()->assigned_to);
    }

    #[TestDox('La baja de un agente corta su acceso, libera sus tickets no cerrados (con evento) y reactivar devuelve el acceso')]
    public function test_deactivate_and_reactivate_agent(): void
    {
        $vivo = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $resuelto = $this->ticket(Department::Support, TicketStatus::Resolved, 'agSup');
        $cerrado = $this->ticket(Department::Support, TicketStatus::Closed, 'agSup');
        $deOtro = $this->ticket(Department::Support, TicketStatus::InProgress, 'agApp');

        $this->como('admin')->post(route('users.deactivate', $this->a['agSup']))->assertRedirect();

        $this->assertNotNull($this->a['agSup']->fresh()->deactivated_at);
        $this->assertNull($vivo->fresh()->assigned_to);
        $this->assertNull($resuelto->fresh()->assigned_to);
        $this->assertSame($this->a['agSup']->id, $cerrado->fresh()->assigned_to, 'un ticket cerrado conserva quién lo llevó');
        $this->assertSame($this->a['agApp']->id, $deOtro->fresh()->assigned_to);
        $evento = TicketEvent::where('ticket_id', $vivo->id)->where('type', TicketEventType::Assigned->value)->firstOrFail();
        $this->assertSame('user_deactivated', $evento->data['reason']);
        $this->assertFalse($evento->visible_to_customer);
        $this->assertTrue(AuditLog::where('event', 'user_deactivated')->exists());

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->a['agSup']->fresh())->get(route('inbox'))->assertRedirect(route('login')); // fresh(): el modelo del test no sabe de la baja

        $this->como('admin')->post(route('users.reactivate', $this->a['agSup']))->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->a['agSup']->fresh())->get(route('inbox'))->assertOk();
        $this->assertTrue(AuditLog::where('event', 'user_reactivated')->exists());
    }

    #[TestDox('R9: el administrador no se puede dar de baja; reactivar una cuenta sin aceptar no la activa (R18)')]
    public function test_cannot_deactivate_admin_or_activate_by_reactivating(): void
    {
        $this->como('admin')->post(route('users.deactivate', $this->a['admin']))->assertForbidden();
        $this->assertNull($this->a['admin']->fresh()->deactivated_at);
        $this->assertSame(1, $this->admins());

        $pendiente = User::factory()->invited()->deactivated()->agent()->create();
        $this->como('admin')->post(route('users.reactivate', $pendiente))->assertRedirect();
        $this->assertNull($pendiente->fresh()->deactivated_at);
        $this->assertNull($pendiente->fresh()->activated_at, 'sigue necesitando su invitación');
    }

    #[TestDox('R1/R8: el administrador de una empresa da de baja a los de SU empresa, nunca a los de otra ni a un empleado')]
    public function test_company_admin_deactivation_scope(): void
    {
        $this->como('caA')->post(route('users.deactivate', $this->a['cuA2']))->assertRedirect();
        $this->assertNotNull($this->a['cuA2']->fresh()->deactivated_at);

        $this->como('caA')->post(route('users.deactivate', $this->a['cuB']))->assertForbidden();
        $this->como('caA')->post(route('users.deactivate', $this->a['agSup']))->assertForbidden();
        $this->como('caA')->post(route('users.deactivate', $this->a['caA']))->assertForbidden();
        $this->como('cuA')->post(route('users.deactivate', $this->a['cuA2']))->assertForbidden();
    }

    #[TestDox('R9: el traspaso intercambia los roles en una sola operación: siempre un único administrador, el antiguo pasa a agente con equipo y sin 2FA')]
    public function test_transfer_admin(): void
    {
        $this->a['admin']->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['x'], 'two_factor_last_step' => 5,
        ])->save();
        $mio = $this->ticket(Department::Support, TicketStatus::InProgress, 'agApp');

        $this->como('admin')->post(route('admin.staff.transfer'), ['nuevo' => $this->a['agApp']->id, 'equipo' => 'applications', 'confirmacion' => ' traspasar '])
            ->assertRedirect(route('inbox'));

        $antiguo = $this->a['admin']->fresh();
        $nuevo = $this->a['agApp']->fresh();
        $this->assertSame(UserRole::Agent, $antiguo->role);
        $this->assertSame(Team::Applications, $antiguo->team);
        $this->assertFalse($antiguo->hasTwoFactorEnabled());
        $this->assertNull($antiguo->two_factor_secret);
        $this->assertSame(UserRole::Admin, $nuevo->role);
        $this->assertNull($nuevo->team);
        $this->assertSame(1, $this->admins());
        $this->assertNull($mio->fresh()->assigned_to, 'el nuevo administrador no lleva tickets (R10)');
        $this->assertSame('role_transfer', TicketEvent::where('ticket_id', $mio->id)->where('type', TicketEventType::Assigned->value)->firstOrFail()->data['reason']);
        $this->assertTrue(AuditLog::where('event', 'admin_transferred')->exists());

        // Los permisos cambian en la siguiente petición: el antiguo ya no entra en administración, el nuevo sí.
        $this->app['auth']->forgetGuards();
        $this->actingAs($antiguo)->get(route('admin.staff.index'))->assertForbidden();
        $this->actingAs($antiguo)->get(route('admin.companies.index'))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($nuevo)->get(route('admin.staff.index'))->assertOk();
    }

    #[TestDox('El traspaso se rechaza (sin cambiar nada) con confirmación errónea o destino que no sea un agente en activo distinto del administrador')]
    public function test_invalid_transfers_change_nothing(): void
    {
        $pendiente = User::factory()->invited()->agent()->create();
        $deBaja = User::factory()->deactivated()->agent()->create();
        $mal = fn (array $datos) => $this->como('admin')->post(route('admin.staff.transfer'), $datos + ['equipo' => 'support', 'confirmacion' => 'TRASPASAR']);

        $mal(['nuevo' => $this->a['agSup']->id, 'confirmacion' => 'si'])->assertSessionHasErrors('confirmacion');
        $mal(['nuevo' => $this->a['agSup']->id, 'confirmacion' => ''])->assertSessionHasErrors('confirmacion');
        foreach ([$this->a['cuA']->id, $this->a['caA']->id, $this->a['admin']->id, $pendiente->id, $deBaja->id, 999999] as $id) {
            $mal(['nuevo' => $id])->assertSessionHasErrors('nuevo');
        }
        $mal(['nuevo' => $this->a['agSup']->id, 'equipo' => 'inventado'])->assertSessionHasErrors('equipo');

        $this->assertSame(UserRole::Admin, $this->a['admin']->fresh()->role);
        $this->assertSame(UserRole::Agent, $this->a['agSup']->fresh()->role);
        $this->assertSame(1, $this->admins());
        $this->assertFalse(AuditLog::where('event', 'admin_transferred')->exists());
    }

    #[TestDox('Si el traspaso falla a medias no cambia ningún rol (una transacción): nunca se queda sin administrador')]
    public function test_failed_transfer_rolls_back(): void
    {
        $this->mock(UnassignTickets::class, fn ($mock) => $mock->shouldReceive('__invoke')->andThrow(new \RuntimeException('fallo')));

        $this->withoutExceptionHandling();
        try {
            $this->como('admin')->post(route('admin.staff.transfer'), ['nuevo' => $this->a['agSup']->id, 'equipo' => 'support', 'confirmacion' => 'TRASPASAR']);
            $this->fail('debía propagar el fallo');
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame(UserRole::Admin, $this->a['admin']->fresh()->role);
        $this->assertSame(UserRole::Agent, $this->a['agSup']->fresh()->role);
        $this->assertSame(1, $this->admins());
    }
}
