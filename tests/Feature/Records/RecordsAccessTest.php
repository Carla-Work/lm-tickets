<?php

/*
 * Quién entra en «Registros» (correos, auditoría, copias): SOLO el administrador. Cuándo tocarlo: si la usuaria
 * cambia quién ve los registros; cambiar también la matriz de docs/plan/autorizacion.md. Si falla, se arregla el
 * código o se discute la regla: NO se adapta el test.
 * Se comprueba TODOS los actores contra TODAS las pantallas: lo que no figura está denegado.
 */

namespace Tests\Feature\Records;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class RecordsAccessTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    private const SCREENS = ['records.mail', 'records.audit', 'records.backups'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    #[TestDox('El administrador abre las tres pantallas de Registros')]
    public function test_admin_opens_all_screens(): void
    {
        foreach (self::SCREENS as $ruta) {
            $this->actingAs($this->a['admin'])->get(route($ruta))->assertOk();
        }
    }

    #[TestDox('Ningún otro rol entra en Registros: agentes y clientes reciben 403')]
    public function test_nobody_else_enters(): void
    {
        foreach ($this->a as $clave => $actor) {
            if ($clave === 'admin') {
                continue;
            }
            foreach (self::SCREENS as $ruta) {
                $this->actingAs($actor)->get(route($ruta))->assertForbidden();
            }
        }
    }

    #[TestDox('Quien no ha iniciado sesión es enviado a la pantalla de acceso')]
    public function test_guest_is_sent_to_login(): void
    {
        foreach (self::SCREENS as $ruta) {
            $this->get(route($ruta))->assertRedirect(route('login'));
        }
    }

    #[TestDox('Solo el administrador puede pedir una copia: los demás reciben 403 y no se encola nada')]
    public function test_only_admin_can_request_backup(): void
    {
        Queue::fake();

        foreach ($this->a as $clave => $actor) {
            if ($clave !== 'admin') {
                $this->actingAs($actor)->post(route('records.backups.store'))->assertForbidden();
            }
        }

        Queue::assertNothingPushed();
    }

    #[TestDox('El menú «Registros» solo lo ve el administrador')]
    public function test_menu_only_for_admin(): void
    {
        $this->actingAs($this->a['admin'])->get(route('inbox'))->assertSee('Registro de correos')->assertSee('Copias de seguridad');
        $this->actingAs($this->a['agSup'])->get(route('inbox'))->assertDontSee('Registro de correos')->assertDontSee('Copias de seguridad');
        $this->actingAs($this->a['cuA'])->get(route('tickets.index'))->assertDontSee('Registro de correos');
    }

    #[TestDox('Un administrador dado de baja no entra aunque conserve la sesión (Gate::before)')]
    public function test_deactivated_admin_denied(): void
    {
        $this->a['admin']->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($this->a['admin'])->get(route('records.mail'))->assertStatus(302);
    }
}
