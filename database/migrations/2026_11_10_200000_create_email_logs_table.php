<?php

/*
 * Registro de correos (sección «Registros» del administrador). Una fila por correo que la aplicación intenta enviar.
 * Cuándo tocarlo: al registrar algo más de cada correo. Lo escribe App\Support\MailLogger al vuelo.
 *
 * Trampas:
 *  - `body` es NULO a propósito para los correos con enlaces de un solo uso (invitación y recuperación de
 *    contraseña): guardarlo dejaría al administrador (y a quien lea una copia) un enlace que entra en la cuenta
 *    de otra persona. Qué correos no guardan cuerpo lo decide MailLogger::WITHOUT_BODY.
 *  - Datos personales (direcciones y asuntos con el nombre de un ticket): entra en el plan de conservación
 *    (docs/plan/conservacion-datos.md); la purga programada está PENDIENTE.
 *  - `status` sin CHECK a propósito (ver EmailLog): los valores viven en el modelo.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 12)->default('pending');
            $table->string('subject', 255)->nullable();
            $table->string('from_email', 255)->nullable();
            $table->string('to_emails', 1000)->nullable(); // direcciones separadas por coma, para buscar con LIKE
            $table->string('kind', 100)->nullable();       // clase de la notificación que lo originó
            $table->longText('body')->nullable();          // HTML; nulo en invitación y recuperación (ver arriba)
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->index(['created_at', 'id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
