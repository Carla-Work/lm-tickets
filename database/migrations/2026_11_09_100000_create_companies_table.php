<?php

/*
 * Empresas cliente. Ver docs/plan/modelo-de-datos.md.
 * Cuándo tocarlo: al añadir datos de la empresa. Pedir solo lo necesario (minimizar datos personales).
 *
 * Trampa: no hay borrado de empresas; la baja es `deactivated_at` (los tickets se conservan).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tax_id', 20)->unique(); // CIF: dos empresas no pueden compartirlo
            $table->string('phone', 30)->nullable();
            $table->string('contact_email')->nullable();
            $table->timestamp('deactivated_at')->nullable(); // baja; nunca DELETE
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
