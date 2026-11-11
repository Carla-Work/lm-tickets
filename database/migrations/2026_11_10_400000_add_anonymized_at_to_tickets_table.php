<?php

/*
 * Marca de «ya anonimizado» en los tickets, para el plan de conservación (retention:run).
 * Cuándo tocarla: nunca; ver docs/plan/conservacion-datos.md.
 *
 * Por qué una marca y no «mirar si el asunto ya es el anonimizado»: un cliente podría titular su ticket igual que el
 * texto de sustitución, y el comando volvería a procesarlo y a contarlo. La fecha además sirve de rastro (qué se
 * anonimizó y cuándo) sin guardar nada personal.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('closed_at');
            $table->index(['status', 'closed_at']); // la consulta de la purga: cerrados antiguos
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['status', 'closed_at']);
            $table->dropColumn('anonymized_at');
        });
    }
};
