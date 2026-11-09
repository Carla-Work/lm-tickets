<?php

/*
 * Amplía `users` (la tabla del esqueleto) con rol, empresa, equipo y estado de la cuenta.
 * Ver docs/plan/modelo-de-datos.md, sección «Usuarios».
 * Cuándo tocarlo: si cambia el modelo de roles. Tocar también app/Enums/UserRole.php y Team.php.
 *
 * Trampas:
 *  - `role` NO lleva valor por defecto a propósito: un rol por defecto sería una escalada de
 *    privilegios esperando a pasar.
 *  - `admin_slot` es una columna GENERADA con índice único: es lo que impide dos administradores
 *    activos (R9). No es código muerto aunque nadie la lea.
 *  - Las restricciones CHECK necesitan MySQL >= 8.0.16 o MariaDB >= 10.2: comprobar en IONOS.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->after('password');
            $table->foreignId('company_id')->nullable()->after('role')->constrained()->restrictOnDelete();
            $table->string('team', 20)->nullable()->after('company_id');
            $table->string('phone', 30)->nullable()->after('team');
            $table->timestamp('activated_at')->nullable();   // nulo = invitada, sin contraseña elegida (R18)
            $table->timestamp('deactivated_at')->nullable(); // baja; nunca DELETE
            // R9: vale 1 solo para el administrador ACTIVO; con el índice único no puede haber dos.
            $table->unsignedTinyInteger('admin_slot')->nullable()
                ->virtualAs("IF(role = 'admin' AND deactivated_at IS NULL, 1, NULL)")->unique();
        });

        // Un cliente tiene empresa; un empleado no. También valida que el rol sea uno de los 4.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_company_check CHECK (
            (role IN ('company_user', 'company_admin') AND company_id IS NOT NULL)
            OR (role IN ('agent', 'admin') AND company_id IS NULL)
        )");
        // Un agente tiene equipo (de los 2 existentes); nadie más lo tiene.
        // TRAMPA SQL: un CHECK solo falla cuando da FALSO, no cuando da NULL («desconocido»).
        // Con `team IN (...)` a secas, un agente con team = NULL daba NULL y SE ACEPTABA. Por eso
        // lleva `team IS NOT NULL` explícito. UserIntegrityTest::test_agent_requires_team lo vigila.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_team_check CHECK (
            (role = 'agent' AND team IS NOT NULL AND team IN ('support', 'applications'))
            OR (role <> 'agent' AND team IS NULL)
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_team_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_company_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['admin_slot']);
            $table->dropColumn('admin_slot');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['role', 'team', 'phone', 'activated_at', 'deactivated_at']);
        });
    }
};
