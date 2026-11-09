<?php

/*
 * Tickets y el contador de su número. Ver docs/plan/modelo-de-datos.md, sección «Tickets».
 * Cuándo tocarlo: al añadir datos al ticket. Reglas: R1, R4, R6, R13, R14, R15, R16.
 *
 * Trampas:
 *  - `ticket_sequences` NO es redundante: reparte el número bloqueando la fila (ver
 *    App\Models\TicketSequence). MAX(sequence)+1 repetiría números con dos altas a la vez.
 *  - No hay columna `description`: la descripción es el primer mensaje del hilo.
 *  - El equipo que atiende el ticket no se guarda: se deduce del departamento.
 *  - Todas las claves foráneas son RESTRICT: no hay borrado de nada que tenga tickets.
 *  - Los CHECK repiten los valores de TicketStatus y Department (EnumsMatchDatabaseTest).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_value')->default(0);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->string('department', 20);
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('subject', 200);
            $table->string('status', 20)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('related_ticket_id')->nullable()->constrained('tickets')->restrictOnDelete();
            $table->text('resolution')->nullable(); // cómo se resolvió (R16)
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->useCurrent();
            $table->timestamps();

            $table->unique(['year', 'sequence']);
            // Índices de las consultas de las listas: por empresa, por asignado y por departamento.
            $table->index(['company_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index(['department', 'status']);
            $table->index('last_activity_at');
        });

        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_department_check
            CHECK (department IN ('support', 'web_apps', 'websites', 'other'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check
            CHECK (status IN ('open', 'in_progress', 'pending_customer', 'resolved', 'closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_sequences');
    }
};
