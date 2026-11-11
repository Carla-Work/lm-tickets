<?php

/*
 * Plan de conservación (`php artisan retention:run`): tickets cerrados antiguos se anonimizan y pierden sus adjuntos;
 * correos y auditoría antiguos se borran. Plazos en config/retention.php (provisionales: 2 años).
 * Cuándo tocarla: al cambiar un plazo o lo que se purga; cambiar también docs/plan/conservacion-datos.md.
 * Trampas que vigila:
 *  - solo los CERRADOS y solo los más antiguos que el plazo (un ticket vivo, resuelto o reciente NO se toca);
 *  - los ficheros de los adjuntos desaparecen del disco, no solo las filas;
 *  - se anonimizan también las notas internas, y el ticket conserva su historial de estados;
 *  - `--dry-run` no cambia NADA y repetir la ejecución no vuelve a procesar lo ya hecho;
 *  - la propia purga deja una fila de auditoría que no se borra a sí misma.
 * Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Console;

use App\Console\Commands\RunRetention;
use App\Enums\Department;
use App\Enums\TicketNoticeKind;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use App\Notifications\TicketNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
        Storage::fake(Attachment::DISK);
        config(['retention.tickets_years' => 2, 'retention.logs_years' => 2]);
    }

    /** Un ticket cerrado hace `$aniosCerrado` años, con un mensaje público, una nota interna, un adjunto real y un aviso. */
    private function cerrado(float $aniosCerrado, string $asunto = 'Mi factura de licencias'): Ticket
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::Closed, 'agSup');
        $ticket->forceFill([
            'subject' => $asunto, 'resolution' => 'Cambiamos la IP 10.0.0.5', 'closed_at' => now()->subDays((int) ($aniosCerrado * 365)),
        ])->save();

        $publico = TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->a['cuA']->id, 'body' => 'Mi DNI es 12345678Z']);
        TicketMessage::factory()->internal()->create(['ticket_id' => $ticket->id, 'author_id' => $this->a['agSup']->id, 'body' => 'Cliente conflictivo, ojo']);

        $ruta = "attachments/{$ticket->id}.txt";
        Storage::disk(Attachment::DISK)->put($ruta, 'contenido sensible');
        $adjunto = new Attachment;
        $adjunto->forceFill(['ticket_id' => $ticket->id, 'ticket_message_id' => $publico->id, 'uploaded_by' => $this->a['cuA']->id,
            'original_name' => 'dni.txt', 'path' => $ruta, 'mime_type' => 'text/plain', 'size' => 18])->save();

        $this->a['cuA']->notify(new TicketNotice(TicketNoticeKind::Replied, $ticket->id, 'Respuesta en el ticket', $asunto));

        return $ticket;
    }

    private function ejecutar(array $opciones = []): void
    {
        $this->artisan('retention:run', $opciones)->assertSuccessful();
    }

    #[TestDox('Un ticket cerrado hace más del plazo queda anonimizado: asunto, solución, mensajes y notas internas, sin adjuntos ni avisos; el ticket y su historial se conservan')]
    public function test_old_closed_ticket_is_anonymized(): void
    {
        $viejo = $this->cerrado(3);
        (new TicketEvent)->forceFill(['ticket_id' => $viejo->id, 'actor_id' => $this->a['agSup']->id, 'type' => 'closed', 'data' => ['from' => 'resolved', 'to' => 'closed'], 'visible_to_customer' => true])->save();
        $ruta = Attachment::where('ticket_id', $viejo->id)->firstOrFail()->path;

        $this->ejecutar();

        $viejo = $viejo->fresh();
        $this->assertSame(RunRetention::REPLACEMENT_SUBJECT, $viejo->subject);
        $this->assertNull($viejo->resolution);
        $this->assertNotNull($viejo->anonymized_at);
        $this->assertSame(TicketStatus::Closed, $viejo->status, 'el ticket se conserva');
        $this->assertSame($this->a['cuA']->id, $viejo->requester_id);
        foreach (TicketMessage::where('ticket_id', $viejo->id)->get() as $mensaje) {
            $this->assertSame(RunRetention::REPLACEMENT_BODY, $mensaje->body, 'también las notas internas');
        }
        $this->assertSame(2, TicketMessage::where('ticket_id', $viejo->id)->count(), 'los mensajes no se borran, solo su contenido');
        $this->assertSame(0, Attachment::where('ticket_id', $viejo->id)->count());
        Storage::disk(Attachment::DISK)->assertMissing($ruta);
        $this->assertSame(0, $this->a['cuA']->notifications()->count());
        $this->assertSame(1, TicketEvent::where('ticket_id', $viejo->id)->count(), 'el historial de estados se conserva');
    }

    #[TestDox('NO se toca un ticket reciente, uno vivo, uno resuelto sin cerrar, ni el de justo dentro del plazo')]
    public function test_other_tickets_are_untouched(): void
    {
        $reciente = $this->cerrado(1, 'Reciente');
        $casi = $this->cerrado(1.9, 'Casi dos años');
        $vivo = $this->cerrado(5, 'Vivo');
        $vivo->forceFill(['status' => TicketStatus::InProgress->value])->save();
        $resuelto = $this->cerrado(5, 'Resuelto sin cerrar');
        $resuelto->forceFill(['status' => TicketStatus::Resolved->value])->save();

        $this->ejecutar();

        foreach ([$reciente, $casi, $vivo, $resuelto] as $ticket) {
            $fresco = $ticket->fresh();
            $this->assertNull($fresco->anonymized_at, "«{$fresco->subject}» no debía tocarse");
            $this->assertNotSame(RunRetention::REPLACEMENT_SUBJECT, $fresco->subject);
            $this->assertSame(1, Attachment::where('ticket_id', $ticket->id)->count());
            $this->assertStringContainsString('Mi DNI', TicketMessage::where('ticket_id', $ticket->id)->where('kind', 'public')->firstOrFail()->body);
        }
    }

    #[TestDox('El plazo sale de la configuración: con 1 año el de hace 1,9 se anonimiza y con 5 el de hace 3 no')]
    public function test_period_comes_from_config(): void
    {
        $casi = $this->cerrado(1.9);
        $tres = $this->cerrado(3);

        config(['retention.tickets_years' => 5]);
        $this->ejecutar();
        $this->assertNull($tres->fresh()->anonymized_at);

        config(['retention.tickets_years' => 1]);
        $this->ejecutar();
        $this->assertNotNull($casi->fresh()->anonymized_at);
        $this->assertNotNull($tres->fresh()->anonymized_at);
    }

    #[TestDox('El plazo de los registros es independiente del de los tickets: con registros a 1 año y tickets a 5, el correo de hace 1,5 años se borra')]
    public function test_logs_period_is_independent(): void
    {
        config(['retention.tickets_years' => 5, 'retention.logs_years' => 1]);
        EmailLog::query()->create(['status' => 'sent', 'subject' => 'año y medio', 'created_at' => now()->subDays(550)]);
        AuditLog::query()->create(['event' => 'login', 'description' => 'año y medio', 'created_at' => now()->subDays(550)]);

        $this->ejecutar();

        $this->assertSame(0, EmailLog::count());
        $this->assertFalse(AuditLog::where('description', 'año y medio')->exists());
    }

    #[TestDox('Los enlaces de contraseña caducados (más viejos que el plazo MÁS LARGO, el de la invitación) se borran; uno de hace 3 días se conserva porque una invitación aún valdría (D-5)')]
    public function test_expired_reset_tokens_are_purged(): void
    {
        DB::table('password_reset_tokens')->insert([
            ['email' => 'viejo@example.com', 'token' => 'x', 'created_at' => now()->subDays(8)],
            ['email' => 'reciente@example.com', 'token' => 'y', 'created_at' => now()->subDays(3)],
        ]);

        $this->artisan('retention:run', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(2, DB::table('password_reset_tokens')->count(), 'la simulación no borra');

        $this->ejecutar();

        $this->assertSame(['reciente@example.com'], DB::table('password_reset_tokens')->pluck('email')->all());
        $this->assertSame(1, AuditLog::where('event', 'retention_run')->firstOrFail()->properties['reset_tokens']);
    }

    #[TestDox('Un usuario de baja hace más del plazo queda anonimizado (nombre, correo, teléfono, clave, 2FA, avisos y enlaces) pero conserva fila e id; los de baja reciente, los activos y las empresas no se tocan (D-6)')]
    public function test_old_deactivated_users_are_anonymized(): void
    {
        $viejo = $this->a['cuA2'];
        $viejo->forceFill(['deactivated_at' => now()->subYears(3), 'phone' => '600111222', 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()->subYears(3)])->save();
        $correoViejo = $viejo->email;
        $claveVieja = $viejo->password;
        DB::table('password_reset_tokens')->insert(['email' => $correoViejo, 'token' => 'x', 'created_at' => now()]);
        $viejo->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'x', 'data' => ['ticket_id' => 1]]);

        $reciente = $this->a['agSup'];
        $reciente->forceFill(['deactivated_at' => now()->subYear()])->save();
        $nombreReciente = $reciente->name;

        $ticket = $this->cerrado(0.1); // un ticket suyo no depende de que el usuario exista con su nombre
        $empresa = $this->empresaA->name;

        $this->artisan('retention:run', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($correoViejo, $viejo->fresh()->email, 'la simulación no cambia nada');

        $this->ejecutar();

        $viejo = $viejo->fresh();
        $this->assertSame(RunRetention::REPLACEMENT_USER_NAME, $viejo->name);
        $this->assertSame("baja-{$viejo->id}@anonimo.invalid", $viejo->email);
        $this->assertNull($viejo->phone);
        $this->assertNotNull($viejo->anonymized_at);
        $this->assertNotSame($claveVieja, $viejo->password);
        $this->assertFalse($viejo->hasTwoFactorEnabled());
        $this->assertSame(0, $viejo->notifications()->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $correoViejo)->count());

        $this->assertSame($nombreReciente, $reciente->fresh()->name, 'baja reciente: intacta');
        $this->assertNull($reciente->fresh()->anonymized_at);
        $this->assertNull($this->a['cuA']->fresh()->anonymized_at, 'activos: intactos');
        $this->assertSame($empresa, $this->empresaA->fresh()->name, 'las empresas no se tocan');
        $this->assertSame($this->a['cuA']->id, $ticket->fresh()->requester_id);

        // Una cuenta anonimizada no se puede reactivar (ni siquiera el administrador): ya no tiene correo ni clave reales.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->a['admin'])->post(route('users.reactivate', $viejo))->assertForbidden();
        $this->assertNotNull($viejo->fresh()->deactivated_at);

        // Repetir no vuelve a anonimizar (la fecha es la marca).
        $fecha = $viejo->anonymized_at;
        $this->ejecutar();
        $this->assertEquals($fecha, $viejo->fresh()->anonymized_at);
    }

    #[TestDox('Los avisos de la campana más viejos que el plazo de registros y los trabajos fallidos de más de 90 días se borran; los recientes se conservan (D-5)')]
    public function test_old_notices_and_failed_jobs_are_purged(): void
    {
        foreach ([['viejo', now()->subYears(3)], ['nuevo', now()->subDays(5)]] as [$marca, $fecha]) {
            DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => 'user', 'notifiable_id' => 99, 'data' => json_encode(['m' => $marca]), 'created_at' => $fecha, 'updated_at' => $fecha]);
        }
        foreach ([['viejo', now()->subDays(100)], ['nuevo', now()->subDays(10)]] as [$marca, $fecha]) {
            DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => $marca, 'payload' => '{}', 'exception' => 'boom', 'failed_at' => $fecha]);
        }

        $this->artisan('retention:run', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(2, DB::table('notifications')->where('notifiable_id', 99)->count());
        $this->assertSame(2, DB::table('failed_jobs')->count());

        $this->ejecutar();

        $this->assertSame(['nuevo'], DB::table('notifications')->where('notifiable_id', 99)->get()->map(fn ($f) => json_decode($f->data)->m)->all());
        $this->assertSame(['nuevo'], DB::table('failed_jobs')->pluck('queue')->all());
    }

    #[TestDox('--dry-run enseña qué afectaría pero no cambia nada: ni tickets, ni adjuntos, ni ficheros, ni registros, ni auditoría')]
    public function test_dry_run_changes_nothing(): void
    {
        $viejo = $this->cerrado(3);
        $ruta = Attachment::where('ticket_id', $viejo->id)->firstOrFail()->path;
        EmailLog::query()->create(['status' => 'sent', 'subject' => 'viejo', 'created_at' => now()->subYears(3)]);
        AuditLog::query()->create(['event' => 'login', 'description' => 'antiguo', 'created_at' => now()->subYears(3)]);
        $auditoriaAntes = AuditLog::count();
        $correosAntes = EmailLog::count(); // incluye el correo del aviso que crea `cerrado()`

        $this->artisan('retention:run', ['--dry-run' => true])->expectsOutputToContain('SIMULACIÓN')->assertSuccessful();

        $this->assertNull($viejo->fresh()->anonymized_at);
        $this->assertSame('Mi factura de licencias', $viejo->fresh()->subject);
        Storage::disk(Attachment::DISK)->assertExists($ruta);
        $this->assertSame(1, Attachment::where('ticket_id', $viejo->id)->count());
        $this->assertSame($correosAntes, EmailLog::count());
        $this->assertSame($auditoriaAntes, AuditLog::count(), 'ni siquiera deja fila de auditoría');
        $this->assertSame(1, $this->a['cuA']->notifications()->count());
    }

    #[TestDox('Los correos y la auditoría más antiguos que el plazo se borran y los demás se conservan; la purga deja su propia fila de auditoría con los recuentos')]
    public function test_logs_are_purged(): void
    {
        EmailLog::query()->create(['status' => 'sent', 'subject' => 'viejo', 'created_at' => now()->subYears(3)]);
        EmailLog::query()->create(['status' => 'sent', 'subject' => 'reciente', 'created_at' => now()->subMonths(6)]);
        AuditLog::query()->create(['event' => 'login', 'description' => 'antiguo', 'created_at' => now()->subYears(3)]);
        AuditLog::query()->create(['event' => 'login', 'description' => 'reciente', 'created_at' => now()->subMonths(6)]);

        $this->ejecutar();

        $this->assertSame(['reciente'], EmailLog::pluck('subject')->all());
        $this->assertFalse(AuditLog::where('description', 'antiguo')->exists());
        $this->assertTrue(AuditLog::where('description', 'reciente')->exists());
        $registro = AuditLog::where('event', 'retention_run')->firstOrFail();
        $this->assertNull($registro->actor_id);
        $this->assertSame(1, $registro->properties['email_logs']);
        $this->assertSame(1, $registro->properties['audit_logs']);
    }

    #[TestDox('Repetir la ejecución no vuelve a procesar lo ya anonimizado (ni lo cuenta otra vez)')]
    public function test_it_is_idempotent(): void
    {
        $this->cerrado(3);
        $this->ejecutar();
        $primera = Ticket::whereNotNull('anonymized_at')->firstOrFail()->anonymized_at;

        $this->travel(1)->days();
        $this->ejecutar();

        $this->assertEquals($primera, Ticket::whereNotNull('anonymized_at')->firstOrFail()->anonymized_at);
        $this->assertSame(0, AuditLog::where('event', 'retention_run')->orderByDesc('id')->firstOrFail()->properties['tickets']);
    }

    #[TestDox('Un ticket cuyo asunto ya coincide con el texto de sustitución se anonimiza igualmente (la marca es la fecha, no el texto)')]
    public function test_marker_is_the_date_not_the_text(): void
    {
        $ticket = $this->cerrado(3, RunRetention::REPLACEMENT_SUBJECT);

        $this->ejecutar();

        $this->assertNotNull($ticket->fresh()->anonymized_at);
        $this->assertSame(0, Attachment::where('ticket_id', $ticket->id)->count());
    }
}
