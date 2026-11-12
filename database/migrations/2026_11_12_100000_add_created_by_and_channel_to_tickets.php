<?php

/*
 * Quién creó el ticket y por qué canal (P1 «ticket creado por un agente», docs/plan/p1-ticket-creado-por-agente.md).
 * Cuándo tocarla: nunca una vez aplicada; los cambios van en otra migración.
 *
 * Trampas:
 *  - `created_by_id` es NOT NULL: los tickets existentes los abrió su solicitante, así que se rellena con `requester_id` ANTES
 *    de endurecer la columna. En un ticket que abre el propio cliente coincide con `requester_id`; solo difiere cuando lo crea
 *    una persona del equipo a nombre de un cliente. Es dato INTERNO (el cliente no lo ve).
 *  - `channel` lleva su CHECK con los valores de App\Enums\TicketChannel (repetidos aquí a propósito, como el resto de enums).
 *    Los tickets existentes quedan en 'web'.
 *  - Nada en cascada: la clave foránea es RESTRICT (una persona del equipo que creó tickets no se borra, solo se da de baja).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('created_by_id')->nullable()->after('requester_id')->constrained('users')->restrictOnDelete();
            $table->string('channel', 10)->default('web')->after('status');
        });

        DB::statement('UPDATE tickets SET created_by_id = requester_id WHERE created_by_id IS NULL');
        DB::statement('ALTER TABLE tickets MODIFY created_by_id BIGINT UNSIGNED NOT NULL');
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_channel_check CHECK (channel IS NOT NULL AND channel IN ('web', 'email', 'phone', 'in_person', 'other'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tickets DROP CHECK tickets_channel_check');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_id');
            $table->dropColumn('channel');
        });
    }
};
