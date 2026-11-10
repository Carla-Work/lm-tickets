<?php

/*
 * «Mis datos»: datos personales y cambio de contraseña de cualquier rol.
 * Cuándo tocarla: al cambiar qué puede editar una persona de sí misma. Trampas que vigila:
 *  - rol, empresa, equipo y correo NO se cambian por este formulario (escalada de privilegios, R8);
 *  - la contraseña nueva exige la actual y respeta el mínimo de config/access.php;
 *  - la contraseña nunca llega a la auditoría.
 * Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Profile;

use App\Enums\UserRole;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    private const NUEVA = 'una-clave-larga-y-nueva-77';

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

    #[TestDox('Todos los roles abren «Mis datos» y ven lo suyo; el visitante va a entrar')]
    public function test_everyone_opens_it(): void
    {
        foreach ($this->a as $clave => $actor) {
            $this->como($clave)->get(route('profile.show'))->assertOk()->assertSee($actor->email)->assertSee('Cambiar contraseña');
        }
        $this->como('cuA')->get(route('profile.show'))->assertSee('Teléfono')->assertSee('Privacidad')->assertSee('Empresa A');
        $this->como('agSup')->get(route('profile.show'))->assertDontSee('Teléfono')->assertSee('Soporte');
        $this->como('admin')->get(route('profile.show'))->assertSee('no pertenece a ningún equipo');
        $this->app['auth']->forgetGuards();
        $this->get(route('profile.show'))->assertRedirect(route('login'));
    }

    #[TestDox('Se cambian nombre y teléfono, pero NUNCA rol, empresa, equipo ni correo aunque se envíen')]
    public function test_update_data_ignores_privileged_fields(): void
    {
        $this->como('cuA')->post(route('profile.update'), [
            'name' => 'Nombre Nuevo', 'phone' => '928 999 999',
            'role' => 'admin', 'company_id' => $this->empresaB->id, 'team' => 'support', 'email' => 'otro@x.example',
        ])->assertRedirect(route('profile.show'));

        $yo = $this->a['cuA']->fresh();
        $this->assertSame('Nombre Nuevo', $yo->name);
        $this->assertSame('928 999 999', $yo->phone);
        $this->assertTrue($yo->hasRole(UserRole::CompanyUser));
        $this->assertSame($this->empresaA->id, $yo->company_id);
        $this->assertNull($yo->team);
        $this->assertNotSame('otro@x.example', $yo->email);

        $this->como('agSup')->post(route('profile.update'), ['name' => 'Agente Renombrado', 'phone' => '600'])->assertRedirect();
        $this->assertSame('Agente Renombrado', $this->a['agSup']->fresh()->name);
        $this->assertNull($this->a['agSup']->fresh()->phone, 'a un empleado no se le guarda teléfono desde aquí');

        $this->como('cuA')->post(route('profile.update'), ['name' => ''])->assertSessionHasErrors('name');
    }

    #[TestDox('Cambiar la contraseña exige la actual, la nueva cumple el mínimo, confirmada y distinta; la antigua deja de valer y queda auditado sin la clave')]
    public function test_change_password(): void
    {
        $url = route('profile.password');
        $ok = ['current_password' => 'password', 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA];

        $this->como('cuA')->post($url, ['current_password' => 'mala'] + $ok)->assertSessionHasErrors('current_password');
        $this->como('cuA')->post($url, ['password' => 'corta', 'password_confirmation' => 'corta'] + $ok)->assertSessionHasErrors('password');
        $this->como('cuA')->post($url, ['password_confirmation' => 'distinta-distinta-1'] + $ok)->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $this->a['cuA']->fresh()->password), 'ningún intento fallido cambia la clave');

        // La nueva no puede ser igual que la actual (aquí la actual ya cumple el mínimo, para que sea la única causa).
        $this->a['cuA']->forceFill(['password' => self::NUEVA])->save();
        $this->como('cuA')->post($url, ['current_password' => self::NUEVA, 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA])->assertSessionHasErrors('password');
        $this->a['cuA']->forceFill(['password' => 'password'])->save();

        $this->como('cuA')->post($url, $ok)->assertRedirect(route('profile.show'));
        $this->assertTrue(Hash::check(self::NUEVA, $this->a['cuA']->fresh()->password));
        $this->assertFalse(Hash::check('password', $this->a['cuA']->fresh()->password));

        $registro = AuditLog::where('event', 'password_changed')->firstOrFail();
        $this->assertStringNotContainsString(self::NUEVA, json_encode($registro->toArray()) ?: '');
        $this->assertStringNotContainsString('password', json_encode($registro->properties) ?: '');
    }

    #[TestDox('Los intentos de cambiar la contraseña están limitados: a partir del sexto seguido, 429')]
    public function test_password_attempts_are_throttled(): void
    {
        $mala = ['current_password' => 'mala', 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA];
        for ($i = 0; $i < 5; $i++) {
            $this->como('cuA')->post(route('profile.password'), $mala)->assertSessionHasErrors('current_password');
        }
        $this->como('cuA')->post(route('profile.password'), $mala)->assertStatus(429);
    }

    #[TestDox('El menú de la cuenta enlaza «Mis datos» a todos los roles')]
    public function test_menu_link(): void
    {
        foreach (['cuA', 'caA', 'agSup', 'admin'] as $clave) {
            $pantalla = in_array($clave, ['agSup', 'admin'], true) ? route('inbox') : route('tickets.index');
            $this->como($clave)->get($pantalla)->assertSee(route('profile.show'));
        }
    }
}
