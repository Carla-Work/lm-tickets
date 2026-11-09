<?php

/*
 * Inicio y cierre de sesión. Reglas: R17 (errores genéricos) y R20 (bloqueo a los 5 intentos).
 * Cuándo tocarlo: si cambia cómo se entra. Si falla un test, se arregla el código o se discute la
 * regla con la usuaria; NO se adapta el test.
 */

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('x'); // cada test parte de un limitador limpio (la caché de tests es en memoria)
    }

    private function intentar(string $correo, string $clave)
    {
        return $this->post(route('login.store'), ['email' => $correo, 'password' => $clave]);
    }

    private function mensajeDeError(): string
    {
        // Según se haya llamado antes a assertSessionHasErrors, los errores llegan como objeto o
        // como array serializado. Se aceptan los dos.
        $errores = session('errors');

        return $errores instanceof ViewErrorBag
            ? (string) $errores->getBag('default')->first('email')
            : (string) ($errores['default']['messages']['email'][0] ?? '');
    }

    #[TestDox('Un usuario con cuenta activa entra y llega al panel')]
    public function test_active_user_can_log_in(): void
    {
        $usuario = User::factory()->create();

        $this->intentar($usuario->email, 'password')->assertRedirect(route('panel'));

        $this->assertAuthenticatedAs($usuario);
    }

    #[TestDox('Un empleado (agente) también entra por la misma puerta')]
    public function test_agent_can_log_in(): void
    {
        $agente = User::factory()->agent()->create();

        $this->intentar($agente->email, 'password')->assertRedirect(route('panel'));

        $this->assertAuthenticatedAs($agente);
    }

    #[TestDox('Con la contraseña mala no entra y el mensaje es el genérico (R17)')]
    public function test_wrong_password_gives_generic_error(): void
    {
        $usuario = User::factory()->create();

        $this->intentar($usuario->email, 'mala')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(trans('auth.failed'), $this->mensajeDeError());
    }

    #[TestDox('Un correo que no existe da EXACTAMENTE el mismo mensaje que una contraseña mala (R17)')]
    public function test_unknown_email_gives_same_error_as_wrong_password(): void
    {
        $usuario = User::factory()->create();

        $this->intentar($usuario->email, 'mala');
        $conCuentaReal = $this->mensajeDeError();
        $this->flushSession();

        $this->intentar('no-existe@example.test', 'mala');
        $sinCuenta = $this->mensajeDeError();

        $this->assertSame($conCuentaReal, $sinCuenta);
    }

    #[TestDox('Una cuenta dada de baja no entra, ni con la contraseña buena, y el mensaje es el genérico (R17)')]
    public function test_deactivated_account_cannot_log_in(): void
    {
        $usuario = User::factory()->deactivated()->create();

        $this->intentar($usuario->email, 'password');

        $this->assertGuest();
        $this->assertSame(trans('auth.failed'), $this->mensajeDeError());
    }

    #[TestDox('Una cuenta invitada que aún no ha elegido contraseña no entra (R18)')]
    public function test_pending_invitation_cannot_log_in(): void
    {
        $usuario = User::factory()->invited()->create();

        $this->intentar($usuario->email, 'password');

        $this->assertGuest();
        $this->assertSame(trans('auth.failed'), $this->mensajeDeError());
    }

    #[TestDox('Un usuario de una empresa dada de baja no entra aunque su cuenta siga activa')]
    public function test_user_of_deactivated_company_cannot_log_in(): void
    {
        $empresa = Company::factory()->deactivated()->create();
        $usuario = User::factory()->forCompany($empresa)->create();

        $this->intentar($usuario->email, 'password');

        $this->assertGuest();
        $this->assertSame(trans('auth.failed'), $this->mensajeDeError());
    }

    #[TestDox('Tras 5 intentos fallidos el sexto se rechaza aunque lleve la contraseña CORRECTA (R20)')]
    public function test_account_is_locked_after_five_failed_attempts(): void
    {
        $usuario = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->intentar($usuario->email, 'mala');
        }
        $this->intentar($usuario->email, 'password');

        $this->assertGuest();
        $this->assertStringContainsString('Demasiados intentos', $this->mensajeDeError());
    }

    #[TestDox('Con 4 intentos fallidos todavía se puede entrar (el bloqueo es a los 5)')]
    public function test_four_failures_do_not_lock(): void
    {
        $usuario = User::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $this->intentar($usuario->email, 'mala');
        }
        $this->intentar($usuario->email, 'password')->assertRedirect(route('panel'));

        $this->assertAuthenticatedAs($usuario);
    }

    #[TestDox('El bloqueo termina pasado el tiempo y se puede volver a entrar')]
    public function test_lockout_expires(): void
    {
        $usuario = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->intentar($usuario->email, 'mala');
        }

        $this->travel((int) config('access.login.lockout_seconds') + 5)->seconds();
        $this->intentar($usuario->email, 'password')->assertRedirect(route('panel'));

        $this->assertAuthenticatedAs($usuario);
    }

    #[TestDox('El bloqueo cuenta también los correos que no existen (no delata qué cuentas hay, R17)')]
    public function test_lockout_also_applies_to_unknown_emails(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->intentar('fantasma@example.test', 'mala');
        }
        $this->intentar('fantasma@example.test', 'mala');

        $this->assertStringContainsString('Demasiados intentos', $this->mensajeDeError());
    }

    #[TestDox('Bloquear a una persona no bloquea a otra con distinto correo')]
    public function test_lockout_is_per_email(): void
    {
        $uno = User::factory()->create();
        $otro = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->intentar($uno->email, 'mala');
        }

        $this->intentar($otro->email, 'password')->assertRedirect(route('panel'));

        $this->assertAuthenticatedAs($otro);
    }

    #[TestDox('Entrar bien borra los fallos acumulados')]
    public function test_successful_login_clears_failures(): void
    {
        $usuario = User::factory()->create();
        for ($i = 0; $i < 4; $i++) {
            $this->intentar($usuario->email, 'mala');
        }
        $this->intentar($usuario->email, 'password');
        $this->post(route('logout'));

        for ($i = 0; $i < 4; $i++) {
            $this->intentar($usuario->email, 'mala');
        }
        $this->intentar($usuario->email, 'password')->assertRedirect(route('panel'));
    }

    #[TestDox('Salir cierra la sesión y lleva a la pantalla de acceso')]
    public function test_logout(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[TestDox('Salir solo funciona por POST: un enlace no cierra la sesión')]
    public function test_logout_is_not_available_by_get(): void
    {
        $this->actingAs(User::factory()->create())->get('/salir')->assertStatus(405);
    }

    #[TestDox('Sin sesión, el panel lleva a la pantalla de acceso')]
    public function test_panel_requires_login(): void
    {
        $this->get(route('panel'))->assertRedirect(route('login'));
    }

    #[TestDox('Con la sesión iniciada, abrir /acceso lleva directo al panel')]
    public function test_logged_in_user_is_redirected_from_login(): void
    {
        $this->actingAs(User::factory()->create())->get(route('login'))->assertRedirect(route('panel'));
    }

    #[TestDox('La pantalla de acceso se ve en español y sin casilla «Recordarme»')]
    public function test_login_page_has_no_remember_me(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Iniciar sesión')
            ->assertSee('¿Olvidaste tu contraseña?')
            ->assertDontSee('Recordarme');
    }
}
