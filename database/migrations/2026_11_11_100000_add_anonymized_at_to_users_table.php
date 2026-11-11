<?php

/*
 * Marca de usuarios ya anonimizados por el plan de conservación (`retention:run`, defecto D-6). Cuándo tocarla: nunca
 * sin tocar RunRetention. Es la FECHA la que marca, no el texto: así se puede repetir el comando sin volver a
 * anonimizar y se distingue a quien se llama «Persona dada de baja» de verdad. Nada se borra: la fila y su id siguen
 * (los tickets, mensajes y eventos apuntan a ella con claves RESTRICT).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
