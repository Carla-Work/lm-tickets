<?php

/*
 * Añade el equipo «Contabilidad» (`accounting`) a la restricción de `users.team`.
 * Cuándo tocarla: nunca; un equipo más es una migración nueva igual que esta (tocar también App\Enums\Team).
 *
 * Contexto (2026-11-10): «Otros» pasa a llamarse «Contabilidad» y lo atiende un equipo propio (comercial@lingmarco.es).
 * El departamento mantiene su valor guardado `other`; solo se amplía la lista de equipos válidos de un agente.
 *
 * Trampa: el CHECK conserva el `IS NOT NULL` explícito del original (con NULL un CHECK no falla; ver la migración
 * add_role_company_and_team_to_users_table). Las restricciones CHECK necesitan MySQL >= 8.0.16.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_team_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_team_check CHECK (
            (role = 'agent' AND team IS NOT NULL AND team IN ('support', 'applications', 'accounting'))
            OR (role <> 'agent' AND team IS NULL)
        )");
    }

    public function down(): void
    {
        // Antes de volver a la lista corta, nadie puede quedarse con un equipo que ya no existe.
        DB::statement("UPDATE users SET team = 'support' WHERE team = 'accounting'");
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_team_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_team_check CHECK (
            (role = 'agent' AND team IS NOT NULL AND team IN ('support', 'applications'))
            OR (role <> 'agent' AND team IS NULL)
        )");
    }
};
