<?php

/*
 * Quién administra empresas, productos, categorías y cuentas. Reglas R1, R7, R8, R9, R18, R19.
 * Es la sección «Administración» de docs/plan/autorizacion.md convertida en test.
 * Cuándo tocarlo: solo si la usuaria cambia quién administra qué. Si falla un test, se arregla la
 * policy o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class AdministrationPolicyTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    /**
     * Comprueba que, de TODOS los actores, solo los indicados tienen la habilidad.
     *
     * @param  list<string>  $allowed
     */
    private function assertOnly(array $allowed, string $ability, mixed ...$arguments): void
    {
        foreach ($this->a as $key => $actor) {
            $expected = in_array($key, $allowed, true);
            $this->assertSame($expected, $actor->can($ability, $arguments), ($expected ? 'DEBERÍA poder' : 'NO debería poder')." $key «{$ability}»");
        }
    }

    // ---------- empresas (R1, R7, R8) ----------

    #[TestDox('Solo el administrador lista empresas, las crea, las edita, las da de baja y les asigna productos (R7, R8)')]
    public function test_only_admin_manages_companies(): void
    {
        $this->assertOnly(['admin'], 'viewAny', Company::class);
        $this->assertOnly(['admin'], 'create', Company::class);
        foreach (['update', 'deactivate', 'assignProducts'] as $ability) {
            $this->assertOnly(['admin'], $ability, $this->empresaA);
        }
    }

    #[TestDox('Un cliente ve SOLO su empresa («Mi empresa»); ni la de otra empresa ni los agentes (R1)')]
    public function test_company_view(): void
    {
        $this->assertOnly(['admin', 'cuA', 'cuA2', 'caA'], 'view', $this->empresaA);
        $this->assertOnly(['admin', 'cuB', 'caB'], 'view', $this->empresaB);
    }

    #[TestDox('El administrador de empresa NO puede asignarse productos: solo los ve (R7)')]
    public function test_company_admin_cannot_assign_products(): void
    {
        $this->assertFalse($this->a['caA']->can('assignProducts', $this->empresaA));
    }

    // ---------- catálogo (R19) ----------

    #[TestDox('Solo el administrador edita productos y categorías')]
    public function test_only_admin_edits_catalog(): void
    {
        $producto = Product::factory()->create();
        $categoria = Category::factory()->create();

        $this->assertOnly(['admin'], 'viewAny', Product::class);
        $this->assertOnly(['admin'], 'create', Product::class);
        $this->assertOnly(['admin'], 'update', $producto);
        $this->assertOnly(['admin'], 'viewAny', Category::class);
        $this->assertOnly(['admin'], 'create', Category::class);
        $this->assertOnly(['admin'], 'update', $categoria);
    }

    #[TestDox('Un producto o una categoría SIN uso se pueden borrar; con uso, no: se desactivan (R19)')]
    public function test_delete_only_unused_catalog_items(): void
    {
        $libre = Product::factory()->create();
        $asignado = Product::factory()->create();
        $this->empresaA->products()->attach($asignado);
        $enTicket = Product::factory()->create();
        Ticket::factory()->create(['product_id' => $enTicket->id]);

        $this->assertOnly(['admin'], 'delete', $libre);
        $this->assertOnly([], 'delete', $asignado);
        $this->assertOnly([], 'delete', $enTicket);

        $categoriaUsada = Ticket::factory()->create()->category;
        $this->assertOnly([], 'delete', $categoriaUsada);
        $this->assertOnly(['admin'], 'delete', Category::factory()->create());
    }

    // ---------- invitar (R8, R18) ----------

    #[TestDox('El administrador invita agentes y al primer administrador de una empresa; NADIE invita administradores (R8, R9)')]
    public function test_admin_invitations(): void
    {
        $this->assertOnly(['admin'], 'invite', User::class, UserRole::Agent, null);
        $this->assertOnly([], 'invite', User::class, UserRole::Agent, $this->empresaA, 'un agente no pertenece a una empresa');
        $this->assertOnly(['admin', 'caA'], 'invite', User::class, UserRole::CompanyAdmin, $this->empresaA);
        $this->assertOnly([], 'invite', User::class, UserRole::CompanyAdmin, null, 'un administrador de empresa necesita empresa');
        $this->assertOnly([], 'invite', User::class, UserRole::Admin, null, 'el rol de administrador solo se obtiene por traspaso');
        $this->assertOnly([], 'invite', User::class, UserRole::Admin, $this->empresaA);
    }

    #[TestDox('El administrador de empresa invita usuarios de SU empresa; nunca de otra (R1, R8)')]
    public function test_company_admin_invitations_are_limited_to_own_company(): void
    {
        $this->assertTrue($this->a['caA']->can('invite', [User::class, UserRole::CompanyUser, $this->empresaA]));
        $this->assertTrue($this->a['caA']->can('invite', [User::class, UserRole::CompanyAdmin, $this->empresaA]));
        $this->assertFalse($this->a['caA']->can('invite', [User::class, UserRole::CompanyUser, $this->empresaB]));
        $this->assertFalse($this->a['caA']->can('invite', [User::class, UserRole::Agent, null]));
        $this->assertFalse($this->a['caA']->can('invite', [User::class, UserRole::Admin, null]));
    }

    #[TestDox('El administrador solo invita usuarios de empresa en el sentido de «primer administrador de empresa»: no usuarios sueltos')]
    public function test_admin_does_not_invite_plain_company_users(): void
    {
        $this->assertFalse($this->a['admin']->can('invite', [User::class, UserRole::CompanyUser, $this->empresaA]));
    }

    #[TestDox('Ni un usuario de empresa ni un agente invitan a nadie')]
    public function test_clients_and_agents_cannot_invite(): void
    {
        foreach (['cuA', 'cuA2', 'cuB', 'agSup', 'agApp'] as $key) {
            foreach ([UserRole::CompanyUser, UserRole::CompanyAdmin, UserRole::Agent, UserRole::Admin] as $role) {
                $this->assertFalse($this->a[$key]->can('invite', [User::class, $role, $this->empresaA]), "$key invitando a {$role->value}");
            }
        }
    }

    #[TestDox('Reenviar una invitación: solo quien podría haberla creado y solo si sigue sin aceptar')]
    public function test_resend_invitation(): void
    {
        $pending = User::factory()->forCompany($this->empresaA)->invited()->create();
        $active = User::factory()->forCompany($this->empresaA)->create();
        $deactivated = User::factory()->forCompany($this->empresaA)->invited()->deactivated()->create();

        $this->assertTrue($this->a['caA']->can('resendInvitation', $pending));
        $this->assertFalse($this->a['caB']->can('resendInvitation', $pending), 'otra empresa (R1)');
        $this->assertFalse($this->a['caA']->can('resendInvitation', $active), 'ya aceptó');
        $this->assertFalse($this->a['caA']->can('resendInvitation', $deactivated), 'dada de baja');
        $this->assertFalse($this->a['cuA']->can('resendInvitation', $pending));
        $this->assertFalse($this->a['agSup']->can('resendInvitation', $pending));
    }

    // ---------- dar de baja (R9) ----------

    #[TestDox('El administrador puede dar de baja a cualquiera EXCEPTO a sí mismo (R9)')]
    public function test_admin_can_deactivate_others_but_not_self(): void
    {
        foreach (['cuA', 'caA', 'cuB', 'agSup', 'agApp'] as $key) {
            $this->assertTrue($this->a['admin']->can('deactivate', $this->a[$key]), "debería poder dar de baja a $key");
        }
        $this->assertFalse($this->a['admin']->can('deactivate', $this->a['admin']), 'R9: el administrador no se da de baja a sí mismo');
    }

    #[TestDox('El administrador de empresa da de baja a los de SU empresa pero no a sí mismo, ni a otra empresa, ni a empleados (R1, R8)')]
    public function test_company_admin_deactivation_limits(): void
    {
        $this->assertTrue($this->a['caA']->can('deactivate', $this->a['cuA']));
        $this->assertTrue($this->a['caA']->can('deactivate', $this->a['cuA2']));
        $this->assertFalse($this->a['caA']->can('deactivate', $this->a['caA']), 'no a sí mismo');
        $this->assertFalse($this->a['caA']->can('deactivate', $this->a['cuB']), 'otra empresa');
        $this->assertFalse($this->a['caA']->can('deactivate', $this->a['caB']), 'otra empresa');
        $this->assertFalse($this->a['caA']->can('deactivate', $this->a['agSup']), 'un empleado');
        $this->assertFalse($this->a['caA']->can('deactivate', $this->a['admin']), 'el administrador');
    }

    #[TestDox('Un usuario de empresa o un agente no dan de baja a nadie')]
    public function test_others_cannot_deactivate(): void
    {
        foreach (['cuA', 'cuA2', 'cuB', 'agSup', 'agApp'] as $key) {
            foreach ($this->a as $objetivo) {
                $this->assertFalse($this->a[$key]->can('deactivate', $objetivo), "$key dando de baja a {$objetivo->name}");
            }
        }
    }

    // ---------- roles y equipos ----------

    #[TestDox('Cambiar usuario ↔ administrador de empresa: el administrador en cualquiera; el de empresa en la suya y no en sí mismo')]
    public function test_change_company_role(): void
    {
        $this->assertTrue($this->a['admin']->can('changeCompanyRole', $this->a['cuA']));
        $this->assertFalse($this->a['admin']->can('changeCompanyRole', $this->a['agSup']), 'el rol de un agente no se cambia así');
        $this->assertTrue($this->a['caA']->can('changeCompanyRole', $this->a['cuA']));
        $this->assertFalse($this->a['caA']->can('changeCompanyRole', $this->a['caA']), 'no el suyo');
        $this->assertFalse($this->a['caA']->can('changeCompanyRole', $this->a['cuB']), 'otra empresa');
        $this->assertFalse($this->a['cuA']->can('changeCompanyRole', $this->a['cuA2']));
    }

    #[TestDox('Solo el administrador cambia el equipo de un agente')]
    public function test_change_team(): void
    {
        $this->assertTrue($this->a['admin']->can('changeTeam', $this->a['agSup']));
        $this->assertFalse($this->a['admin']->can('changeTeam', $this->a['cuA']), 'solo agentes tienen equipo');
        foreach (['cuA', 'caA', 'agSup', 'agApp'] as $key) {
            $this->assertFalse($this->a[$key]->can('changeTeam', $this->a['agSup']), $key);
        }
    }

    #[TestDox('Traspasar el rol de administrador (R9): solo el administrador, a un agente activo y distinto de él')]
    public function test_transfer_admin_role(): void
    {
        $inactive = User::factory()->agent()->deactivated()->create();

        $this->assertTrue($this->a['admin']->can('transferAdmin', $this->a['agSup']));
        $this->assertTrue($this->a['admin']->can('transferAdmin', $this->a['agApp']));
        $this->assertFalse($this->a['admin']->can('transferAdmin', $this->a['admin']), 'a sí mismo no');
        $this->assertFalse($this->a['admin']->can('transferAdmin', $this->a['cuA']), 'a un cliente no');
        $this->assertFalse($this->a['admin']->can('transferAdmin', $this->a['caA']), 'a un administrador de empresa no');
        $this->assertFalse($this->a['admin']->can('transferAdmin', $inactive), 'a un agente dado de baja no');
        foreach (['cuA', 'caA', 'agSup', 'agApp'] as $key) {
            $this->assertFalse($this->a[$key]->can('transferAdmin', $this->a['agSup']), "$key no puede traspasar el rol");
        }
    }

    // ---------- ver cuentas ----------

    #[TestDox('Ver y listar cuentas: el administrador todas; el administrador de empresa las de SU empresa; cada uno, la suya (R1)')]
    public function test_view_users(): void
    {
        // Ambos administradores de empresa pueden LISTAR cuentas; la lista se limita a su propia empresa (R1).
        $this->assertOnly(['admin', 'caA', 'caB'], 'viewAny', User::class);
        $this->assertTrue($this->a['admin']->can('view', $this->a['cuB']));
        $this->assertTrue($this->a['caA']->can('view', $this->a['cuA']));
        $this->assertTrue($this->a['caA']->can('view', $this->a['cuA2']));
        $this->assertFalse($this->a['caA']->can('view', $this->a['cuB']), 'otra empresa (R1)');
        $this->assertFalse($this->a['caA']->can('view', $this->a['agSup']), 'un empleado');
        $this->assertTrue($this->a['cuA']->can('view', $this->a['cuA']), 'una persona se ve a sí misma');
        $this->assertFalse($this->a['cuA']->can('view', $this->a['cuA2']), 'un compañero no ve a otro');
        $this->assertFalse($this->a['agSup']->can('view', $this->a['cuA']));
    }
}
