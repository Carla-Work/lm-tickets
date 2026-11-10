<?php

/*
 * `php artisan admin:recover`: la salida de último recurso si el único administrador pierde su acceso (R9, R21).
 * Cuándo tocarla: al cambiar el comando. Trampas que vigila:
 *  - nunca fija ni imprime una contraseña, solo un enlace de un solo uso que sirve de verdad;
 *  - solo actúa sobre administradores (no ascende ni recupera a agentes o clientes);
 *  - el 2FA solo se borra si se pide, y la baja solo se quita si se pide.
 * Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class RecoverAdminAccessTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    private function conDosFactores(): void
    {
        $this->a['admin']->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['x'], 'two_factor_last_step' => 3])->save();
    }

    /** Ejecuta el comando y devuelve lo que imprimió (con un búfer propio: `Artisan::output()` sale vacío en los tests). */
    private function ejecutar(array $opciones): string
    {
        $salida = new BufferedOutput;
        $codigo = Artisan::call('admin:recover', $opciones, $salida);

        $codigo === 0 || $this->fail("el comando terminó con código $codigo");

        return $salida->fetch();
    }

    /** Saca el enlace impreso por el comando. */
    private function enlace(string $salida): string
    {
        preg_match('#https?://\S+/contrasena/\S+#', $salida, $m);
        $this->assertNotEmpty($m, 'el comando no imprimió ningún enlace: '.$salida);

        return $m[0];
    }

    #[TestDox('Imprime un enlace de un solo uso que de verdad permite elegir la contraseña, sin tocar la actual ni imprimir ninguna clave')]
    public function test_prints_a_working_one_time_link(): void
    {
        $antes = $this->a['admin']->password;
        $this->artisan('admin:recover', ['--force' => true])->expectsOutputToContain('Enlace de un solo uso')->assertSuccessful();
        $this->assertSame($antes, $this->a['admin']->fresh()->password, 'el comando no cambia la contraseña');

        // Se ejecuta de nuevo capturando la salida para usar el enlace de verdad.
        $salida = $this->ejecutar(['--force' => true]);
        $url = $this->enlace($salida);
        $token = basename(parse_url($url, PHP_URL_PATH) ?: '');
        $this->assertStringNotContainsString('password', strtolower(str_replace('contrasena', '', $salida)));

        $this->post(route('password.update'), ['token' => $token, 'email' => $this->a['admin']->email, 'password' => 'otra-clave-muy-larga-1', 'password_confirmation' => 'otra-clave-muy-larga-1'])
            ->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('otra-clave-muy-larga-1', $this->a['admin']->fresh()->password));

        // De un solo uso: el mismo enlace ya no vale.
        $this->post(route('password.update'), ['token' => $token, 'email' => $this->a['admin']->email, 'password' => 'y-otra-distinta-larga-2', 'password_confirmation' => 'y-otra-distinta-larga-2'])
            ->assertSessionHasErrors('password');
    }

    #[TestDox('El segundo factor solo se borra con --disable-2fa')]
    public function test_two_factor_only_cleared_when_asked(): void
    {
        $this->conDosFactores();

        $this->artisan('admin:recover', ['--force' => true])->assertSuccessful();
        $this->assertTrue($this->a['admin']->fresh()->hasTwoFactorEnabled());

        $this->artisan('admin:recover', ['--force' => true, '--disable-2fa' => true])->assertSuccessful();
        $yo = $this->a['admin']->fresh();
        $this->assertFalse($yo->hasTwoFactorEnabled());
        $this->assertNull($yo->two_factor_secret);
        $this->assertNull($yo->two_factor_recovery_codes);
    }

    #[TestDox('Una cuenta de baja exige --reactivate; sin él no cambia nada y con él se reactiva')]
    public function test_deactivated_requires_flag(): void
    {
        $this->a['admin']->forceFill(['deactivated_at' => now()])->save();

        // Con el correo dado, para que sea la baja (y no la búsqueda del administrador) lo que lo rechaza.
        $this->artisan('admin:recover', ['--force' => true, '--email' => $this->a['admin']->email])->assertFailed();
        $this->assertNotNull($this->a['admin']->fresh()->deactivated_at);
        $this->assertFalse(AuditLog::where('event', 'admin_recovered')->exists());

        $this->artisan('admin:recover', ['--force' => true, '--reactivate' => true, '--email' => $this->a['admin']->email])->assertSuccessful();
        $this->assertNull($this->a['admin']->fresh()->deactivated_at);
    }

    #[TestDox('Solo actúa sobre administradores: agentes, clientes y correos inexistentes se rechazan sin crear ningún enlace')]
    public function test_only_admins(): void
    {
        foreach (['agSup', 'cuA', 'caA'] as $clave) {
            $this->artisan('admin:recover', ['--force' => true, '--email' => $this->a[$clave]->email])->assertFailed();
        }
        $this->artisan('admin:recover', ['--force' => true, '--email' => 'nadie@nada.example'])->assertFailed();

        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertFalse(AuditLog::where('event', 'admin_recovered')->exists());
    }

    #[TestDox('Sin confirmación no hace nada, y deja constancia en la auditoría (sin actor y sin el enlace) cuando se ejecuta')]
    public function test_confirmation_and_audit(): void
    {
        $this->artisan('admin:recover')->expectsConfirmation('¿Generar el enlace de recuperación?', 'no')->assertFailed();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        $this->conDosFactores();
        $url = $this->enlace($this->ejecutar(['--force' => true, '--disable-2fa' => true]));

        $registro = AuditLog::where('event', 'admin_recovered')->firstOrFail();
        $this->assertNull($registro->actor_id);
        $this->assertSame($this->a['admin']->id, $registro->subject_id);
        $this->assertStringContainsString('segundo factor borrado', $registro->description);
        $this->assertStringNotContainsString(basename(parse_url($url, PHP_URL_PATH) ?: ''), json_encode($registro->toArray()) ?: '');
    }

    #[TestDox('Si no hay un único administrador en activo pide el correo en vez de adivinar')]
    public function test_ambiguous_without_email(): void
    {
        User::query()->where('id', $this->a['admin']->id)->update(['deactivated_at' => now()]);
        $this->artisan('admin:recover', ['--force' => true])->assertFailed();
    }
}
