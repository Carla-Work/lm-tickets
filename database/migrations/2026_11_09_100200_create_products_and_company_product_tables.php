<?php

/*
 * Catálogo de productos y productos contratados por cada empresa.
 * Cuándo tocarlo: al cambiar cómo se asignan productos. Reglas: R6, R7, R19.
 *
 * Trampa (R19): las claves foráneas son RESTRICT. Un producto asignado a una empresa o usado en un
 * ticket NO se puede borrar; se desactiva (`active = false`). No cambiar a CASCADE «para
 * simplificar»: dejaría tickets apuntando a un producto que ya no existe.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description', 200)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('company_product', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->primary(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_product');
        Schema::dropIfExists('products');
    }
};
