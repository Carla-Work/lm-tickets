<?php

/*
 * Avisos en el panel (la campana). Tabla estándar de Laravel (`php artisan make:notifications-table`).
 * Cuándo tocarlo: casi nunca; los avisos se envían con las clases de notificación del paso 5.
 * Regla R2: nunca debe llegar a un aviso del cliente el contenido de una nota interna.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
