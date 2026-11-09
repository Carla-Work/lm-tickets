<?php

/*
 * Categorías de incidencia, colgadas de un departamento. Las edita el administrador.
 * Cuándo tocarlo: si cambia la relación categoría-departamento.
 *
 * Trampa: el CHECK repite los valores de App\Enums\Department. EnumsMatchDatabaseTest falla si
 * se añade un departamento y se olvida esta restricción.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('department', 20);
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['department', 'name']);
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_department_check
            CHECK (department IN ('support', 'web_apps', 'websites', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
