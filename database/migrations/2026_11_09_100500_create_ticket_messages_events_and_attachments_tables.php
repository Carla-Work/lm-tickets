<?php

/*
 * Hilo del ticket (mensajes y notas internas), historial de cambios y adjuntos.
 * Cuándo tocarlo: al añadir algo al hilo. Reglas: R2, R3, R5.
 *
 * Trampas:
 *  - R2: mensajes públicos y notas internas comparten tabla (`kind`). Todo lo que ve el cliente
 *    debe pasar por TicketMessage::scopePublic(); un SELECT sin filtrar filtra notas internas.
 *  - R5: `ticket_events` SOLO SE AÑADE (sin updated_at). No hay UPDATE ni DELETE de historial.
 *  - R3: `attachments.path` es una ruta del disco PRIVADO, nunca una URL pública.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 10)->default('public');
            $table->text('body');
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
        });
        DB::statement("ALTER TABLE ticket_messages ADD CONSTRAINT ticket_messages_kind_check
            CHECK (kind IN ('public', 'internal'))");

        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete(); // nulo = el sistema
            $table->string('type', 30); // sin CHECK a propósito: ver TicketEventType
            $table->json('data')->nullable();
            $table->boolean('visible_to_customer')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete(); // duplicado a propósito: autorizar sin unir
            $table->foreignId('ticket_message_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_name');
            $table->string('path', 500);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->timestamp('created_at')->useCurrent();
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('ticket_messages');
    }
};
