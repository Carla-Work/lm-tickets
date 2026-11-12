<?php

/*
 * El filtro de entrada del correo entrante (P1; docs/plan/p1-email-entrante.md). Cuándo tocarlo: al cambiar las reglas del filtro.
 * Lo que de verdad vigila:
 *  - un correo solo crea ticket si es de un cliente conocido, pasa SPF/DKIM y parece una incidencia; si no, cuarentena o spam, nunca ticket;
 *  - el DEPARTAMENTO sale del buzón (comercial@ es Contabilidad, R13) y el producto solo si es único y de la empresa del cliente (R6/R7);
 *  - un dominio registrado es solo una PISTA: nunca crea cuenta ni ticket (R18);
 *  - una respuesta entra en SU hilo solo si la manda el solicitante; con el ticket final, rebote y nada se añade (R4/R15);
 *  - un correo se procesa UNA vez; el correo de compañeros y las autorrespuestas se ignoran.
 * Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Inbound;

use App\Actions\Inbound\ProcessIncomingEmail;
use App\Enums\TicketChannel;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Mail\ClosedTicketBounceMail;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\InboundEmail;
use App\Models\IncidentWord;
use App\Models\Product;
use App\Models\ProductKeyword;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\BuildsIncomingEmails;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class ProcessIncomingEmailTest extends TestCase
{
    use BuildsIncomingEmails, CreatesActors, RefreshDatabase;

    private Product $onecop;

    private Product $onefact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
        Mail::fake();
        Notification::fake();
        Storage::fake(Attachment::DISK);
        config(['inbound.require_auth' => true]);

        $this->onecop = Product::factory()->create(['name' => 'oneCOP']);
        $this->onefact = Product::factory()->create(['name' => 'oneFact']);
        $this->empresaA->products()->attach([$this->onecop->id, $this->onefact->id]);
        (new ProductKeyword)->forceFill(['product_id' => $this->onecop->id, 'keyword' => 'fichaje'])->save();
        (new ProductKeyword)->forceFill(['product_id' => $this->onefact->id, 'keyword' => 'factura'])->save();
        IncidentWord::create(['word' => 'error']);
        IncidentWord::create(['word' => 'no funciona']);
    }

    private function procesar(array $mail, string $buzon = 'apps'): InboundEmail
    {
        return app(ProcessIncomingEmail::class)($this->incoming($mail), $this->mailbox($buzon));
    }

    private function emailDe(string $quien): string
    {
        return $this->a[$quien]->email;
    }

    // ---------------------------------------------------------------- ticket nuevo

    #[TestDox('Un cliente conocido escribe algo que parece una incidencia: nace un ticket Abierto a su nombre, con canal Correo, departamento del buzón, categoría «Sin clasificar» y el producto por palabra clave')]
    public function test_known_client_incident_creates_a_ticket(): void
    {
        $in = $this->procesar(['from' => $this->emailDe('cuA'), 'subject' => 'RE: Error en el fichaje', 'text' => 'No puedo hacer el fichaje desde el móvil']);

        $ticket = Ticket::query()->sole();
        $this->assertSame(InboundEmail::TICKET, $in->status);
        $this->assertSame($ticket->id, $in->ticket_id);
        $this->assertNull($in->body, 'al convertirse en ticket, el texto vive en el ticket');
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame(TicketChannel::Email, $ticket->channel);
        $this->assertSame('web_apps', $ticket->department->value, 'el departamento sale del buzón aplicaciones@');
        $this->assertSame($this->a['cuA']->id, $ticket->requester_id);
        $this->assertSame($this->a['cuA']->id, $ticket->created_by_id);
        $this->assertSame($this->empresaA->id, $ticket->company_id);
        $this->assertSame('Sin clasificar', $ticket->category->name);
        $this->assertSame($this->onecop->id, $ticket->product_id, '«fichaje» → oneCOP');
        $this->assertSame('Error en el fichaje', $ticket->subject, 'se quita el «RE:»');
        $this->assertNull($ticket->assigned_to);

        $message = $ticket->messages()->sole();
        $this->assertTrue($message->via_email);
        $this->assertSame($in->message_id, $message->email_message_id);
        $this->assertSame($this->a['cuA']->id, $message->author_id);
        Notification::assertSentTo($this->a['cuA'], TicketNotice::class, fn (TicketNotice $n) => str_contains($n->title, 'Hemos recibido'));
        Notification::assertSentTo($this->a['agApp'], TicketNotice::class, fn (TicketNotice $n) => str_contains($n->title, 'sin asignar'));
    }

    #[TestDox('Si el correo iba (Para/CC) a un compañero que puede atender el departamento, el ticket nace asignado a él con su evento interno; a quien no puede atenderlo o al administrador, no')]
    public function test_named_colleague_gets_the_ticket(): void
    {
        $this->procesar(['from' => $this->emailDe('cuA'), 'to' => ['aplicaciones@lingmarco.es', $this->emailDe('agCon'), $this->emailDe('admin'), $this->emailDe('agSup')]]);

        $ticket = Ticket::query()->sole();
        $this->assertSame($this->a['agSup']->id, $ticket->assigned_to, 'agCon (Contabilidad) y el administrador no atienden Aplicaciones; agSup sí (R11)');
        $evento = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', TicketEventType::Assigned->value)->sole();
        $this->assertFalse($evento->visible_to_customer);
        $this->assertSame('email_recipient', $evento->data['reason']);
        Notification::assertSentTo($this->a['agSup'], TicketNotice::class, fn (TicketNotice $n) => str_contains($n->title, 'por correo'));
    }

    #[TestDox('R13: un correo a comercial@ es de Contabilidad: solo se asigna al equipo de Contabilidad y los demás agentes no lo ven')]
    public function test_accounting_mailbox_is_accounting(): void
    {
        $this->procesar(['from' => $this->emailDe('cuA'), 'to' => ['comercial@lingmarco.es', $this->emailDe('agApp'), $this->emailDe('agCon')]], 'accounting');

        $ticket = Ticket::query()->sole();
        $this->assertSame('other', $ticket->department->value);
        $this->assertSame($this->a['agCon']->id, $ticket->assigned_to);
        $this->assertFalse($this->a['agApp']->can('view', $ticket));
        $this->assertSame(0, Ticket::query()->visibleTo($this->a['agApp'])->count());
    }

    #[TestDox('R6/R7: el producto solo se asigna si UNA palabra coincide con UN producto de la empresa del cliente; con varios, o de un producto que la empresa no tiene, queda sin producto')]
    public function test_product_only_when_unambiguous_and_the_companys(): void
    {
        $this->procesar(['from' => $this->emailDe('cuA'), 'subject' => 'Error', 'text' => 'Fichaje y factura fallan']);
        $this->assertNull(Ticket::query()->latest('id')->first()->product_id, 'dos productos coinciden');

        $ajeno = Product::factory()->create(['name' => 'oneCAR']);
        (new ProductKeyword)->forceFill(['product_id' => $ajeno->id, 'keyword' => 'flota'])->save();
        $this->procesar(['from' => $this->emailDe('cuA'), 'subject' => 'Error', 'text' => 'Error en la flota']);
        $this->assertNull(Ticket::query()->latest('id')->first()->product_id, 'la empresa A no tiene oneCAR');
    }

    #[TestDox('Un cliente conocido que NO escribe una incidencia ni responde a un ticket («Gracias»): no hay ticket, va a pendientes con el texto guardado')]
    public function test_known_client_not_an_incident_goes_to_pending(): void
    {
        $in = $this->procesar(['from' => $this->emailDe('cuA'), 'subject' => 'Gracias', 'text' => 'Muchas gracias por todo']);

        $this->assertSame(InboundEmail::PENDING, $in->status);
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame('Muchas gracias por todo', $in->body);
        $this->assertStringContainsString('No parece una incidencia', implode(' ', $in->reasons));
    }

    #[TestDox('El «De» se falsifica: un correo de un cliente conocido que NO pasa SPF/DKIM no crea ticket, va a pendientes marcado como posible suplantación')]
    public function test_failed_authentication_never_creates_a_ticket(): void
    {
        foreach ([null, 'mx.ionos.es; spf=fail; dkim=none', 'mx; spf=pass; dmarc=fail'] as $auth) {
            $in = $this->procesar(['from' => $this->emailDe('cuA'), 'auth' => $auth]);
            $this->assertSame(InboundEmail::PENDING, $in->status);
            $this->assertStringContainsString('suplantación', implode(' ', $in->reasons));
        }
        $this->assertSame(0, Ticket::query()->count());

        config(['inbound.require_auth' => false]);
        $this->procesar(['from' => $this->emailDe('cuA'), 'auth' => null]);
        $this->assertSame(1, Ticket::query()->count(), 'solo con la comprobación desactivada (pruebas)');
    }

    // ---------------------------------------------------------------- remitente desconocido

    #[TestDox('R18: el dominio de una empresa solo es una PISTA: remitente sin cuenta con ese dominio va a pendientes con la empresa sugerida, y NO se crea ni cuenta ni ticket')]
    public function test_registered_domain_is_only_a_clue(): void
    {
        $this->empresaA->domains()->create(['domain' => 'empresa-a.test']);
        $usuarios = User::query()->count();

        $in = $this->procesar(['from' => 'nuevo@empresa-a.test', 'subject' => 'Hola', 'text' => 'Quiero hablar con vosotros']);

        $this->assertSame(InboundEmail::PENDING, $in->status);
        $this->assertStringContainsString('Dominio de Empresa A', implode(' ', $in->reasons));
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame($usuarios, User::query()->count());
    }

    #[TestDox('Un remitente desconocido con palabra clave de producto o de incidencia va a pendientes; sin ninguna pista, a posible spam')]
    public function test_unknown_sender_clues_decide_pending_or_spam(): void
    {
        $this->assertSame(InboundEmail::PENDING, $this->procesar(['from' => 'x@otra.test', 'subject' => 'Hola', 'text' => 'Problema con el fichaje'])->status);
        $this->assertSame(InboundEmail::PENDING, $this->procesar(['from' => 'y@otra.test', 'subject' => 'Hola', 'text' => 'Esto no funciona'])->status);
        $spam = $this->procesar(['from' => 'z@spam.test', 'subject' => 'Gana dinero', 'text' => 'Compra ahora']);
        $this->assertSame(InboundEmail::SPAM, $spam->status);
        $this->assertSame(0, Ticket::query()->count());
    }

    #[TestDox('Un cliente DE BAJA (o de empresa de baja) no es un cliente conocido: no crea ticket aunque pase todo lo demás')]
    public function test_deactivated_accounts_do_not_create_tickets(): void
    {
        $baja = User::factory()->forCompany($this->empresaA)->deactivated()->create();
        $empresaBaja = Company::factory()->deactivated()->create();
        $deEmpresaBaja = User::factory()->forCompany($empresaBaja)->create();

        foreach ([$baja, $deEmpresaBaja] as $user) {
            $this->assertNotSame(InboundEmail::TICKET, $this->procesar(['from' => $user->email])->status);
        }
        $this->assertSame(0, Ticket::query()->count());
    }

    #[TestDox('Se ignoran sin crear nada: los correos del propio equipo, las autorrespuestas/listas y los rebotes')]
    public function test_internal_and_automatic_mail_is_ignored(): void
    {
        foreach ([['from' => 'compañero@lingmarco.es'], ['from' => $this->emailDe('cuA'), 'automatic' => true], ['from' => 'mailer-daemon@x.test', 'automatic' => true]] as $mail) {
            $this->assertSame(InboundEmail::IGNORED, $this->procesar($mail)->status);
        }
        $this->assertSame(0, Ticket::query()->count());
    }

    #[TestDox('Un correo se procesa UNA vez: el mismo Message-ID no duplica ni el ticket ni la fila')]
    public function test_it_is_idempotent(): void
    {
        $mail = ['from' => $this->emailDe('cuA'), 'messageId' => 'repetido@cliente.es'];
        $a = $this->procesar($mail);
        $b = $this->procesar($mail);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(1, InboundEmail::query()->count());
    }

    // ---------------------------------------------------------------- hilo

    private function ticketPorCorreo(): array
    {
        $this->procesar(['from' => $this->emailDe('cuA'), 'messageId' => 'primero@cliente.es']);
        $ticket = Ticket::query()->sole();
        // Una respuesta del equipo por correo, con su Message-ID (así lo enlaza TicketMailer).
        $respuesta = TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->a['agApp']->id, 'body' => 'Ya lo miramos']);
        $respuesta->forceFill(['email_message_id' => 'tk-nuestro@lingmarco.es'])->save();

        return [$ticket, $respuesta];
    }

    #[TestDox('La respuesta del solicitante a un correo nuestro entra en SU hilo (por In-Reply-To), aunque sea corta; R14: si esperaba al cliente pasa a En curso; el texto citado se quita')]
    public function test_a_reply_joins_its_thread(): void
    {
        [$ticket] = $this->ticketPorCorreo();
        $ticket->forceFill(['status' => TicketStatus::PendingCustomer->value])->save();

        $in = $this->procesar(['from' => $this->emailDe('cuA'), 'inReplyTo' => 'tk-nuestro@lingmarco.es', 'subject' => 'Re: Error', 'text' => "Gracias\n\nEl 12 nov 2026, Francisco escribió:\n> Ya lo miramos"]);

        $this->assertSame(InboundEmail::REPLY, $in->status);
        $this->assertSame(1, Ticket::query()->count(), 'no se crea otro ticket');
        $mensaje = $ticket->messages()->latest('id')->first();
        $this->assertSame('Gracias', $mensaje->body);
        $this->assertTrue($mensaje->via_email);
        $this->assertSame($this->a['cuA']->id, $mensaje->author_id);
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status, 'R14');
    }

    #[TestDox('Una respuesta a un hilo de quien NO es el solicitante (o que no pasa SPF/DKIM) no se añade: cuarentena')]
    public function test_only_the_requester_can_reply_into_a_thread(): void
    {
        [$ticket] = $this->ticketPorCorreo();
        $antes = $ticket->messages()->count();

        $this->assertSame(InboundEmail::PENDING, $this->procesar(['from' => $this->emailDe('cuA2'), 'inReplyTo' => 'tk-nuestro@lingmarco.es'])->status);
        $this->assertSame(InboundEmail::PENDING, $this->procesar(['from' => $this->emailDe('caA'), 'inReplyTo' => 'tk-nuestro@lingmarco.es'])->status, 'ni el administrador de su empresa');
        $this->assertSame(InboundEmail::PENDING, $this->procesar(['from' => $this->emailDe('cuA'), 'inReplyTo' => 'tk-nuestro@lingmarco.es', 'auth' => null])->status);
        $this->assertSame($antes, $ticket->messages()->count());
        Mail::assertNothingQueued();
    }

    #[TestDox('R4/R15: responder por correo a un ticket resuelto o cerrado NO añade nada: rebote automático al solicitante, solo uno por ticket y día')]
    public function test_reply_to_a_final_ticket_bounces_once(): void
    {
        [$ticket] = $this->ticketPorCorreo();
        $ticket->forceFill(['status' => TicketStatus::Closed->value])->save();
        $antes = $ticket->messages()->count();

        $in = $this->procesar(['from' => $this->emailDe('cuA'), 'inReplyTo' => 'tk-nuestro@lingmarco.es']);
        $this->assertSame(InboundEmail::REJECTED, $in->status);
        $this->procesar(['from' => $this->emailDe('cuA'), 'inReplyTo' => 'tk-nuestro@lingmarco.es']);

        $this->assertSame($antes, $ticket->messages()->count());
        Mail::assertQueued(ClosedTicketBounceMail::class, 1);
        Mail::assertQueued(ClosedTicketBounceMail::class, fn ($m) => $m->hasTo($this->emailDe('cuA')));
    }

    #[TestDox('Un rebote NUNCA se manda a una dirección falsificada: si no pasa SPF/DKIM, cuarentena y ningún correo')]
    public function test_no_bounce_to_a_spoofed_sender(): void
    {
        [$ticket] = $this->ticketPorCorreo();
        $ticket->forceFill(['status' => TicketStatus::Closed->value])->save();

        $this->procesar(['from' => $this->emailDe('cuA'), 'inReplyTo' => 'tk-nuestro@lingmarco.es', 'auth' => null]);

        Mail::assertNothingQueued();
    }

    // ---------------------------------------------------------------- adjuntos

    #[TestDox('Los adjuntos pasan por la lista cerrada de siempre: una captura entra al ticket; un .php se descarta y se anota en el texto, y el correo se procesa igual')]
    public function test_attachments_use_the_closed_list(): void
    {
        $this->procesar(['from' => $this->emailDe('cuA'), 'attachments' => [
            $this->incomingAttachment('captura.png', $this->png()),
            $this->incomingAttachment('malo.php', '<?php echo 1;'),
        ]]);

        $ticket = Ticket::query()->sole();
        $this->assertSame(['captura.png'], Attachment::query()->pluck('original_name')->all());
        $this->assertStringContainsString('Adjunto descartado: «malo.php»', $ticket->messages()->sole()->body);
    }

    #[TestDox('En cuarentena los adjuntos válidos se guardan en el disco PRIVADO (R3) hasta que el administrador decide')]
    public function test_quarantined_attachments_are_stored_privately(): void
    {
        $in = $this->procesar(['from' => 'x@otra.test', 'text' => 'Problema con el fichaje', 'attachments' => [$this->incomingAttachment('captura.png', $this->png())]]);

        $adjunto = $in->attachments()->sole();
        Storage::disk(Attachment::DISK)->assertExists($adjunto->path);
        $this->assertStringStartsWith("inbound/{$in->id}/", $adjunto->path);
        $this->assertSame('image/png', $adjunto->mime_type);
    }

    #[TestDox('El dominio de una empresa DE BAJA no es una pista: sin otras pistas, posible spam')]
    public function test_domain_of_a_deactivated_company_is_no_clue(): void
    {
        $baja = Company::factory()->deactivated()->create();
        $baja->domains()->create(['domain' => 'baja.test']);

        $this->assertSame(InboundEmail::SPAM, $this->procesar(['from' => 'x@baja.test', 'subject' => 'Hola', 'text' => 'Buenas'])->status);
    }
}
