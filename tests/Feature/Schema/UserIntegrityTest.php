<?php

/*
 * Integridad de la tabla `users` (restricciones de la base de datos, no de la aplicación).
 * Cuándo tocarlo: si cambia el modelo de roles. Si un test falla, se arregla el esquema o se
 * discute la regla con la usuaria; NO se adapta el test.
 *
 * Reglas: R9 (un único administrador activo) y coherencia rol-empresa-equipo.
 */

namespace Tests\Feature\Schema;

use App\Enums\Team;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class UserIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('Un usuario de empresa sin empresa no se puede guardar')]
    public function test_company_user_requires_company(): void
    {
        $this->expectException(QueryException::class);
        User::factory()->create(['company_id' => null]);
    }

    #[TestDox('Un agente no puede pertenecer a una empresa cliente')]
    public function test_agent_cannot_belong_to_company(): void
    {
        $this->expectException(QueryException::class);
        User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);
    }

    #[TestDox('El administrador no puede pertenecer a una empresa cliente')]
    public function test_admin_cannot_belong_to_company(): void
    {
        $this->expectException(QueryException::class);
        User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);
    }

    #[TestDox('Un agente sin equipo no se puede guardar')]
    public function test_agent_requires_team(): void
    {
        $this->expectException(QueryException::class);
        User::factory()->agent()->create(['team' => null]);
    }

    #[TestDox('Un usuario de empresa con equipo no se puede guardar')]
    public function test_company_user_cannot_have_team(): void
    {
        $this->expectException(QueryException::class);
        User::factory()->create(['team' => Team::Support->value]);
    }

    #[TestDox('Un rol inexistente no se puede guardar en la base de datos')]
    public function test_invalid_role_is_rejected_by_the_database(): void
    {
        // Se inserta SIN pasar por el modelo: la enumeración de PHP rechazaría «supervisor» antes de
        // llegar a la base, y lo que se quiere probar es la restricción CHECK de la propia base.
        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            'name' => 'X', 'email' => 'x@example.test', 'password' => 'x',
            'role' => 'supervisor', // el supervisor ya no existe
            'company_id' => Company::factory()->create()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[TestDox('Un rol inexistente tampoco se puede asignar desde PHP (la enumeración lo rechaza)')]
    public function test_invalid_role_is_rejected_by_the_enum(): void
    {
        $this->expectException(\ValueError::class);
        User::factory()->create(['role' => 'supervisor']);
    }

    #[TestDox('No puede haber dos administradores activos a la vez (R9)')]
    public function test_only_one_active_admin(): void
    {
        User::factory()->admin()->create();

        $this->expectException(QueryException::class);
        User::factory()->admin()->create();
    }

    #[TestDox('Un administrador dado de baja no impide tener otro activo (R9)')]
    public function test_deactivated_admin_does_not_count(): void
    {
        User::factory()->admin()->deactivated()->create();
        User::factory()->admin()->create();

        $this->assertSame(1, User::where('role', 'admin')->whereNull('deactivated_at')->count());
        $this->assertSame(2, User::where('role', 'admin')->count());
    }

    #[TestDox('El traspaso del rol de administrador funciona si se degrada primero al antiguo (R9)')]
    public function test_admin_handover_in_a_transaction(): void
    {
        $antiguo = User::factory()->admin()->create();
        $nuevo = User::factory()->agent(Team::Applications)->create();

        DB::transaction(function () use ($antiguo, $nuevo): void {
            $antiguo->forceFill(['role' => 'agent', 'team' => Team::Support->value])->save();
            $nuevo->forceFill(['role' => 'admin', 'team' => null])->save();
        });

        $this->assertSame(UserRole::Admin, $nuevo->fresh()->role);
        $this->assertSame(UserRole::Agent, $antiguo->fresh()->role);
    }

    #[TestDox('Ascender a administrador sin degradar antes al actual se rechaza (R9)')]
    public function test_promoting_second_admin_without_demoting_fails(): void
    {
        User::factory()->admin()->create();
        $agente = User::factory()->agent()->create();

        $this->expectException(QueryException::class);
        $agente->forceFill(['role' => 'admin', 'team' => null])->save();
    }

    #[TestDox('Una empresa con usuarios no se puede borrar')]
    public function test_company_with_users_cannot_be_deleted(): void
    {
        $usuario = User::factory()->create();

        $this->expectException(QueryException::class);
        $usuario->company->delete();
    }

    #[TestDox('El rol no se puede asignar en masa desde un formulario')]
    public function test_role_is_not_mass_assignable(): void
    {
        $usuario = new User;
        $usuario->fill(['name' => 'X', 'email' => 'x@example.test', 'role' => 'admin', 'company_id' => 1, 'team' => 'support']);

        $this->assertNull($usuario->getAttribute('role'), 'role no debe poder rellenarse con fill()');
        $this->assertNull($usuario->getAttribute('company_id'));
        $this->assertNull($usuario->getAttribute('team'));
    }
}
