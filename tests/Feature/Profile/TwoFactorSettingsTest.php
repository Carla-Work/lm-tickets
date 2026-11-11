<?php

/*
 * Segundo factor del administrador desde «Mis datos»: activar, ver los códigos una vez, regenerarlos y desactivar (R21).
 * Cuándo tocarla: al cambiar cómo se gestiona el 2FA con la sesión iniciada. Trampas que vigila:
 *  - SOLO el administrador (agentes y clientes, 403 en todas las rutas);
 *  - la clave no se guarda hasta acertar un código (abandonar no deja nada a medias);
 *  - el código usado al activar no vale luego para entrar;
 *  - regenerar y desactivar piden la contraseña y limitan intentos;
 *  - los códigos en claro se ven UNA vez y nunca llegan a la auditoría;
 *  - tras activar, el siguiente acceso exige el código (no autentica solo con la contraseña).
 * Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Profile;

use App\Models\AuditLog;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class TwoFactorSettingsTest extends TestCase
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

    private function totp(): Totp
    {
        return new Totp((int) config('access.two_factor.digits'), (int) config('access.two_factor.period'));
    }

    /** Abre la pantalla de activar y devuelve la clave pendiente que quedó en la sesión. */
    private function claveEnSesion(): string
    {
        $this->como('admin')->get(route('profile.two-factor.setup'))->assertOk();

        return Crypt::decryptString((string) session('two_factor_settings.secret'));
    }

    /** Activa el 2FA del administrador y devuelve los códigos en claro que se mostraron. */
    private function activar(): array
    {
        $secreto = $this->claveEnSesion();
        $codigo = $this->totp()->code($secreto, now()->timestamp);
        $this->post(route('profile.two-factor.confirm'), ['codigo' => $codigo])->assertRedirect(route('profile.two-factor.codes'));

        return json_decode(Crypt::decryptString((string) session('two_factor_settings.codes')), true);
    }

    #[TestDox('Solo el administrador usa estas pantallas: agentes y clientes reciben 403 en todas las rutas y el visitante va a entrar')]
    public function test_only_admin(): void
    {
        $rutas = [
            ['get', route('profile.two-factor.setup')], ['post', route('profile.two-factor.confirm')], ['get', route('profile.two-factor.codes')],
            ['post', route('profile.two-factor.finish')], ['post', route('profile.two-factor.regenerate')], ['post', route('profile.two-factor.disable')],
        ];

        foreach (['agSup', 'agCon', 'cuA', 'caA'] as $clave) {
            foreach ($rutas as [$metodo, $url]) {
                $this->como($clave)->{$metodo}($url, ['codigo' => '000000', 'password' => 'password'])->assertForbidden();
            }
            $this->como($clave)->get(route('profile.show'))->assertDontSee('Verificación en dos pasos');
        }
        $this->assertFalse($this->a['agSup']->fresh()->hasTwoFactorEnabled());

        foreach ($rutas as [$metodo, $url]) {
            $this->app['auth']->forgetGuards();
            $this->{$metodo}($url)->assertRedirect(route('login'));
        }
    }

    #[TestDox('Desactivada: «Mis datos» ofrece activarla y muestra la clave para escribir a mano con el enlace otpauth; no guarda nada hasta acertar el código')]
    public function test_setup_shows_key_and_saves_nothing(): void
    {
        $this->como('admin')->get(route('profile.show'))->assertSee('Desactivada')->assertSee(route('profile.two-factor.setup'));

        $respuesta = $this->como('admin')->get(route('profile.two-factor.setup'))->assertOk()->assertSee('otpauth://totp/', false);
        $secreto = Crypt::decryptString((string) session('two_factor_settings.secret'));
        $respuesta->assertSee(trim(chunk_split($secreto, 4, ' ')));

        $this->assertNull($this->a['admin']->fresh()->two_factor_secret, 'la clave no se guarda en la base de datos hasta confirmar');
        // Recargar no cambia la clave (si no, la que ya añadiste a tu app dejaría de valer).
        $this->como('admin')->get(route('profile.two-factor.setup'));
        $this->assertSame($secreto, Crypt::decryptString((string) session('two_factor_settings.secret')));
        // Salir a «Mis datos» descarta la clave pendiente.
        $this->como('admin')->get(route('profile.show'));
        $this->assertNull(session('two_factor_settings.secret'));
    }

    #[TestDox('Un código erróneo no activa nada ni guarda la clave; uno acertado la activa, guarda el intervalo usado y 8 códigos con hash')]
    public function test_confirm_validates_the_code(): void
    {
        $secreto = $this->claveEnSesion();

        $this->post(route('profile.two-factor.confirm'), ['codigo' => '000000'])->assertSessionHasErrors('codigo');
        $this->post(route('profile.two-factor.confirm'), [])->assertSessionHasErrors('codigo');
        $yo = $this->a['admin']->fresh();
        $this->assertFalse($yo->hasTwoFactorEnabled());
        $this->assertNull($yo->two_factor_secret);

        $codigo = $this->totp()->code($secreto, now()->timestamp);
        $this->post(route('profile.two-factor.confirm'), ['codigo' => $codigo])->assertRedirect(route('profile.two-factor.codes'));

        $yo = $this->a['admin']->fresh();
        $this->assertTrue($yo->hasTwoFactorEnabled());
        $this->assertSame($secreto, $yo->two_factor_secret);
        $this->assertSame($this->totp()->stepAt(now()->timestamp), $yo->two_factor_last_step, 'el código usado queda marcado: no vale para entrar');
        $this->assertCount((int) config('access.two_factor.recovery_codes'), $yo->two_factor_recovery_codes ?? []);
        $this->assertTrue(AuditLog::where('event', 'two_factor_enabled')->where('actor_id', $yo->id)->exists());
    }

    #[TestDox('Los códigos se ven una sola vez, hay que confirmar que se guardaron y no llegan a la auditoría ni a la base de datos en claro')]
    public function test_codes_are_shown_once(): void
    {
        $codigos = $this->activar();
        $this->assertCount(8, $codigos);

        $pantalla = $this->get(route('profile.two-factor.codes'))->assertOk();
        foreach ($codigos as $codigo) {
            $pantalla->assertSee($codigo);
        }

        $this->post(route('profile.two-factor.finish'))->assertSessionHasErrors('guardados');
        $this->get(route('profile.two-factor.codes'))->assertOk();
        $this->post(route('profile.two-factor.finish'), ['guardados' => '1'])->assertRedirect(route('profile.show'));
        $this->get(route('profile.two-factor.codes'))->assertRedirect(route('profile.show'));

        $guardado = json_encode($this->a['admin']->fresh()->two_factor_recovery_codes).json_encode(AuditLog::all()->toArray());
        foreach ($codigos as $codigo) {
            $this->assertStringNotContainsString($codigo, $guardado, 'un código en claro llegó a la base de datos o a la auditoría');
        }
        $this->assertStringNotContainsString((string) $this->a['admin']->fresh()->two_factor_secret, json_encode(AuditLog::all()->toArray()) ?: '');
    }

    #[TestDox('Tras activarlo, el siguiente acceso del administrador NO inicia sesión solo con la contraseña: exige el código')]
    public function test_next_login_requires_the_code(): void
    {
        $this->activar();

        $this->app['auth']->forgetGuards();
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $this->a['admin']->email, 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    #[TestDox('Regenerar códigos exige la contraseña, los anteriores dejan de valer y los nuevos se ven una vez; limita intentos')]
    public function test_regenerate(): void
    {
        $viejos = $this->activar();
        $this->post(route('profile.two-factor.finish'), ['guardados' => '1']);
        $hashesViejos = $this->a['admin']->fresh()->two_factor_recovery_codes;

        $this->post(route('profile.two-factor.regenerate'), ['password' => 'mala'])->assertSessionHasErrors('password');
        $this->post(route('profile.two-factor.regenerate'), [])->assertSessionHasErrors('password');
        $this->assertSame($hashesViejos, $this->a['admin']->fresh()->two_factor_recovery_codes);

        $this->post(route('profile.two-factor.regenerate'), ['password' => 'password'])->assertRedirect(route('profile.two-factor.codes'));
        $nuevos = json_decode(Crypt::decryptString((string) session('two_factor_settings.codes')), true);
        $this->get(route('profile.two-factor.codes'))->assertOk()->assertSee('nuevos')->assertSee($nuevos[0]);

        $actuales = $this->a['admin']->fresh()->two_factor_recovery_codes ?? [];
        $this->assertCount(8, $actuales);
        $this->assertNull(RecoveryCodes::consume($actuales, $viejos[0]), 'un código antiguo ya no vale');
        $this->assertNotNull(RecoveryCodes::consume($actuales, $nuevos[0]));
        $this->assertTrue(AuditLog::where('event', 'recovery_codes_regenerated')->exists());
    }

    #[TestDox('Desactivar exige la contraseña, borra clave y códigos, y el acceso vuelve a ser solo con contraseña; queda auditado')]
    public function test_disable(): void
    {
        $this->activar();
        $this->post(route('profile.two-factor.finish'), ['guardados' => '1']);

        $this->post(route('profile.two-factor.disable'), ['password' => 'mala'])->assertSessionHasErrors('password');
        $this->assertTrue($this->a['admin']->fresh()->hasTwoFactorEnabled());

        $this->post(route('profile.two-factor.disable'), ['password' => 'password'])->assertRedirect(route('profile.show'));
        $yo = $this->a['admin']->fresh();
        $this->assertFalse($yo->hasTwoFactorEnabled());
        $this->assertNull($yo->two_factor_secret);
        $this->assertNull($yo->two_factor_recovery_codes);
        $this->assertTrue(AuditLog::where('event', 'two_factor_disabled')->exists());

        $this->app['auth']->forgetGuards();
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $yo->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticated(); // entra solo con la contraseña, sin pasar por el código
    }

    #[TestDox('Los intentos de código al activar y de contraseña al regenerar o desactivar comparten un límite de 5 por minuto por persona: el sexto da 429')]
    public function test_attempts_are_throttled(): void
    {
        $this->activar(); // gasta 1 de los 5 intentos del minuto
        $this->post(route('profile.two-factor.finish'), ['guardados' => '1']);

        for ($i = 0; $i < 4; $i++) {
            $this->como('admin')->post(route('profile.two-factor.disable'), ['password' => 'mala'])->assertSessionHasErrors('password');
        }
        $this->como('admin')->post(route('profile.two-factor.disable'), ['password' => 'password'])->assertStatus(429);
        $this->assertTrue($this->a['admin']->fresh()->hasTwoFactorEnabled(), 'ni con la contraseña buena se desactiva estando limitado');
    }

    #[TestDox('La pantalla de «Mis datos» muestra el estado Activada con los códigos que quedan y las opciones de regenerar y desactivar')]
    public function test_profile_shows_enabled_state(): void
    {
        $this->activar();
        $this->post(route('profile.two-factor.finish'), ['guardados' => '1']);

        $this->como('admin')->get(route('profile.show'))->assertOk()->assertSee('Activada')->assertSee('Generar códigos nuevos')
            ->assertSee('Desactivar la verificación en dos pasos')->assertSee('<strong>8</strong>', false);
    }
}
