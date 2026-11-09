<?php

/*
 * «He olvidado mi contraseña». Reglas: R17 (la respuesta no revela qué cuentas existen), enlace de
 * 60 minutos y de un solo uso, mínimo de 12 caracteres.
 * Cuándo tocarlo: si cambia la recuperación. Si falla un test, se arregla el código o se discute la
 * regla con la usuaria; NO se adapta el test.
 */

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function pedirEnlace(string $correo)
    {
        return $this->post(route('password.email'), ['email' => $correo]);
    }

    private function guardar(string $token, string $correo, string $clave = 'una frase larga y segura', ?string $repite = null)
    {
        return $this->post(route('password.update'), [
            'token' => $token, 'email' => $correo,
            'password' => $clave, 'password_confirmation' => $repite ?? $clave,
        ]);
    }

    #[TestDox('Pedir el enlace con un correo real envía el correo y lleva a «Revisa tu correo»')]
    public function test_link_is_sent_to_existing_user(): void
    {
        Notification::fake();
        $usuario = User::factory()->create();

        $this->pedirEnlace($usuario->email)->assertRedirect(route('password.sent'));

        Notification::assertSentTo($usuario, ResetPasswordNotification::class);
    }

    #[TestDox('Con un correo que no existe la respuesta es IDÉNTICA y no se envía nada (R17)')]
    public function test_unknown_email_gets_identical_response(): void
    {
        Notification::fake();
        $usuario = User::factory()->create();

        $real = $this->pedirEnlace($usuario->email);
        $falso = $this->pedirEnlace('nadie@example.test');

        $this->assertSame($real->getStatusCode(), $falso->getStatusCode());
        $this->assertSame($real->headers->get('Location'), $falso->headers->get('Location'));
        Notification::assertSentToTimes($usuario, ResetPasswordNotification::class, 1);
        Notification::assertCount(1); // al correo falso no se envió nada
    }

    #[TestDox('Pedir el enlace dos veces seguidas no cambia la respuesta (no delata «ya te lo envié»)')]
    public function test_repeated_request_does_not_reveal_existence(): void
    {
        Notification::fake();
        $usuario = User::factory()->create();

        $primera = $this->pedirEnlace($usuario->email);
        $segunda = $this->pedirEnlace($usuario->email);

        $this->assertSame($primera->headers->get('Location'), $segunda->headers->get('Location'));
        $segunda->assertSessionHasNoErrors();
    }

    #[TestDox('A una cuenta dada de baja no se le envía el enlace, pero la respuesta es la misma (R17)')]
    public function test_deactivated_user_gets_no_email(): void
    {
        Notification::fake();
        $usuario = User::factory()->deactivated()->create();

        $respuesta = $this->pedirEnlace($usuario->email);

        $respuesta->assertRedirect(route('password.sent'));
        Notification::assertNothingSent();
    }

    #[TestDox('A una cuenta invitada sin activar no se le envía recuperación (debe usar su invitación)')]
    public function test_pending_invitation_gets_no_reset_email(): void
    {
        Notification::fake();
        $usuario = User::factory()->invited()->create();

        $this->pedirEnlace($usuario->email)->assertRedirect(route('password.sent'));

        Notification::assertNothingSent();
    }

    #[TestDox('La pantalla de «enviado» no muestra el correo escrito')]
    public function test_sent_page_does_not_echo_the_email(): void
    {
        $this->followingRedirects()->pedirEnlace('secreto@example.test')
            ->assertOk()->assertDontSee('secreto@example.test')->assertSee('Revisa tu correo');
    }

    #[TestDox('Con el enlace correcto se cambia la contraseña y ya se puede entrar con la nueva')]
    public function test_password_can_be_reset_with_valid_token(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker('users')->createToken($usuario);

        $this->guardar($token, $usuario->email, 'mi nueva frase de acceso')->assertRedirect(route('login'));

        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => 'mi nueva frase de acceso'])
            ->assertRedirect(route('panel'));
        $this->assertAuthenticatedAs($usuario);
    }

    #[TestDox('La contraseña anterior deja de valer tras cambiarla')]
    public function test_old_password_stops_working(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker('users')->createToken($usuario);
        $this->guardar($token, $usuario->email);

        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => 'password']);

        $this->assertGuest();
    }

    #[TestDox('El enlace es de un solo uso: la segunda vez se rechaza')]
    public function test_token_is_single_use(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker('users')->createToken($usuario);

        $this->guardar($token, $usuario->email, 'primera contraseña larga')->assertRedirect(route('login'));
        $segunda = $this->guardar($token, $usuario->email, 'otra contraseña distinta');

        $segunda->assertSessionHasErrors('password');
        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => 'otra contraseña distinta']);
        $this->assertGuest();
    }

    #[TestDox('El enlace caduca a los 60 minutos y deja de valer')]
    public function test_token_expires_after_sixty_minutes(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker('users')->createToken($usuario);

        $this->travel(61)->minutes();
        $this->guardar($token, $usuario->email)->assertSessionHasErrors('password');

        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => 'una frase larga y segura']);
        $this->assertGuest();
    }

    #[TestDox('A los 59 minutos el enlace todavía vale')]
    public function test_token_still_valid_before_sixty_minutes(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker('users')->createToken($usuario);

        $this->travel(59)->minutes();

        $this->guardar($token, $usuario->email)->assertRedirect(route('login'));
    }

    #[TestDox('Una contraseña de 11 caracteres se rechaza; el mínimo es 12')]
    public function test_password_below_twelve_characters_is_rejected(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker('users')->createToken($usuario);

        $this->guardar($token, $usuario->email, '12345678901')->assertSessionHasErrors('password');
        $this->guardar($token, $usuario->email, '123456789012')->assertRedirect(route('login'));
    }

    #[TestDox('Si las dos contraseñas no coinciden, se rechaza')]
    public function test_confirmation_must_match(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker('users')->createToken($usuario);

        $this->guardar($token, $usuario->email, 'una frase larga y segura', 'una frase larga y distinta')
            ->assertSessionHasErrors('password');
    }

    #[TestDox('Un enlace con el correo de OTRA cuenta no sirve')]
    public function test_token_does_not_work_with_another_email(): void
    {
        $dueno = User::factory()->create();
        $otro = User::factory()->create();
        $token = Password::broker('users')->createToken($dueno);

        $this->guardar($token, $otro->email)->assertSessionHasErrors('password');

        $this->post(route('login.store'), ['email' => $otro->email, 'password' => 'una frase larga y segura']);
        $this->assertGuest();
    }

    #[TestDox('Un enlace inventado da el mismo mensaje que uno caducado (R17)')]
    public function test_invalid_and_expired_tokens_give_same_message(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker('users')->createToken($usuario);

        $this->guardar('token-inventado', $usuario->email);
        $inventado = session('errors');
        $mensajeInventado = $inventado instanceof ViewErrorBag ? $inventado->getBag('default')->first('password') : ($inventado['default']['messages']['password'][0] ?? '');
        $this->flushSession();

        $this->travel(61)->minutes();
        $this->guardar($token, $usuario->email);
        $caducado = session('errors');
        $mensajeCaducado = $caducado instanceof ViewErrorBag ? $caducado->getBag('default')->first('password') : ($caducado['default']['messages']['password'][0] ?? '');

        $this->assertNotSame('', $mensajeInventado);
        $this->assertSame($mensajeInventado, $mensajeCaducado);
    }

    #[TestDox('Un enlace de recuperación de una cuenta dada de baja no la reactiva ni le deja cambiar la contraseña')]
    public function test_deactivated_account_cannot_reset(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker('users')->createToken($usuario);
        $usuario->forceFill(['deactivated_at' => now()])->save(); // se da de baja después de pedir el enlace

        $this->guardar($token, $usuario->email)->assertSessionHasErrors('password');
    }

    #[TestDox('El correo de recuperación está en español, lleva el enlace y avisa de los 60 minutos')]
    public function test_reset_email_content(): void
    {
        $usuario = User::factory()->create(['name' => 'María Suárez']);

        $correo = (new ResetPasswordNotification('TOKEN123'))->toMail($usuario);
        $texto = implode("\n", array_merge($correo->introLines, $correo->outroLines)).' '.$correo->actionUrl;

        $this->assertSame('Crea una contraseña nueva', $correo->subject);
        $this->assertStringContainsString('TOKEN123', $correo->actionUrl);
        $this->assertStringContainsString(rawurlencode($usuario->email), $correo->actionUrl);
        $this->assertStringContainsString('60 minutos', $texto);
        $this->assertStringContainsString('solo se puede usar una vez', $texto);
    }
}
