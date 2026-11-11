<?php

/*
 * Prioridad interna del ticket y etiquetas internas (P1, mockups validados 2026-11-11).
 * Cuándo tocarla: nunca una vez aplicada; los cambios van en otra migración. Detalle: docs/plan/p1-prioridades-y-etiquetas.md.
 *
 * Trampas:
 *  - `tickets.priority` es NOT NULL con valor por defecto 'normal' y su CHECK: las filas antiguas quedan en Normal sin
 *    tocarlas. Los cuatro valores están repetidos en App\Enums\Priority.
 *  - Nada se borra en cascada (todas las claves foráneas son RESTRICT): una etiqueta en uso solo se desactiva (`active`).
 *  - El índice (priority, status) sirve a la bandeja ordenada por prioridad y al filtro «Urgentes y altas».
 *  - `ticket_tag` no lleva fechas ni id propio: la clave primaria es la pareja, así no puede repetirse una etiqueta.
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
            $table->string('priority', 10)->default('normal')->after('status');
            $table->index(['priority', 'status'], 'tickets_priority_status_index');
        });
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_priority_check CHECK (priority IS NOT NULL AND priority IN ('low', 'normal', 'high', 'urgent'))");

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('ticket_tag', function (Blueprint $table) {
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('tag_id')->constrained()->restrictOnDelete();
            $table->primary(['ticket_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_tag');
        Schema::dropIfExists('tags');

        DB::statement('ALTER TABLE tickets DROP CHECK tickets_priority_check');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_priority_status_index');
            $table->dropColumn('priority');
        });
    }
};
