<?php

/*
 * La respuesta del equipo POR CORREO (P1; decidido por la usuaria el 2026-11-12: el cliente no entra al panel). Cuándo tocarlo: al cambiar qué sale por correo.
 * Lo que de verdad vigila:
 *  - solo sale una respuesta PÚBLICA de una persona del EQUIPO con la casilla marcada; una nota interna NUNCA (R2) y un cliente no puede activarlo;
 *  - va SOLO al solicitante (no a compañeros ni al administrador de su empresa), desde la dirección del buzón del departamento;
 *  - lleva Message-ID e In-Reply-To/References: así la respuesta del cliente vuelve a entrar en su hilo;
 *  - sin casilla no sale correo (solo la campana); el tope de adjuntos del correo se respeta; el registro de correos NO guarda el texto.
 * Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Inbound;

use App\Enums\Department;
use App\Enums\MessageKind;
use App\Enums\TicketStatus;
use App\Mail\TicketReplyMail;
use App\Models\Attachment;
use App\Models\EmailLog;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Notifications\TicketNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\BuildsIncomingEmails;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class OutgoingReplyTest extends TestCase
{
    use BuildsIncomingEmails, CreatesActors, RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
        Storage::fake(Attachment::DISK);
        $this->ticket = $this->ticket(Department::WebApps, TicketStatus::InProgress, 'agApp');
        $this->ticket->forceFill(['subject' => 'Error al fichar'])->save();
    }

    private function como(string $quien): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->a[$quien]);
    }

    private function responder(string $quien, array $datos = []): TestResponse
    {
        return $this->como($quien)->post(route('tickets.messages.store', $this->ticket), array_merge(['mensaje' => 'Hola, ya está arreglado.'], $datos));
    }

    private function archivo(string $nombre, int $bytes): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'lmo').'.txt';
        file_put_contents($ruta, str_repeat('a', $bytes));

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    #[TestDox('Con la casilla marcada, el agente envía al SOLICITANTE (y a nadie más) un correo con el texto, desde el buzón del departamento, con su Message-ID y el asunto con el número')]
    public function test_the_reply_is_emailed_to_the_requester_only(): void
    {
        Mail::fake();
        Notification::fake();
        // Un compañero de la misma empresa (administrador de empresa) NO debe recibirlo.
        $this->responder('agApp', ['enviar_correo' => '1'])->assertSessionHasNoErrors();

        Mail::assertQueued(TicketReplyMail::class, 1);
        Mail::assertQueued(TicketReplyMail::class, function (TicketReplyMail $m) {
            return $m->hasTo($this->a['cuA']->email) && ! $m->hasTo($this->a['caA']->email)
                && $m->fromAddress === 'aplicaciones@lingmarco.es'
                && $m->text === 'Hola, ya está arreglado.'
                && str_contains($m->subjectLine, '[#'.$this->ticket->number.']')
                && str_starts_with($m->messageId, 'tk-'.$this->ticket->id.'-');
        });
        $mensaje = TicketMessage::query()->where('ticket_id', $this->ticket->id)->latest('id')->first();
        $this->assertNotNull($mensaje->emailed_at);
        $this->assertNotNull($mensaje->email_message_id);
        // Solo la campana: el cliente no recibe además el correo genérico «ha respondido».
        Notification::assertSentTo($this->a['cuA'], TicketNotice::class, fn (TicketNotice $n) => $n->via($this->a['cuA']) === ['database']);
    }

    #[TestDox('El equipo ve en el hilo que la respuesta salió por correo; el cliente NO ve esa marca (ni el canal)')]
    public function test_the_thread_mark_is_internal(): void
    {
        Mail::fake();
        $this->responder('agApp', ['enviar_correo' => '1']);

        $this->como('agApp')->get(route('tickets.show', $this->ticket))->assertOk()->assertSee('Enviado también por correo a '.$this->a['cuA']->email);
        $this->como('cuA')->get(route('tickets.show', $this->ticket))->assertOk()->assertDontSee('Enviado también por correo');
    }

    #[TestDox('Sin la casilla no sale ningún correo: solo queda en el ticket y en la campana del cliente')]
    public function test_without_the_checkbox_nothing_is_emailed(): void
    {
        Mail::fake();
        Notification::fake();

        $this->responder('agApp')->assertSessionHasNoErrors();

        Mail::assertNothingQueued();
        $this->assertNull(TicketMessage::query()->latest('id')->first()->emailed_at);
        Notification::assertSentTo($this->a['cuA'], TicketNotice::class, fn (TicketNotice $n) => $n->via($this->a['cuA']) === ['database']);
    }

    #[TestDox('R2: una NOTA INTERNA nunca sale por correo, aunque se mande la casilla; y un cliente no puede activar el envío')]
    public function test_notes_and_clients_never_trigger_an_email(): void
    {
        Mail::fake();
        Notification::fake();

        $this->como('agApp')->post(route('tickets.notes.store', $this->ticket), ['mensaje' => 'NOTA-SECRETA', 'enviar_correo' => '1'])->assertSessionHasNoErrors();
        $this->como('cuA')->post(route('tickets.messages.store', $this->ticket), ['mensaje' => 'Gracias', 'enviar_correo' => '1'])->assertSessionHasNoErrors();

        Mail::assertNothingQueued();
        $this->assertSame(MessageKind::Internal, TicketMessage::query()->where('body', 'NOTA-SECRETA')->sole()->kind);
    }

    #[TestDox('El hilo: el correo lleva In-Reply-To y References con los Message-ID anteriores (el del cliente y los nuestros), para que su respuesta vuelva a entrar')]
    public function test_the_email_carries_thread_headers(): void
    {
        Mail::fake();
        $primero = TicketMessage::factory()->create(['ticket_id' => $this->ticket->id, 'author_id' => $this->a['cuA']->id, 'body' => 'No puedo fichar']);
        $primero->forceFill(['email_message_id' => 'cliente-1@cliente.es', 'via_email' => true])->save();

        $this->responder('agApp', ['enviar_correo' => '1']);

        Mail::assertQueued(TicketReplyMail::class, function (TicketReplyMail $m) {
            return $m->inReplyTo === 'cliente-1@cliente.es' && $m->references === ['cliente-1@cliente.es']
                && $m->quote !== null && $m->quote['text'] === 'No puedo fichar';
        });
    }

    #[TestDox('Los adjuntos viajan en el correo; si pasan del tope (10 MB) y la casilla está marcada, el formulario se rechaza; sin casilla se guardan en el ticket')]
    public function test_attachments_and_the_size_cap(): void
    {
        Mail::fake();

        $this->responder('agApp', ['enviar_correo' => '1', 'adjuntos' => [$this->archivo('grande-1.txt', 6 * 1048576), $this->archivo('grande-2.txt', 6 * 1048576)]])->assertSessionHasErrors('adjuntos');
        Mail::assertNothingQueued();
        $this->assertSame(0, Attachment::query()->count());

        $this->responder('agApp', ['adjuntos' => [$this->archivo('grande-1.txt', 6 * 1048576), $this->archivo('grande-2.txt', 6 * 1048576)]])->assertSessionHasNoErrors();
        $this->assertSame(2, Attachment::query()->count(), 'sin casilla, los archivos quedan en el ticket');

        $this->responder('agApp', ['enviar_correo' => '1', 'adjuntos' => [$this->archivo('pequeno.txt', 2048)]])->assertSessionHasNoErrors();
        Mail::assertQueued(TicketReplyMail::class, fn (TicketReplyMail $m) => count($m->files) === 1 && $m->files[0]['name'] === 'pequeno.txt');
    }

    #[TestDox('El registro de correos NO guarda el texto de la respuesta (es dato de cliente) pero sí el asunto y el destinatario; el HTML escapa lo que escribe el agente')]
    public function test_the_mail_log_does_not_store_the_body(): void
    {
        config(['mail.default' => 'array', 'queue.default' => 'sync']);

        $enviado = null;
        Event::listen(MessageSent::class, function (MessageSent $e) use (&$enviado): void {
            $enviado = $e->message;
        });

        $this->responder('agApp', ['enviar_correo' => '1', 'mensaje' => 'Contraseña nueva: SECRETO-123 <script>alert(1)</script>'])->assertSessionHasNoErrors();

        $log = EmailLog::query()->where('subject', 'like', 'Re: Error al fichar%')->sole();
        $this->assertNull($log->body, 'sin cuerpo en el registro');
        $this->assertSame(EmailLog::SENT, $log->status);
        $this->assertStringContainsString($this->a['cuA']->email, json_encode($log->to_emails));

        $this->assertNotNull($enviado, 'el correo se envió');
        $mensaje = $enviado;
        $html = (string) $mensaje->getHtmlBody();
        $this->assertStringContainsString('SECRETO-123', $html, 'el correo sí lleva el texto');
        $this->assertStringNotContainsString('<script>', $html, 'escapado');
        $this->assertTrue($mensaje->getHeaders()->has('Message-ID'));
        $this->assertStringContainsString('['.'#'.$this->ticket->number.']', (string) $mensaje->getSubject());
    }

    #[TestDox('Un agente que no puede responder (no es quien lo lleva) no envía nada por correo')]
    public function test_a_forbidden_agent_sends_nothing(): void
    {
        Mail::fake();

        $this->responder('agSup', ['enviar_correo' => '1'])->assertForbidden();

        Mail::assertNothingQueued();
    }

    #[TestDox('Una respuesta cuyo correo no se puede encolar no deshace la respuesta: queda guardada en el ticket')]
    public function test_a_mail_failure_does_not_undo_the_reply(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Cola caída'));
        $this->withoutExceptionHandling();

        $this->responder('agApp', ['enviar_correo' => '1']);

        $this->assertSame(1, TicketMessage::query()->where('ticket_id', $this->ticket->id)->where('body', 'Hola, ya está arreglado.')->count());
    }
}
