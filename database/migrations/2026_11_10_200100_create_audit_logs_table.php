<?php

/*
 * Auditoría (sección «Registros» del administrador): quién hizo qué en la aplicación, fuera de los tickets
 * (el historial de cada ticket ya está en `ticket_events`, R5). Cuándo tocarlo: casi nunca; los hechos nuevos
 * se añaden llamando a App\Support\Audit::record(), no cambiando la tabla.
 *
 * Trampas:
 *  - SOLO SE AÑADE (sin `updated_at`; no hay UPDATE ni DELETE). La purga por plazo de conservación, cuando
 *    se decida, será un comando propio y explícito.
 *  - `actor_id` nulo = el sistema o alguien sin sesión (un intento de acceso fallido). Clave RESTRICT: una
 *    cuenta con rastro no se borra, se da de baja.
 *  - `ip` es dato personal: solo se guarda en los hechos de acceso.
 *  - `properties` NUNCA lleva contraseñas, claves ni códigos.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);                 // clave estable, ver App\Models\AuditLog::EVENTS
            $table->string('description', 500);          // frase legible, ya redactada
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('users')->restrictOnDelete(); // persona afectada
            $table->string('ip', 45)->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at', 'id']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
