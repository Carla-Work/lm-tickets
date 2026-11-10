<?php

/*
 * Administración › Empresas (alta, datos, productos, baja y reenvío de invitación): R1, R7, R8, R18, R19.
 * Cuándo tocarla: al cambiar quién gestiona empresas o qué implica una baja; cambiar también la matriz de
 * docs/plan/autorizacion.md. Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 *
 * Trampa: las notificaciones se simulan con Notification::fake(); la auditoría se lee de verdad.
 */

namespace Tests\Feature\Admin;

use App\Actions\Users\InviteUser;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class CompaniesTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    /** @return array<string, mixed> */
    private function datos(array $extra = []): array
    {
        return $extra + [
            'name' => 'Talleres Marrero S.L.', 'tax_id' => 'B35567890', 'phone' => '928 111 222', 'contact_email' => 'info@marrero.example',
            'user_name' => 'Pedro Marrero', 'user_email' => 'pedro@marrero.example',
        ];
    }

    #[TestDox('Solo el administrador abre y usa las pantallas de empresas: el resto recibe 403 y el visitante va a entrar')]
    public function test_only_admin_can_use_company_screens(): void
    {
        Notification::fake();
        $empresa = $this->empresaA;
        $pantallas = [
            ['get', route('admin.companies.index')], ['get', route('admin.companies.create')], ['get', route('admin.companies.show', $empresa)],
            ['post', route('admin.companies.store')], ['post', route('admin.companies.update', $empresa)],
            ['post', route('admin.companies.products', $empresa)], ['post', route('admin.companies.deactivate', $empresa)],
            ['post', route('admin.companies.reactivate', $empresa)],
        ];

        foreach ($this->a as $clave => $actor) {
            foreach ($pantallas as [$metodo, $url]) {
                // Datos inválidos a propósito: quien no puede debe recibir 403 y no los mensajes de validación.
                $respuesta = $this->actingAs($actor)->{$metodo}($url, $metodo === 'post' ? ['name' => '', 'tax_id' => '', 'productos' => ['x']] : []);
                if ($clave === 'admin') {
                    $this->assertNotSame(403, $respuesta->status(), "el administrador no debe recibir 403 en {$url}");
                } else {
                    $respuesta->assertForbidden();
                }
            }
        }
        $this->assertSame(2, Company::count(), 'nadie crea nada con datos inválidos');

        foreach ($pantallas as [$metodo, $url]) {
            $this->app['auth']->forgetGuards();
            $this->{$metodo}($url)->assertRedirect(route('login'));
        }
    }

    #[TestDox('El alta crea la empresa, sus productos ACTIVOS, a su primer administrador de empresa (invitado) y lo audita')]
    public function test_create_company_with_first_admin(): void
    {
        Notification::fake();
        $activo = Product::factory()->create();
        $inactivo = Product::factory()->inactive()->create();

        $this->actingAs($this->a['admin'])->post(route('admin.companies.store'), $this->datos(['productos' => [$activo->id, $inactivo->id]]))
            ->assertRedirect();

        $empresa = Company::where('tax_id', 'B35567890')->firstOrFail();
        $this->assertSame([$activo->id], $empresa->products()->pluck('products.id')->all(), 'un producto desactivado no se asigna (R19)');
        $primero = User::where('email', 'pedro@marrero.example')->firstOrFail();
        $this->assertSame(UserRole::CompanyAdmin, $primero->role);
        $this->assertSame($empresa->id, $primero->company_id);
        $this->assertNull($primero->activated_at, 'la cuenta nace sin activar (R18)');
        Notification::assertSentTo($primero, InvitationNotification::class);
        $this->assertTrue(AuditLog::where('event', 'company_created')->where('actor_id', $this->a['admin']->id)->exists());
    }

    #[TestDox('El CIF se normaliza y no se repite (ni con otras mayúsculas o espacios); el correo del primer usuario tampoco')]
    public function test_duplicates_are_rejected(): void
    {
        Notification::fake();
        $this->empresaA->forceFill(['tax_id' => 'B35567890'])->save();

        $this->actingAs($this->a['admin'])->post(route('admin.companies.store'), $this->datos(['tax_id' => 'b35 567890']))->assertSessionHasErrors('tax_id');
        $this->actingAs($this->a['admin'])->post(route('admin.companies.store'), $this->datos(['tax_id' => 'B99999999', 'user_email' => $this->a['cuA']->email]))->assertSessionHasErrors('user_email');
        $this->assertSame(2, Company::count());
    }

    #[TestDox('Si la invitación falla no queda ni la empresa ni el usuario (una sola transacción)')]
    public function test_failed_invitation_leaves_nothing(): void
    {
        $this->mock(InviteUser::class, fn ($mock) => $mock->shouldReceive('handle')->andThrow(new \RuntimeException('correo caído')));

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->a['admin'])->post(route('admin.companies.store'), $this->datos());
            $this->fail('debía propagar el fallo');
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame(2, Company::count());
        $this->assertNull(User::where('email', 'pedro@marrero.example')->first());
    }

    #[TestDox('Productos: se añaden solo activos, se conserva y se puede quitar uno desactivado, y los tickets antiguos conservan el suyo (R19)')]
    public function test_product_assignment(): void
    {
        $viejo = Product::factory()->create();
        $nuevo = Product::factory()->create();
        $inactivo = Product::factory()->inactive()->create();
        $this->empresaA->products()->attach($viejo);
        $ticket = $this->ticket();
        $ticket->forceFill(['product_id' => $viejo->id])->save();
        $this->empresaA->products()->attach($inactivo);

        // Marca el nuevo, conserva el desactivado que ya tenía y quita el viejo; intenta colar otro desactivado.
        $otroInactivo = Product::factory()->inactive()->create();
        $this->actingAs($this->a['admin'])->post(route('admin.companies.products', $this->empresaA), ['productos' => [$nuevo->id, $inactivo->id, $otroInactivo->id]])->assertRedirect();

        $this->assertEqualsCanonicalizing([$nuevo->id, $inactivo->id], $this->empresaA->products()->pluck('products.id')->all());
        $this->assertSame($viejo->id, $ticket->fresh()->product_id, 'quitar el producto a la empresa no toca el ticket que ya lo llevaba');
        $this->assertTrue(AuditLog::where('event', 'company_products_changed')->exists());

        $this->actingAs($this->a['admin'])->post(route('admin.companies.products', $this->empresaA), [])->assertRedirect();
        $this->assertSame(0, $this->empresaA->products()->count(), 'sin casillas marcadas se quitan todos');
    }

    #[TestDox('Se editan los datos; el CIF propio no choca consigo mismo pero sí con el de otra empresa')]
    public function test_update_details(): void
    {
        $url = route('admin.companies.update', $this->empresaA);
        $this->actingAs($this->a['admin'])->post($url, ['name' => 'Nuevo nombre', 'tax_id' => $this->empresaA->tax_id])->assertRedirect();
        $this->assertSame('Nuevo nombre', $this->empresaA->fresh()->name);
        $this->assertTrue(AuditLog::where('event', 'company_updated')->exists());

        $this->actingAs($this->a['admin'])->post($url, ['name' => 'X', 'tax_id' => $this->empresaB->tax_id])->assertSessionHasErrors('tax_id');
        $this->assertSame('Nuevo nombre', $this->empresaA->fresh()->name);
    }

    #[TestDox('La baja exige escribir el nombre; corta el acceso de sus usuarios en la siguiente petición y reactivar lo devuelve')]
    public function test_deactivate_and_reactivate(): void
    {
        $url = route('admin.companies.deactivate', $this->empresaA);

        $this->actingAs($this->a['admin'])->post($url, [])->assertSessionHasErrors('confirmacion');
        $this->actingAs($this->a['admin'])->post($url, ['confirmacion' => 'otra cosa'])->assertSessionHasErrors('confirmacion');
        $this->assertTrue($this->empresaA->fresh()->isActive());

        $this->actingAs($this->a['admin'])->post($url, ['confirmacion' => '  empresa A '])->assertRedirect();
        $this->assertFalse($this->empresaA->fresh()->isActive());
        $this->assertTrue(AuditLog::where('event', 'company_deactivated')->exists());

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->a['cuA'])->get(route('tickets.index'))->assertRedirect(route('login'));
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->a['cuB'])->get(route('tickets.index'))->assertOk();

        $this->actingAs($this->a['admin'])->post(route('admin.companies.reactivate', $this->empresaA))->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->a['cuA']->fresh())->get(route('tickets.index'))->assertOk();
        $this->assertTrue(AuditLog::where('event', 'company_reactivated')->exists());
    }

    #[TestDox('Reenviar invitación: solo a cuentas sin aceptar; una cuenta activa o de baja no recibe enlace; clientes y agentes no pueden')]
    public function test_resend_invitation(): void
    {
        Notification::fake();
        $pendiente = User::factory()->invited()->companyAdmin()->forCompany($this->empresaA)->create();
        $deBaja = User::factory()->invited()->deactivated()->companyAdmin()->forCompany($this->empresaA)->create();
        $usuarioPendiente = User::factory()->invited()->forCompany($this->empresaA)->create();

        $this->actingAs($this->a['admin'])->post(route('users.invitation.resend', $pendiente))->assertRedirect();
        Notification::assertSentTo($pendiente, InvitationNotification::class);
        $this->assertTrue(AuditLog::where('event', 'invited')->where('subject_id', $pendiente->id)->exists());

        foreach ([$this->a['cuA'], $deBaja] as $objetivo) {
            $this->actingAs($this->a['admin'])->post(route('users.invitation.resend', $objetivo))->assertForbidden();
        }
        Notification::assertNotSentTo($this->a['cuA'], InvitationNotification::class);
        Notification::assertNotSentTo($deBaja, InvitationNotification::class);

        foreach (['agSup', 'cuA', 'cuB', 'caB'] as $clave) {
            $this->actingAs($this->a[$clave])->post(route('users.invitation.resend', $pendiente))->assertForbidden();
        }

        // Un usuario normal pendiente lo invita su administrador de empresa (R8), no el administrador; y nunca el de otra empresa (R1).
        $this->actingAs($this->a['admin'])->post(route('users.invitation.resend', $usuarioPendiente))->assertForbidden();
        $this->actingAs($this->a['caB'])->post(route('users.invitation.resend', $usuarioPendiente))->assertForbidden();
        $this->actingAs($this->a['caA'])->post(route('users.invitation.resend', $usuarioPendiente))->assertRedirect();
        Notification::assertSentTo($usuarioPendiente, InvitationNotification::class);
    }

    #[TestDox('La lista filtra por texto, estado y producto, y distingue activa, invitación pendiente y de baja')]
    public function test_list_filters(): void
    {
        $producto = Product::factory()->create(['name' => 'oneTEST']);
        $pendiente = Company::factory()->create(['name' => 'Pendiente S.L.']);
        User::factory()->invited()->companyAdmin()->forCompany($pendiente)->create();
        $baja = Company::factory()->deactivated()->create(['name' => 'Baja S.L.']);
        $this->empresaA->products()->attach($producto);
        $admin = $this->actingAs($this->a['admin']);

        $admin->get(route('admin.companies.index'))->assertOk()->assertSee('Empresa A')->assertSee('Pendiente S.L.')->assertSee('Baja S.L.')
            ->assertSee('Invitación pendiente')->assertSee('De baja');
        $admin->get(route('admin.companies.index', ['estado' => 'baja']))->assertSee('Baja S.L.')->assertDontSee('Pendiente S.L.')->assertDontSee('Empresa A');
        $admin->get(route('admin.companies.index', ['estado' => 'pendiente']))->assertSee('Pendiente S.L.')->assertDontSee('Baja S.L.')->assertDontSee('Empresa A');
        $admin->get(route('admin.companies.index', ['estado' => 'activa']))->assertSee('Empresa A')->assertDontSee('Pendiente S.L.')->assertDontSee('Baja S.L.');
        $admin->get(route('admin.companies.index', ['producto' => $producto->id]))->assertSee('Empresa A')->assertDontSee('Empresa B');
        $admin->get(route('admin.companies.index', ['q' => $baja->tax_id]))->assertSee('Baja S.L.')->assertDontSee('Empresa A');
    }

    #[TestDox('El detalle muestra los usuarios de ESA empresa y el menú «Empresas» sale solo al administrador')]
    public function test_detail_and_menu(): void
    {
        $this->actingAs($this->a['admin'])->get(route('admin.companies.show', $this->empresaA))->assertOk()
            ->assertSee('Usuario A')->assertSee('Admin empresa A')->assertDontSee('Usuario B')
            ->assertSee(route('admin.companies.index'));

        $this->actingAs($this->a['agSup'])->get(route('inbox'))->assertOk()->assertDontSee(route('admin.companies.index'));
    }
}
