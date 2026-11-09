<?php

/*
 * Invitaciones: la ÚNICA forma de que nazca una cuenta (R18).
 * Cuándo tocarlo: si cambia cómo se crean las cuentas. Si falla un test, se arregla el código o se
 * discute la regla con la usuaria; NO se adapta el test.
 */

namespace Tests\Feature\Auth;

use App\Actions\Users\InviteUser;
use App\Enums\Team;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function invitar(UserRole $rol = UserRole::CompanyUser, ?Company $empresa = null, ?Team $equipo = null): array
    {
        Notification::fake();
        $token = null;
        $usuario = app(InviteUser::class)->handle('Lucía Ramos', 'lucia@example.test', $rol, $empresa, $equipo);
        Notification::assertSentTo($usuario, InvitationNotification::class, function (InvitationNotification $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return [$usuario, $token];
    }

    private function aceptar(string $token, string $correo, string $clave = 'mi primera contraseña larga')
    {
        return $this->post(route('invitation.store'), [
            'token' => $token, 'email' => $correo, 'password' => $clave, 'password_confirmation' => $clave,
        ]);
    }

    #[TestDox('Invitar crea la cuenta SIN activar y envía la invitación (R18)')]
    public function test_invite_creates_pending_account_and_sends_email(): void
    {
        [$usuario] = $this->invitar(UserRole::CompanyUser, Company::factory()->create());

        $this->assertNull($usuario->fresh()->activated_at);
        $this->assertTrue($usuario->fresh()->isPendingInvitation());
        $this->assertSame(UserRole::CompanyUser, $usuario->fresh()->role);
    }

    #[TestDox('La cuenta invitada tiene la empresa, el rol y el equipo que se indicaron')]
    public function test_invite_assigns_role_company_and_team(): void
    {
        $empresa = Company::factory()->create();
        [$cliente] = $this->invitar(UserRole::CompanyAdmin, $empresa);
        $this->assertSame($empresa->id, $cliente->fresh()->company_id);
        $this->assertSame(UserRole::CompanyAdmin, $cliente->fresh()->role);

        Notification::fake();
        $agente = app(InviteUser::class)->handle('Daniel Ortega', 'daniel@example.test', UserRole::Agent, null, Team::Applications);
        $this->assertSame(Team::Applications, $agente->fresh()->team);
        $this->assertNull($agente->fresh()->company_id);
    }

    #[TestDox('Una combinación imposible (agente con empresa) la rechaza la base de datos')]
    public function test_invite_rejects_impossible_combination(): void
    {
        Notification::fake();

        $this->expectException(QueryException::class);
        app(InviteUser::class)->handle('X', 'x@example.test', UserRole::Agent, Company::factory()->create(), Team::Support);
    }

    #[TestDox('Hasta aceptar la invitación no se puede entrar: la contraseña inicial la desconoce todo el mundo')]
    public function test_pending_account_cannot_log_in(): void
    {
        [$usuario] = $this->invitar(UserRole::CompanyUser, Company::factory()->create());

        foreach (['password', 'secret', '', 'lucia@example.test'] as $intento) {
            $this->post(route('login.store'), ['email' => $usuario->email, 'password' => $intento]);
            $this->assertGuest();
        }
    }

    #[TestDox('Aceptar la invitación activa la cuenta y permite entrar con la contraseña elegida')]
    public function test_accepting_invitation_activates_and_allows_login(): void
    {
        [$usuario, $token] = $this->invitar(UserRole::CompanyUser, Company::factory()->create());

        $this->aceptar($token, $usuario->email)->assertRedirect(route('login'));

        $this->assertNotNull($usuario->fresh()->activated_at);
        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => 'mi primera contraseña larga'])
            ->assertRedirect(route('panel'));
        $this->assertAuthenticatedAs($usuario);
    }

    #[TestDox('La invitación es de un solo uso')]
    public function test_invitation_is_single_use(): void
    {
        [$usuario, $token] = $this->invitar(UserRole::CompanyUser, Company::factory()->create());

        $this->aceptar($token, $usuario->email)->assertRedirect(route('login'));
        $this->aceptar($token, $usuario->email, 'otra contraseña distinta')->assertSessionHasErrors('password');
    }

    #[TestDox('La invitación sigue valiendo a los 6 días y caduca a los 8')]
    public function test_invitation_lasts_seven_days(): void
    {
        [$usuario, $token] = $this->invitar(UserRole::CompanyUser, Company::factory()->create());

        $this->travel(6)->days();
        $this->get(route('invitation.accept', ['token' => $token, 'email' => $usuario->email]))->assertOk();
        $this->aceptar($token, $usuario->email)->assertRedirect(route('login'));
    }

    #[TestDox('Pasados 8 días la invitación caducada se rechaza')]
    public function test_invitation_expires_after_a_week(): void
    {
        [$usuario, $token] = $this->invitar(UserRole::CompanyUser, Company::factory()->create());

        $this->travel(8)->days();

        $this->aceptar($token, $usuario->email)->assertSessionHasErrors('password');
        $this->assertNull($usuario->fresh()->activated_at, 'una invitación caducada no activa la cuenta');
    }

    #[TestDox('Una contraseña de menos de 12 caracteres no activa la cuenta')]
    public function test_short_password_does_not_activate(): void
    {
        [$usuario, $token] = $this->invitar(UserRole::CompanyUser, Company::factory()->create());

        $this->aceptar($token, $usuario->email, 'corta')->assertSessionHasErrors('password');

        $this->assertNull($usuario->fresh()->activated_at);
    }

    #[TestDox('Un enlace de INVITACIÓN no sirve en la ruta de recuperación: no se puede usar para saltarse la activación')]
    public function test_invitation_token_is_not_valid_on_the_reset_route(): void
    {
        [$usuario, $token] = $this->invitar(UserRole::CompanyUser, Company::factory()->create());

        $this->post(route('password.update'), [
            'token' => $token, 'email' => $usuario->email,
            'password' => 'una frase larga y segura', 'password_confirmation' => 'una frase larga y segura',
        ])->assertSessionHasErrors('password');

        $this->assertNull($usuario->fresh()->activated_at);
    }

    #[TestDox('Un enlace de RECUPERACIÓN de una cuenta ya activa no sirve en la ruta de invitación')]
    public function test_reset_token_of_active_user_is_not_valid_on_the_invitation_route(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker('users')->createToken($usuario);

        $this->aceptar($token, $usuario->email, 'una frase larga distinta')->assertSessionHasErrors('password');

        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => 'una frase larga distinta']);
        $this->assertGuest();
    }

    #[TestDox('Reenviar la invitación invalida el enlace anterior')]
    public function test_resending_invalidates_previous_link(): void
    {
        [$usuario, $primero] = $this->invitar(UserRole::CompanyUser, Company::factory()->create());
        $segundo = null;
        Notification::fake();
        app(InviteUser::class)->send($usuario);
        Notification::assertSentTo($usuario, InvitationNotification::class, function ($n) use (&$segundo) {
            $segundo = $n->token;

            return true;
        });

        $this->aceptar($primero, $usuario->email)->assertSessionHasErrors('password');
        $this->aceptar($segundo, $usuario->email)->assertRedirect(route('login'));
    }

    #[TestDox('La pantalla de la invitación se ve y lleva el correo de la cuenta')]
    public function test_invitation_page_renders(): void
    {
        [$usuario, $token] = $this->invitar(UserRole::CompanyUser, Company::factory()->create());

        $this->get(route('invitation.accept', ['token' => $token, 'email' => $usuario->email]))
            ->assertOk()->assertSee('Crea tu contraseña')->assertSee($usuario->email);
    }

    #[TestDox('El correo de invitación está en español, lleva el enlace y dice que caduca en 7 días')]
    public function test_invitation_email_content(): void
    {
        $usuario = User::factory()->create(['name' => 'Lucía Ramos']);

        $correo = (new InvitationNotification('TOKEN-INV'))->toMail($usuario);
        $texto = implode("\n", array_merge($correo->introLines, $correo->outroLines));

        $this->assertSame('Te han invitado a Ling Marco Tickets', $correo->subject);
        $this->assertStringContainsString('TOKEN-INV', $correo->actionUrl);
        $this->assertStringContainsString('/invitacion/', $correo->actionUrl);
        $this->assertStringContainsString('7 días', $texto);
    }
}
