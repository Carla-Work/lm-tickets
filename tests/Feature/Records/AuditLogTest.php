<?php

/*
 * Auditoría: qué hechos quedan anotados y qué NUNCA se anota. Cuándo tocarlo: al auditar un hecho nuevo
 * (un test por hecho). Regla propia: ni contraseñas ni códigos entran jamás en la auditoría.
 */

namespace Tests\Feature\Records;

use App\Actions\Users\InviteUser;
use App\Enums\Team;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('x');
    }

    #[TestDox('Entrar y salir quedan anotados con la persona y su IP')]
    public function test_login_and_logout(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'password']);
        $this->post(route('logout'));

        $this->assertSame(['login', 'logout'], AuditLog::query()->orderBy('id')->pluck('event')->all());
        $this->assertSame($user->id, AuditLog::query()->where('event', 'login')->firstOrFail()->actor_id);
        $this->assertNotNull(AuditLog::query()->where('event', 'login')->firstOrFail()->ip);
    }

    #[TestDox('Un acceso fallido se anota con el correo escrito, pero NUNCA con la contraseña')]
    public function test_failed_login_never_stores_password(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);

        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'CLAVE-EQUIVOCADA-999']);

        $log = AuditLog::query()->where('event', 'login_failed')->firstOrFail();
        $this->assertSame('ana@example.com', $log->properties['email']);
        $this->assertStringNotContainsString('CLAVE-EQUIVOCADA-999', json_encode($log->getAttributes()));
    }

    #[TestDox('Tras 5 fallos, el siguiente intento se anota como acceso bloqueado (R20)')]
    public function test_lockout_is_logged(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('login.store'), ['email' => 'nadie@example.com', 'password' => 'mal']);
        }

        $this->assertSame(5, AuditLog::query()->where('event', 'login_failed')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'login_locked')->count());
    }

    #[TestDox('Invitar a alguien anota quién lo invitó y a quién')]
    public function test_invitation_is_logged(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $invited = app(InviteUser::class)->handle('Nuevo', 'nuevo@example.com', UserRole::Agent, team: Team::Support);

        $log = AuditLog::query()->where('event', 'invited')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($invited->id, $log->subject_id);
    }

    #[TestDox('Activar la cuenta con el enlace de invitación se anota como «activated»; recuperar contraseña, como «password_reset»')]
    public function test_activation_and_reset_are_logged(): void
    {
        $invited = app(InviteUser::class)->handle('Nuevo', 'nuevo@example.com', UserRole::Agent, team: Team::Support);
        $token = Password::broker('invitations')->createToken($invited);

        $this->post(route('invitation.store'), ['token' => $token, 'email' => 'nuevo@example.com', 'password' => 'una-frase-bien-larga-1', 'password_confirmation' => 'una-frase-bien-larga-1']);
        $this->assertSame(1, AuditLog::query()->where('event', 'activated')->count());

        $token = Password::broker('users')->createToken($invited->fresh());
        $this->post(route('password.update'), ['token' => $token, 'email' => 'nuevo@example.com', 'password' => 'otra-frase-bien-larga-2', 'password_confirmation' => 'otra-frase-bien-larga-2']);
        $this->assertSame(1, AuditLog::query()->where('event', 'password_reset')->count());
    }

    #[TestDox('La pantalla filtra por hecho y por texto')]
    public function test_screen_filters(): void
    {
        $admin = User::factory()->admin()->create();
        AuditLog::create(['event' => 'login', 'description' => 'Ha entrado en la aplicación.', 'actor_id' => $admin->id]);
        AuditLog::create(['event' => 'backup_requested', 'description' => 'Ha pedido una copia de seguridad de la base de datos.', 'actor_id' => $admin->id]);

        $this->actingAs($admin)->get(route('records.audit', ['evento' => 'backup_requested']))
            ->assertSee('Ha pedido una copia')->assertDontSee('Ha entrado en la aplicación');
        $this->actingAs($admin)->get(route('records.audit', ['q' => $admin->email]))->assertSee('Ha entrado en la aplicación');
        $this->actingAs($admin)->get(route('records.audit', ['evento' => 'inventado']))->assertSessionHasErrors('evento');
    }

    #[TestDox('Un fallo al escribir en la auditoría no rompe la acción que se estaba haciendo')]
    public function test_audit_failure_does_not_break_login(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);
        Schema::drop('audit_logs');

        $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'password'])->assertRedirect(route('panel'));
    }
}
