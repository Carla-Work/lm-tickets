<?php

/*
 * Segundo factor (2FA) del administrador. Regla R21 (más R17 errores genéricos y R20 bloqueo).
 * Cuándo tocarlo: si cambia el segundo factor. Si falla un test, se arregla el código o se discute la
 * regla con la usuaria; NO se adapta el test. El algoritmo TOTP en sí se prueba aparte (TotpTest, con
 * los vectores oficiales del RFC); aquí se prueba cómo se ENCADENA con el acceso.
 */

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\RecoveryCodeUsedNotification;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function totp(): Totp
    {
        return new Totp;
    }

    /** Un administrador con el segundo factor YA activado. Devuelve [usuario, clave, códigos de recuperación en claro]. */
    private function adminConDosFactores(): array
    {
        $secreto = Totp::generateSecret();
        $codigos = RecoveryCodes::generate(8);
        $admin = User::factory()->admin()->create();
        $admin->forceFill([
            'two_factor_secret' => $secreto,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => RecoveryCodes::hashAll($codigos),
        ])->save();

        return [$admin->fresh(), $secreto, $codigos];
    }

    private function entrar(User $usuario, string $clave = 'password')
    {
        return $this->post(route('login.store'), ['email' => $usuario->email, 'password' => $clave]);
    }

    /**
     * Deja a `$usuario` en el acceso «a medias» sin pasar por el formulario. Hace falta porque un
     * administrador sin segundo factor ya entra directo (es opcional) y las pantallas de activación solo
     * se abren con ese acceso a medias. La clave de sesión es la de PendingLogin::KEY.
     */
    private function accesoAMedias(User $usuario): void
    {
        $this->withSession(['login.pending' => ['id' => $usuario->id, 'at' => now()->timestamp]]);
    }

    private function codigoActual(string $secreto, int $desfaseSegundos = 0): string
    {
        return $this->totp()->code($secreto, now()->timestamp + $desfaseSegundos);
    }

    /** Un código de 6 dígitos que seguro NO es válido ahora (ni en los intervalos vecinos). */
    private function codigoMalo(string $secreto): string
    {
        $validos = [-60, -30, 0, 30, 60];
        $validos = array_map(fn ($s) => $this->codigoActual($secreto, $s), $validos);
        foreach (['000000', '111111', '222222', '333333'] as $candidato) {
            if (! in_array($candidato, $validos, true)) {
                return $candidato;
            }
        }
        $this->fail('no se encontró un código malo');
    }

    private function mensaje(string $campo): string
    {
        $errores = session('errors');

        return $errores instanceof ViewErrorBag
            ? (string) $errores->getBag('default')->first($campo)
            : (string) ($errores['default']['messages'][$campo][0] ?? '');
    }

    // ---------- el administrador no queda autenticado tras la contraseña ----------

    #[TestDox('Un administrador que acierta la contraseña NO queda autenticado: va al segundo factor (R21)')]
    public function test_admin_is_not_authenticated_after_password(): void
    {
        [$admin] = $this->adminConDosFactores();

        $this->entrar($admin)->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->get(route('panel'))->assertRedirect(route('login'));
    }

    #[TestDox('Un administrador SIN el segundo factor activado entra solo con la contraseña (es opcional)')]
    public function test_admin_without_two_factor_logs_in_with_password_only(): void
    {
        $admin = User::factory()->admin()->create();

        $this->entrar($admin)->assertRedirect(route('panel'));

        $this->assertAuthenticatedAs($admin);
    }

    #[TestDox('Un agente o un cliente entran sin segundo factor (solo lo exige el administrador)')]
    public function test_only_admin_requires_two_factor(): void
    {
        foreach ([User::factory()->agent()->create(), User::factory()->create(), User::factory()->companyAdmin()->create()] as $usuario) {
            $this->assertFalse($usuario->requiresTwoFactor());
            $this->entrar($usuario)->assertRedirect(route('panel'));
            $this->assertAuthenticatedAs($usuario);
            $this->post(route('logout'));
        }
    }

    #[TestDox('Con la contraseña mala de un administrador no hay acceso «a medias» ni se llega al segundo factor')]
    public function test_wrong_password_creates_no_pending_login(): void
    {
        [$admin] = $this->adminConDosFactores();

        $this->entrar($admin, 'mala');
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    #[TestDox('Las pantallas del segundo factor sin acceso «a medias» llevan a la pantalla de acceso')]
    public function test_two_factor_pages_require_pending_login(): void
    {
        foreach (['two-factor.setup', 'two-factor.codes', 'two-factor.challenge', 'two-factor.recovery'] as $ruta) {
            $this->get(route($ruta))->assertRedirect(route('login'));
        }
        $this->post(route('two-factor.verify'), ['codigo' => '123456'])->assertRedirect(route('login'));
    }

    #[TestDox('El acceso «a medias» caduca a los 10 minutos')]
    public function test_pending_login_expires(): void
    {
        [$admin] = $this->adminConDosFactores();
        $this->entrar($admin);

        $this->travel(11)->minutes();

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    #[TestDox('Si dan de baja al administrador entre la contraseña y el código, el acceso «a medias» deja de valer')]
    public function test_pending_login_is_invalid_if_account_is_deactivated(): void
    {
        [$admin] = $this->adminConDosFactores();
        $this->entrar($admin);
        $admin->forceFill(['deactivated_at' => now()])->save();

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    // ---------- activar (primera vez) ----------

    #[TestDox('La pantalla de activación muestra la clave y el enlace otpauth, y no hay código QR')]
    public function test_setup_page_shows_key_and_link_without_qr(): void
    {
        $admin = User::factory()->admin()->create();
        $this->accesoAMedias($admin);

        $respuesta = $this->get(route('two-factor.setup'))->assertOk()->assertSee('Activa la verificación en dos pasos');
        $clave = $admin->fresh()->two_factor_secret;

        $respuesta->assertSee(trim(chunk_split($clave, 4, ' ')));
        $respuesta->assertSee('otpauth://totp/', false);
        $respuesta->assertDontSee('<canvas', false)->assertDontSee('<svg class="qr', false);
    }

    #[TestDox('La clave del segundo factor se guarda CIFRADA: en la base de datos no aparece en claro')]
    public function test_secret_is_stored_encrypted(): void
    {
        $admin = User::factory()->admin()->create();
        $this->accesoAMedias($admin);
        $this->get(route('two-factor.setup'));

        $enClaro = $admin->fresh()->two_factor_secret;
        $crudo = DB::table('users')->where('id', $admin->id)->value('two_factor_secret');

        $this->assertNotNull($enClaro);
        $this->assertNotSame($enClaro, $crudo, 'la base de datos no debe guardar la clave tal cual');
        $this->assertStringNotContainsString($enClaro, (string) $crudo);
        $this->assertSame($enClaro, Crypt::decryptString($crudo), 'y se descifra con la clave de la aplicación');
    }

    #[TestDox('Un código incorrecto al activar no lo activa y da el mensaje genérico (R17)')]
    public function test_wrong_code_at_setup_does_not_enable(): void
    {
        $admin = User::factory()->admin()->create();
        $this->accesoAMedias($admin);
        $this->get(route('two-factor.setup'));

        $this->post(route('two-factor.setup.confirm'), ['codigo' => $this->codigoMalo($admin->fresh()->two_factor_secret)])->assertSessionHasErrors('codigo');

        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());
        $this->assertSame(trans('auth.two_factor_failed'), $this->mensaje('codigo'));
        $this->assertGuest();
    }

    #[TestDox('El flujo completo de activación: código correcto, 8 códigos de recuperación, «he guardado» y se entra')]
    public function test_full_setup_flow(): void
    {
        $admin = User::factory()->admin()->create();
        $this->accesoAMedias($admin);
        $this->get(route('two-factor.setup'));
        $secreto = $admin->fresh()->two_factor_secret;

        $this->post(route('two-factor.setup.confirm'), ['codigo' => $this->codigoActual($secreto)])->assertRedirect(route('two-factor.codes'));
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
        $this->assertGuest(); // todavía no: falta confirmar que ha guardado los códigos

        $pagina = $this->get(route('two-factor.codes'))->assertOk()->assertSee('Guarda tus códigos de recuperación');
        preg_match_all('/\b[A-Z2-9]{4}-[A-Z2-9]{4}\b/', $pagina->getContent(), $coincidencias);
        $this->assertCount(8, array_unique($coincidencias[0]));

        $this->post(route('two-factor.finish'), ['guardados' => '1'])->assertRedirect(route('panel'));
        $this->assertAuthenticatedAs($admin);
    }

    #[TestDox('Los códigos de recuperación se guardan con hash: la base de datos no contiene ninguno en claro')]
    public function test_recovery_codes_are_stored_hashed(): void
    {
        $admin = User::factory()->admin()->create();
        $this->accesoAMedias($admin);
        $this->get(route('two-factor.setup'));
        $this->post(route('two-factor.setup.confirm'), ['codigo' => $this->codigoActual($admin->fresh()->two_factor_secret)]);

        $pagina = $this->get(route('two-factor.codes'))->getContent();
        preg_match_all('/\b[A-Z2-9]{4}-[A-Z2-9]{4}\b/', $pagina, $m);
        $crudo = (string) DB::table('users')->where('id', $admin->id)->value('two_factor_recovery_codes');

        $this->assertCount(8, json_decode($crudo, true));
        foreach ($m[0] as $codigo) {
            $this->assertStringNotContainsString($codigo, $crudo);
            $this->assertStringNotContainsString(str_replace('-', '', $codigo), $crudo);
        }
    }

    #[TestDox('No se puede continuar sin marcar «He guardado estos códigos»')]
    public function test_finish_requires_the_checkbox(): void
    {
        $admin = User::factory()->admin()->create();
        $this->accesoAMedias($admin);
        $this->get(route('two-factor.setup'));
        $this->post(route('two-factor.setup.confirm'), ['codigo' => $this->codigoActual($admin->fresh()->two_factor_secret)]);

        $this->post(route('two-factor.finish'), [])->assertSessionHasErrors('guardados');

        $this->assertGuest();
    }

    #[TestDox('Los códigos de recuperación solo se ven una vez: tras continuar no se pueden volver a abrir')]
    public function test_recovery_codes_cannot_be_viewed_again(): void
    {
        $admin = User::factory()->admin()->create();
        $this->accesoAMedias($admin);
        $this->get(route('two-factor.setup'));
        $this->post(route('two-factor.setup.confirm'), ['codigo' => $this->codigoActual($admin->fresh()->two_factor_secret)]);
        $this->post(route('two-factor.finish'), ['guardados' => '1']);

        $this->get(route('two-factor.codes'))->assertRedirect(); // ya no hay codigos: no se muestran
        $this->assertStringNotContainsString('Guarda tus códigos', (string) $this->get(route('two-factor.codes'))->getContent());
    }

    #[TestDox('Si el segundo factor ya está activado, la pantalla de activación lleva a verificar (no se puede reactivar con otra clave)')]
    public function test_setup_is_not_available_when_already_enabled(): void
    {
        [$admin] = $this->adminConDosFactores();
        $this->entrar($admin);

        $this->get(route('two-factor.setup'))->assertRedirect(route('two-factor.challenge'));
    }

    // ---------- verificar al entrar ----------

    #[TestDox('Con la contraseña y el código correctos el administrador entra')]
    public function test_admin_logs_in_with_valid_code(): void
    {
        [$admin, $secreto] = $this->adminConDosFactores();
        $this->entrar($admin);

        $this->post(route('two-factor.verify'), ['codigo' => $this->codigoActual($secreto)])->assertRedirect(route('panel'));

        $this->assertAuthenticatedAs($admin);
    }

    #[TestDox('Un código incorrecto no deja entrar y el mensaje es el genérico (R17)')]
    public function test_wrong_code_does_not_log_in(): void
    {
        [$admin, $secreto] = $this->adminConDosFactores();
        $this->entrar($admin);

        $this->post(route('two-factor.verify'), ['codigo' => $this->codigoMalo($secreto)])->assertSessionHasErrors('codigo');

        $this->assertGuest();
        $this->assertSame(trans('auth.two_factor_failed'), $this->mensaje('codigo'));
    }

    #[TestDox('Un código caducado da EXACTAMENTE el mismo mensaje que uno incorrecto (R17)')]
    public function test_expired_and_wrong_codes_give_same_message(): void
    {
        [$admin, $secreto] = $this->adminConDosFactores();
        $this->entrar($admin);

        $this->post(route('two-factor.verify'), ['codigo' => $this->codigoMalo($secreto)]);
        $malo = $this->mensaje('codigo');
        $this->flushSession();
        $this->entrar($admin);

        $this->post(route('two-factor.verify'), ['codigo' => $this->codigoActual($secreto, -300)]); // de hace 5 minutos
        $this->assertSame($malo, $this->mensaje('codigo'));
        $this->assertGuest();
    }

    #[TestDox('Se acepta el código del intervalo anterior (desfase de reloj) pero no el de hace 90 segundos')]
    public function test_clock_drift_window(): void
    {
        [$admin, $secreto] = $this->adminConDosFactores();
        $this->entrar($admin);
        $this->post(route('two-factor.verify'), ['codigo' => $this->codigoActual($secreto, -90)])->assertSessionHasErrors('codigo');

        $this->post(route('two-factor.verify'), ['codigo' => $this->codigoActual($secreto, -30)])->assertRedirect(route('panel'));
        $this->assertAuthenticatedAs($admin);
    }

    #[TestDox('El mismo código no sirve dos veces: tras usarlo, volver a entrar con él se rechaza (reutilización)')]
    public function test_code_cannot_be_reused(): void
    {
        [$admin, $secreto] = $this->adminConDosFactores();
        $codigo = $this->codigoActual($secreto);

        $this->entrar($admin);
        $this->post(route('two-factor.verify'), ['codigo' => $codigo])->assertRedirect(route('panel'));
        $this->post(route('logout'));

        $this->entrar($admin);
        $this->post(route('two-factor.verify'), ['codigo' => $codigo])->assertSessionHasErrors('codigo');
        $this->assertGuest();

        $this->travel(30)->seconds(); // llega el siguiente código
        $this->post(route('two-factor.verify'), ['codigo' => $this->codigoActual($secreto)])->assertRedirect(route('panel'));
        $this->assertAuthenticatedAs($admin);
    }

    // ---------- bloqueo (R20) ----------

    #[TestDox('Tras 5 códigos incorrectos el sexto se rechaza aunque sea el CORRECTO (R20)')]
    public function test_locked_after_five_wrong_codes(): void
    {
        [$admin, $secreto] = $this->adminConDosFactores();
        $this->entrar($admin);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('two-factor.verify'), ['codigo' => $this->codigoMalo($secreto)]);
        }

        $this->post(route('two-factor.verify'), ['codigo' => $this->codigoActual($secreto)])->assertSessionHasErrors('codigo');

        $this->assertGuest();
        $this->assertStringContainsString('Demasiados intentos', $this->mensaje('codigo'));
    }

    #[TestDox('Volver a poner la contraseña NO reinicia el contador de fallos del código (si no, el bloqueo no serviría)')]
    public function test_re_entering_password_does_not_reset_the_counter(): void
    {
        [$admin, $secreto] = $this->adminConDosFactores();
        $this->entrar($admin);
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('two-factor.verify'), ['codigo' => $this->codigoMalo($secreto)]);
        }

        $this->flushSession();
        $this->entrar($admin); // contraseña buena otra vez
        $this->post(route('two-factor.verify'), ['codigo' => $this->codigoMalo($secreto)]); // el 5.º fallo

        $this->post(route('two-factor.verify'), ['codigo' => $this->codigoActual($secreto)])->assertSessionHasErrors('codigo');
        $this->assertGuest();
    }

    #[TestDox('Con el administrador bloqueado ni siquiera se llega a mirar su contraseña')]
    public function test_locked_admin_cannot_even_pass_the_password_step(): void
    {
        [$admin, $secreto] = $this->adminConDosFactores();
        $this->entrar($admin);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('two-factor.verify'), ['codigo' => $this->codigoMalo($secreto)]);
        }
        $this->flushSession();

        $this->entrar($admin)->assertSessionHasErrors('email');
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    #[TestDox('Acceder con éxito borra los fallos acumulados')]
    public function test_success_clears_failures(): void
    {
        [$admin, $secreto] = $this->adminConDosFactores();
        $this->entrar($admin);
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('two-factor.verify'), ['codigo' => $this->codigoMalo($secreto)]);
        }
        $this->post(route('two-factor.verify'), ['codigo' => $this->codigoActual($secreto)])->assertRedirect(route('panel'));
        $this->post(route('logout'));

        $this->travel(30)->seconds();
        $this->entrar($admin);
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('two-factor.verify'), ['codigo' => $this->codigoMalo($secreto)]);
        }
        $this->post(route('two-factor.verify'), ['codigo' => $this->codigoActual($secreto)])->assertRedirect(route('panel'));
    }

    // ---------- códigos de recuperación ----------

    #[TestDox('Un código de recuperación deja entrar, se gasta y avisa por correo')]
    public function test_recovery_code_logs_in_and_is_consumed(): void
    {
        Notification::fake();
        [$admin, , $codigos] = $this->adminConDosFactores();
        $this->entrar($admin);

        $this->post(route('two-factor.recovery.use'), ['codigo' => $codigos[0]])->assertRedirect(route('panel'));

        $this->assertAuthenticatedAs($admin);
        $this->assertSame(7, $admin->fresh()->remainingRecoveryCodes());
        Notification::assertSentTo($admin, RecoveryCodeUsedNotification::class, fn ($n) => $n->remaining === 7);
    }

    #[TestDox('Un código de recuperación sirve UNA sola vez')]
    public function test_recovery_code_is_single_use(): void
    {
        Notification::fake();
        [$admin, , $codigos] = $this->adminConDosFactores();
        $this->entrar($admin);
        $this->post(route('two-factor.recovery.use'), ['codigo' => $codigos[0]])->assertRedirect(route('panel'));
        $this->post(route('logout'));

        $this->entrar($admin);
        $this->post(route('two-factor.recovery.use'), ['codigo' => $codigos[0]])->assertSessionHasErrors('codigo');

        $this->assertGuest();
        $this->assertSame(7, $admin->fresh()->remainingRecoveryCodes());
    }

    #[TestDox('El código de recuperación se acepta en minúsculas, sin guion y con espacios')]
    public function test_recovery_code_input_is_normalised(): void
    {
        Notification::fake();
        [$admin, , $codigos] = $this->adminConDosFactores();
        $this->entrar($admin);

        $escrito = ' '.strtolower(str_replace('-', ' ', $codigos[3])).' ';
        $this->post(route('two-factor.recovery.use'), ['codigo' => $escrito])->assertRedirect(route('panel'));
    }

    #[TestDox('Un código de recuperación inventado da el mensaje genérico y cuenta para el bloqueo')]
    public function test_invalid_recovery_code_counts_for_lockout(): void
    {
        [$admin, $secreto] = $this->adminConDosFactores();
        $this->entrar($admin);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('two-factor.recovery.use'), ['codigo' => 'AAAA-BBBB']);
        }
        $this->assertSame(trans('auth.two_factor_failed'), $this->mensaje('codigo'));

        $this->post(route('two-factor.verify'), ['codigo' => $this->codigoActual($secreto)])->assertSessionHasErrors('codigo');
        $this->assertGuest();
    }

    #[TestDox('La pantalla de recuperación dice cuántos códigos quedan')]
    public function test_recovery_page_shows_remaining(): void
    {
        [$admin] = $this->adminConDosFactores();
        $this->entrar($admin);

        $this->get(route('two-factor.recovery'))->assertOk()->assertSee('Te quedan 8 códigos sin usar');
    }

    // ---------- el rol y la sesión ----------

    #[TestDox('Un administrador SIN segundo factor activado con la sesión abierta (p. ej. recién ascendido) NO es expulsado: el segundo factor es opcional')]
    public function test_admin_without_two_factor_keeps_session(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('inbox'))->assertOk(); // /panel solo redirige a la bandeja desde el paso 4b

        $this->assertAuthenticatedAs($admin);
    }

    #[TestDox('Un administrador CON segundo factor activado con la sesión abierta usa el panel con normalidad')]
    public function test_admin_with_two_factor_can_use_panel(): void
    {
        [$admin] = $this->adminConDosFactores();

        $this->actingAs($admin)->get(route('inbox'))->assertOk(); // /panel solo redirige a la bandeja desde el paso 4b
    }

    #[TestDox('El traspaso del rol limpia el segundo factor de quien deja de ser administrador')]
    public function test_clear_two_factor_wipes_everything(): void
    {
        [$admin] = $this->adminConDosFactores();
        $admin->forceFill(['two_factor_last_step' => 123])->save();

        $admin->clearTwoFactor();
        $admin = $admin->fresh();

        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertNull($admin->two_factor_recovery_codes);
        $this->assertNull($admin->two_factor_last_step);
        $this->assertFalse($admin->hasTwoFactorEnabled());
    }

    #[TestDox('La clave y los códigos de recuperación nunca salen al convertir el usuario a texto o JSON')]
    public function test_secrets_are_hidden_from_serialisation(): void
    {
        [$admin, $secreto] = $this->adminConDosFactores();

        $json = json_encode($admin->toArray());

        $this->assertStringNotContainsString($secreto, (string) $json);
        $this->assertArrayNotHasKey('two_factor_secret', $admin->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $admin->toArray());
    }
}
