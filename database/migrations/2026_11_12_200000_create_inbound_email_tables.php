<?php

/*
 * Correo entrante (P1, docs/plan/p1-email-entrante.md): dominios de empresa, palabras clave, correos recibidos y sus adjuntos, y las
 * marcas de correo en los mensajes. Cuándo tocarla: nunca una vez aplicada; los cambios van en otra migración.
 *
 * Trampas:
 *  - `inbound_emails` guarda UNA fila por correo procesado (también los que crearon ticket o entraron en un hilo): es la forma de no
 *    procesar dos veces el mismo correo SIN tocar el buzón compartido. El CUERPO solo se conserva mientras el correo está en
 *    cuarentena (pending/spam); al convertirse en ticket o mensaje, el texto vive allí y aquí se vacía. `retention:run` purga por plazo.
 *  - Nada en cascada: claves foráneas RESTRICT. Una palabra clave sí se borra (no es dato de clientes), por eso su tabla no tiene dependientes.
 *  - Los estados de `inbound_emails.status` están repetidos en App\Models\InboundEmail y en el CHECK.
 *  - `categories.is_default`: «Sin clasificar» en cada departamento es la categoría con la que nacen los tickets que llegan por correo (R6 la exige). Es
 *    interna: NO se ofrece al cliente ni al equipo al crear un ticket, ni al mover de departamento (solo se asigna aquí, por el correo entrante).
 *  - `ticket_messages.email_message_id` es el Message-ID del correo enviado o recibido para ese mensaje: con él una respuesta del cliente
 *    entra en su hilo. Único: un mismo correo no puede añadirse dos veces.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('domain', 190)->unique(); // un dominio solo puede ser de UNA empresa
            $table->timestamps();
        });

        Schema::create('product_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('keyword', 100)->unique(); // normalizada (minúsculas, sin tildes): cada palabra es de UN producto
            $table->timestamps();
        });

        Schema::create('incident_words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 100)->unique(); // normalizada
            $table->timestamps();
        });

        Schema::create('inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->string('mailbox', 20);                       // clave del buzón (config/inbound.php)
            $table->string('message_id', 255)->unique();
            $table->string('in_reply_to', 255)->nullable();
            $table->string('from_email', 190);
            $table->string('from_name', 190)->nullable();
            $table->json('to_addresses');                        // Para y CC (para asignar al compañero nombrado)
            $table->string('subject', 255);
            $table->text('body')->nullable();                    // solo en cuarentena; ver arriba
            $table->boolean('auth_ok')->default(false);          // ¿SPF o DKIM correctos según el servidor?
            $table->string('status', 12)->default('pending');
            $table->json('reasons');                             // por qué está donde está (pistas) — lista de textos
            $table->timestamp('received_at');
            $table->foreignId('ticket_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'received_at']);
        });
        DB::statement("ALTER TABLE inbound_emails ADD CONSTRAINT inbound_emails_status_check CHECK (status IS NOT NULL AND status IN ('pending', 'spam', 'ticket', 'reply', 'ignored', 'rejected'))");

        Schema::create('inbound_email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_email_id')->constrained()->restrictOnDelete();
            $table->string('path', 255);                         // disco PRIVADO (R3)
            $table->string('original_name', 150);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->string('email_message_id', 255)->nullable()->unique()->after('kind');
            $table->boolean('via_email')->default(false)->after('email_message_id'); // el mensaje del CLIENTE entró por correo
            $table->timestamp('emailed_at')->nullable()->after('via_email');          // la respuesta del EQUIPO salió por correo
        });

        // Categoría por defecto de los tickets que llegan por correo, una por departamento (idempotente).
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('active');
        });
        $name = (string) config('inbound.default_category', 'Sin clasificar');
        foreach (['support', 'web_apps', 'websites', 'other'] as $department) {
            if (DB::table('categories')->where('department', $department)->where('name', $name)->exists()) {
                DB::table('categories')->where('department', $department)->where('name', $name)->update(['is_default' => true]);
            } else {
                DB::table('categories')->insert(['department' => $department, 'name' => $name, 'active' => true, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('categories')->where('is_default', true)->whereNotIn('id', DB::table('tickets')->select('category_id'))->delete();
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropColumn(['email_message_id', 'via_email', 'emailed_at']);
        });
        Schema::dropIfExists('inbound_email_attachments');
        DB::statement('ALTER TABLE inbound_emails DROP CHECK inbound_emails_status_check');
        Schema::dropIfExists('inbound_emails');
        Schema::dropIfExists('incident_words');
        Schema::dropIfExists('product_keywords');
        Schema::dropIfExists('company_domains');
    }
};
