<?php

/*
 * Registro de correos: qué se anota de cada envío y cómo se enseña. Cuándo tocarlo: al cambiar MailLogger o
 * la pantalla. Reglas propias: los correos con enlace de un solo uso NO guardan cuerpo; el cuerpo guardado
 * solo se ve dentro de un iframe aislado; un correo que no se confirma queda visible como «sin confirmar».
 */

namespace Tests\Feature\Records;

use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\RecoveryCodeUsedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\TicketNotice;
use Exception;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Queue\Events\JobFailed;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MailLogTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('Un correo enviado queda anotado como «sent» con destinatario, asunto y cuerpo')]
    public function test_sent_mail_is_logged(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $user->notify(new RecoveryCodeUsedNotification(3));

        $log = EmailLog::query()->firstOrFail();
        $this->assertSame(EmailLog::SENT, $log->status);
        $this->assertSame('ana@example.com', $log->to_emails);
        $this->assertSame('Se ha usado un código de recuperación de tu cuenta', $log->subject);
        $this->assertSame(RecoveryCodeUsedNotification::class, $log->kind);
        $this->assertNotNull($log->sent_at);
        $this->assertStringContainsString('3 códigos', (string) $log->body);
    }

    #[TestDox('La invitación se anota pero NO guarda su cuerpo: lleva un enlace de un solo uso')]
    public function test_invitation_body_is_not_stored(): void
    {
        $user = User::factory()->create(['email' => 'nuevo@example.com']);

        $user->notify(new InvitationNotification('TOKEN-SECRETO-123'));

        $log = EmailLog::query()->firstOrFail();
        $this->assertSame(EmailLog::SENT, $log->status);
        $this->assertNull($log->body);
        $this->assertStringNotContainsString('TOKEN-SECRETO-123', json_encode($log->getAttributes()));
    }

    #[TestDox('La recuperación de contraseña se anota pero NO guarda su cuerpo: lleva un enlace de un solo uso')]
    public function test_password_reset_body_is_not_stored(): void
    {
        $user = User::factory()->create(['email' => 'olvido@example.com']);

        $user->notify(new ResetPasswordNotification('TOKEN-RECUPERACION-456'));

        $log = EmailLog::query()->firstOrFail();
        $this->assertSame(EmailLog::SENT, $log->status);
        $this->assertNull($log->body);
        $this->assertStringNotContainsString('TOKEN-RECUPERACION-456', json_encode($log->getAttributes()));
    }

    #[TestDox('Un correo que abre el envío y la cola falla queda «failed» con el motivo; si nadie lo confirma, «pending»')]
    public function test_unconfirmed_and_failed(): void
    {
        $abrir = function (string $asunto): void {
            $mensaje = (new Email)->from('a@example.com')->to('b@example.com')->subject($asunto)->html('<p>Hola</p>');
            event(new MessageSending($mensaje, []));
        };

        $abrir('Se pierde');
        $this->assertSame(EmailLog::PENDING, EmailLog::query()->firstOrFail()->status);

        event(new JobFailed('database', $this->createStub(Job::class), new Exception('SMTP caído')));

        $log = EmailLog::query()->firstOrFail();
        $this->assertSame(EmailLog::FAILED, $log->status);
        $this->assertSame('SMTP caído', $log->error);
    }

    #[TestDox('La pantalla lista los correos y los filtros por estado, tipo y texto funcionan')]
    public function test_screen_filters(): void
    {
        EmailLog::create(['status' => EmailLog::SENT, 'subject' => 'Hemos recibido tu ticket #1', 'to_emails' => 'a@x.com', 'kind' => TicketNotice::class]);
        EmailLog::create(['status' => EmailLog::FAILED, 'subject' => 'Otro asunto', 'to_emails' => 'b@x.com', 'error' => 'boom']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('records.mail'))->assertSee('Hemos recibido tu ticket #1')->assertSee('Otro asunto');
        $this->actingAs($admin)->get(route('records.mail', ['estado' => 'failed']))->assertSee('Otro asunto')->assertDontSee('Hemos recibido');
        $this->actingAs($admin)->get(route('records.mail', ['q' => 'a@x.com']))->assertSee('Hemos recibido')->assertDontSee('Otro asunto');
        $this->actingAs($admin)->get(route('records.mail', ['tipo' => TicketNotice::class]))->assertSee('Hemos recibido')->assertDontSee('Otro asunto');
    }

    #[TestDox('Un valor de filtro inventado se rechaza (no llega a la consulta)')]
    public function test_invalid_filter_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('records.mail', ['estado' => 'x; DROP TABLE']))->assertSessionHasErrors('estado');
    }

    #[TestDox('El cuerpo se ve SOLO en un iframe con sandbox y CSP; un <script> guardado no sale como HTML suelto')]
    public function test_body_is_sandboxed(): void
    {
        $log = EmailLog::create(['status' => EmailLog::SENT, 'subject' => 'X', 'body' => '<p>Hola</p><script>alert(1)</script>']);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('records.mail', ['ver' => $log->id]))->assertOk()->getContent();

        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringContainsString('sandbox=""', $html);
        $this->assertStringContainsString('Content-Security-Policy', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html); // solo escapado dentro de srcdoc
    }

    #[TestDox('El detalle de un correo sin cuerpo guardado lo explica en vez de enseñar un iframe vacío')]
    public function test_detail_without_body(): void
    {
        $log = EmailLog::create(['status' => EmailLog::SENT, 'subject' => 'Invitación', 'kind' => InvitationNotification::class]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('records.mail', ['ver' => $log->id]))
            ->assertSee('no se guarda')->assertDontSee('<iframe', false);
    }
}
