<?php

/*
 * Valoración de la atención de un ticket (P1, mockups validados 2026-11-11). Plan: docs/plan/p1-valoracion-atencion.md.
 * Cuándo tocarla: nunca una vez aplicada; los cambios van en otra migración.
 *
 * Trampas:
 *  - `ticket_id` es ÚNICO: una sola valoración por ticket, y es la base la que lo garantiza (dos envíos a la vez no
 *    pueden colarse los dos). No hay `updated_at`: la valoración solo se añade, no se edita.
 *  - `score` es NOT NULL con su CHECK de 1 a 5 (con `IS NOT NULL` explícito: con NULL el CHECK no falla).
 *  - `comment` es texto libre del cliente = dato personal: `retention:run` lo sustituye al anonimizar el ticket y
 *    conserva la puntuación.
 *  - Claves foráneas RESTRICT: nada se borra en cascada.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('rated_by')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('score');
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE ticket_ratings ADD CONSTRAINT ticket_ratings_score_check CHECK (score IS NOT NULL AND score BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_ratings');
    }
};
