<?php

/*
 * Avisos (campana y correo). Reglas: R2 (una nota interna no avisa a nadie), R12 (el cliente recibe aviso del cambio
 * de departamento), R13/R1 (un aviso no enseña un ticket que la persona ya no puede ver), R14 (el cambio automático de
 * estado no genera aviso propio), nadie recibe aviso de lo que hizo él, y el correo no lleva el contenido del ticket.
 * Usan la tubería real (cola síncrona + correo en memoria), no notificaciones simuladas.
 * Cuándo tocarlo: al cambiar a quién se avisa. NO se adapta un test para que pase.
 */

namespace Tests\Feature\Tickets;

use App\Enums\Department;
use App\Enums\MessageKind;
use App\Enums\Team;
use App\Enums\TicketNoticeKind;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketNotice;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class NoticesTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    /** Correos enviados en esta prueba (transporte en memoria). @return \Illuminate\Support\Collection<int, \Symfony\Component\Mime\RawMessage> */
    private function correos(): Collection
    {
        $transporte = Mail::mailer()->getSymfonyTransport();
        if (! $transporte instanceof ArrayTransport) {
            $this->fail('El correo de las pruebas debe ir al transporte en memoria (phpunit.xml: MAIL_MAILER=array).');
        }

        return $transporte->messages();
    }

    private function as(string $quien): static
    {
        return $this->actingAs($this->a[$quien]);
    }

    /** Tipos de aviso que tiene una persona, en orden de llegada. @return list<string> */
    private function avisos(string $quien): array
    {
        return $this->a[$quien]->fresh()->notifications()->oldest()->get()->pluck('data.kind')->all();
    }

    private function todosSinAvisos(): void
    {
        foreach (array_keys($this->a) as $quien) {
            $this->assertSame([], $this->avisos($quien), "$quien no debería tener avisos");
        }
    }

    private function abrirTicket(string $quien, Department $departamento): Ticket
    {
        $categoria = Category::factory()->forDepartment($departamento)->create();
        $this->as($quien)->post(route('tickets.store'), [
            'department' => $departamento->value, 'category_id' => $categoria->id, 'subject' => 'Asunto de prueba', 'description' => 'CUERPO-PRIVADO-DEL-TICKET',
        ])->assertSessionHasNoErrors();

        return Ticket::query()->latest('id')->firstOrFail();
    }

    // ---------- al abrir un ticket ----------

    #[TestDox('Al abrir un ticket: el solicitante recibe «Hemos recibido…» y el equipo del departamento, «sin asignar»; nadie más')]
    public function test_created_notifies_requester_and_department_team(): void
    {
        $this->abrirTicket('cuA', Department::Support);

        $this->assertSame(['received'], $this->avisos('cuA'));
        $this->assertSame(['new_unassigned'], $this->avisos('agSup'));
        foreach (['agApp', 'admin', 'cuA2', 'caA', 'cuB'] as $nadie) {
            $this->assertSame([], $this->avisos($nadie), "$nadie no debería enterarse");
        }
    }

    #[TestDox('Aplicaciones y Páginas web avisan al equipo Desarrollo y Diseño; «Otros» solo al administrador (R13: ningún agente)')]
    public function test_team_routing_by_department(): void
    {
        $this->abrirTicket('cuA', Department::WebApps);
        $this->abrirTicket('cuA', Department::Websites);
        $this->abrirTicket('cuA', Department::Other);

        $this->assertSame(['new_unassigned', 'new_unassigned'], $this->avisos('agApp'));
        $this->assertSame([], $this->avisos('agSup'));
        $this->assertSame(['new_unassigned'], $this->avisos('admin'));
    }

    #[TestDox('Una cuenta de baja del equipo no recibe avisos')]
    public function test_deactivated_staff_gets_nothing(): void
    {
        $this->a['agSup']->forceFill(['deactivated_at' => now()])->save();

        $this->abrirTicket('cuA', Department::Support);

        $this->assertSame([], $this->avisos('agSup'));
    }

    // ---------- respuestas ----------

    #[TestDox('Cuando el equipo responde, avisa al SOLICITANTE (no a compañeros ni al administrador de su empresa) y no a quien responde')]
    public function test_staff_reply_notifies_requester_only(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->as('agSup')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'Probad ahora'])->assertRedirect();

        $this->assertSame(['replied'], $this->avisos('cuA'));
        foreach (['agSup', 'agApp', 'admin', 'cuA2', 'caA', 'cuB'] as $nadie) {
            $this->assertSame([], $this->avisos($nadie), "$nadie");
        }
    }

    #[TestDox('Cuando el cliente responde, avisa a quien lleva el ticket; si no lo lleva nadie, al equipo del departamento')]
    public function test_client_reply_notifies_handler_or_team(): void
    {
        $llevado = $this->ticket(Department::Support, TicketStatus::InProgress, 'agApp'); // lo lleva alguien del OTRO equipo
        $libre = $this->ticket(Department::Support, TicketStatus::Open);

        $this->as('cuA')->post(route('tickets.messages.store', $llevado), ['mensaje' => 'sigo igual']);
        $this->assertSame(['client_replied'], $this->avisos('agApp'));
        $this->assertSame([], $this->avisos('agSup'));

        $this->as('cuA')->post(route('tickets.messages.store', $libre), ['mensaje' => 'hola']);
        $this->assertSame(['client_replied'], $this->avisos('agSup'));
        $this->assertSame(['client_replied'], $this->avisos('agApp'), 'el de Aplicaciones no recibe el del equipo Soporte');
        $this->assertSame([], $this->avisos('cuA'), 'el cliente no se avisa a sí mismo');
    }

    #[TestDox('R2: una NOTA INTERNA no avisa a nadie, ni por la campana ni por correo')]
    public function test_internal_note_notifies_nobody(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->as('agApp')->post(route('tickets.notes.store', $ticket), ['mensaje' => 'NOTA-RESERVADA']);

        $this->todosSinAvisos();
        $this->assertCount(0, $this->correos());
    }

    // ---------- estado, asignación, departamento ----------

    #[TestDox('El equipo cambia el estado: el solicitante recibe aviso de cada cambio (en curso, pendiente de ti, resuelto, cerrado)')]
    public function test_status_change_notifies_requester(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::Open, 'agSup');

        $this->as('agSup')->post(route('tickets.status.update', $ticket), ['estado' => 'in_progress']);
        $this->as('agSup')->post(route('tickets.status.update', $ticket), ['estado' => 'pending_customer']);
        $this->as('agSup')->post(route('tickets.status.update', $ticket), ['estado' => 'resolved', 'solucion' => 'Hecho']);
        $this->as('agSup')->post(route('tickets.status.update', $ticket), ['estado' => 'closed']);

        $this->assertSame(['status_changed', 'status_changed', 'status_changed', 'status_changed'], $this->avisos('cuA'));
        // Los cuatro llegan en el mismo segundo: no se fía del orden, se comprueba que están TODOS.
        $titulos = implode(' | ', $this->a['cuA']->fresh()->notifications()->get()->pluck('data.title')->all());
        foreach (['está en curso', 'espera tu respuesta', 'está resuelto', 'está cerrado'] as $frase) {
            $this->assertStringContainsString($frase, $titulos);
        }
        $this->assertSame([], $this->avisos('agSup'));
    }

    #[TestDox('R14: el cambio automático de «Pendiente de cliente» a «En curso» al responder el cliente NO genera un aviso de estado')]
    public function test_automatic_status_change_has_no_own_notice(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::PendingCustomer, 'agSup');

        $this->as('cuA')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'ya lo probé']);

        $this->assertSame(['client_replied'], $this->avisos('agSup'));
        $this->assertSame([], $this->avisos('cuA'));
    }

    #[TestDox('Cuando el cliente marca «resuelto», avisa a quien lo lleva para que lo cierre; no se avisa al cliente')]
    public function test_client_resolution_notifies_handler(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->as('cuA')->post(route('tickets.resolve', $ticket), ['solucion' => 'Ya funciona']);

        $this->assertSame(['client_resolved'], $this->avisos('agSup'));
        $this->assertSame([], $this->avisos('cuA'));
    }

    #[TestDox('Asignar avisa solo a quien recibe el ticket: no a quien lo deja, no a quien se lo asigna él mismo, no al quitar la asignación')]
    public function test_assignment_notifies_only_the_receiver(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->as('agSup')->post(route('tickets.assignment.update', $ticket), ['asignado' => $this->a['agApp']->id]);
        $this->assertSame(['assigned'], $this->avisos('agApp'));
        $this->assertSame([], $this->avisos('agSup'));

        $this->as('agApp')->post(route('tickets.assignment.update', $ticket), ['asignado' => $this->a['agApp']->id])->assertSessionHasErrors('asignado');
        $this->as('agApp')->post(route('tickets.assignment.update', $ticket), ['asignado' => 'sin']);
        $this->assertSame(['assigned'], $this->avisos('agApp'), 'quitar la asignación no avisa a nadie');
        $this->assertSame([], $this->avisos('cuA'));
    }

    #[TestDox('R12: el cliente recibe aviso del cambio de departamento («De Soporte a Aplicaciones»)')]
    public function test_department_change_notifies_client(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $categoria = Category::factory()->forDepartment(Department::WebApps)->create();

        $this->as('agSup')->post(route('tickets.department.update', $ticket), ['categoria' => $categoria->id])->assertRedirect();

        $this->assertSame(['department_changed'], $this->avisos('cuA'));
        $this->assertSame('De Soporte a Aplicaciones', $this->a['cuA']->notifications()->firstOrFail()->data['detail']);
        $this->assertSame([], $this->avisos('agApp'), 'sigue asignado a agSup: no es "sin asignar"');
    }

    #[TestDox('Si el ticket queda sin asignar tras el cambio de departamento, el equipo nuevo recibe «sin asignar»; pasar a «Otros» avisa al administrador, no a ningún agente')]
    public function test_department_change_on_unassigned_notifies_new_team(): void
    {
        $libre = $this->ticket(Department::Support, TicketStatus::Open);
        $this->as('agSup')->post(route('tickets.department.update', $libre), ['categoria' => Category::factory()->forDepartment(Department::WebApps)->create()->id]);
        $this->assertSame(['new_unassigned'], $this->avisos('agApp'));

        $llevado = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $this->as('agSup')->post(route('tickets.department.update', $llevado), ['categoria' => Category::factory()->forDepartment(Department::Other)->create()->id]);
        $this->assertSame(['new_unassigned'], $this->avisos('admin'), 'la asignación se vació al pasar a Otros');
        $this->assertSame([], $this->avisos('agSup'), 'quien lo movió no se avisa a sí mismo');
    }

    #[TestDox('Quien actúa no se avisa a sí mismo ni cuando está entre los destinatarios: se asigna el ticket él o lo mueve a su propio equipo')]
    public function test_actor_is_never_notified_even_when_a_recipient(): void
    {
        $libre = $this->ticket(Department::Support, TicketStatus::Open);
        $this->as('agApp')->post(route('tickets.assignment.update', $libre), ['asignado' => $this->a['agApp']->id])->assertRedirect();
        $this->assertSame([], $this->avisos('agApp'), 'asignarse el ticket a uno mismo no avisa a uno mismo');

        // agSup mueve a Soporte un ticket sin asignar: el equipo destino es el SUYO, pero él no se avisa; su compañero sí.
        $otroSoporte = User::factory()->agent(Team::Support)->create(['name' => 'Otro de Soporte']);
        $deApps = $this->ticket(Department::WebApps, TicketStatus::Open);
        $this->as('agSup')->post(route('tickets.department.update', $deApps), ['categoria' => Category::factory()->forDepartment(Department::Support)->create()->id])->assertRedirect();

        $this->assertSame([], $this->avisos('agSup'));
        $this->assertSame(['new_unassigned'], $otroSoporte->fresh()->notifications()->get()->pluck('data.kind')->all());
    }

    #[TestDox('Un solicitante que ya no puede entrar (su empresa o su cuenta de baja) no recibe avisos')]
    public function test_inactive_requester_gets_no_notices(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $this->empresaA->forceFill(['deactivated_at' => now()])->save();

        $this->as('agSup')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'hola'])->assertRedirect();

        $this->assertSame([], $this->avisos('cuA'));
        $this->assertCount(0, $this->correos());
    }

    // ---------- contenido del correo ----------

    #[TestDox('El correo lleva número, asunto y enlace, pero NO el contenido del ticket ni de los mensajes')]
    public function test_mail_has_no_ticket_content(): void
    {
        $ticket = $this->abrirTicket('cuA', Department::Support);
        $this->as('agSup')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'MENSAJE-DEL-AGENTE-PRIVADO']);

        $mensajes = $this->correos();
        $this->assertGreaterThanOrEqual(2, $mensajes->count());
        foreach ($mensajes as $mensaje) {
            $texto = $mensaje->toString();
            $this->assertStringNotContainsString('CUERPO-PRIVADO-DEL-TICKET', $texto);
            $this->assertStringNotContainsString('MENSAJE-DEL-AGENTE-PRIVADO', $texto);
        }
        $this->assertStringContainsString(route('tickets.show', $ticket), $mensajes->last()->toString());
    }

    #[TestDox('Un fallo al avisar (cola o correo caídos) no rompe ni deshace la acción ya guardada')]
    public function test_notification_failure_does_not_break_the_action(): void
    {
        $this->app->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new \RuntimeException('cola caída');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new \RuntimeException('cola caída');
            }
        });

        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $this->as('agSup')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'sigue guardándose'])->assertRedirect(route('tickets.show', $ticket));

        $this->assertSame(1, TicketMessage::query()->where('ticket_id', $ticket->id)->where('kind', MessageKind::Public->value)->count());
    }

    // ---------- la página de avisos ----------

    #[TestDox('La página «Avisos» lista los míos, cuenta los sin leer y no enseña los de otra persona')]
    public function test_notices_page_lists_only_my_notices(): void
    {
        $this->abrirTicket('cuA', Department::Support);

        $this->as('cuA')->get(route('notices.index'))->assertOk()->assertSee('Hemos recibido tu ticket')->assertSee('1 sin leer')->assertDontSee('Ticket nuevo sin asignar');
        $this->as('agSup')->get(route('notices.index'))->assertOk()->assertSee('Ticket nuevo sin asignar')->assertDontSee('Hemos recibido tu ticket');
    }

    #[TestDox('Abrir un aviso lo marca leído y lleva al ticket; el aviso de otra persona da 404')]
    public function test_opening_a_notice_marks_it_read(): void
    {
        $ticket = $this->abrirTicket('cuA', Department::Support);
        $aviso = $this->a['cuA']->notifications()->firstOrFail();

        $this->as('cuB')->get(route('notices.open', $aviso->id))->assertNotFound();
        $this->assertNull($aviso->fresh()->read_at);

        $this->as('cuA')->get(route('notices.open', $aviso->id))->assertRedirect(route('tickets.show', $ticket));
        $this->assertNotNull($aviso->fresh()->read_at);
    }

    #[TestDox('«Marcar todos como leídos» marca los míos y deja intactos los de los demás')]
    public function test_mark_all_read(): void
    {
        $this->abrirTicket('cuA', Department::Support);

        $this->as('agSup')->post(route('notices.read-all'))->assertRedirect(route('notices.index'));

        $this->assertSame(0, $this->a['agSup']->unreadNotifications()->count());
        $this->assertSame(1, $this->a['cuA']->unreadNotifications()->count());
    }

    #[TestDox('Ver el ticket marca como leídos sus avisos (y solo los de ese ticket)')]
    public function test_viewing_a_ticket_marks_its_notices_read(): void
    {
        $uno = $this->abrirTicket('cuA', Department::Support);
        $this->abrirTicket('cuA', Department::Support);

        $this->as('agSup')->get(route('tickets.show', $uno))->assertOk();

        $this->assertSame(1, $this->a['agSup']->unreadNotifications()->count(), 'queda el del otro ticket');
    }

    #[TestDox('R13: si el ticket pasa a «Otros», el aviso antiguo del agente deja de verse ni contarse, y abrirlo no lleva al ticket')]
    public function test_notice_hidden_when_access_is_lost(): void
    {
        $ticket = $this->abrirTicket('cuA', Department::Support);
        $aviso = $this->a['agSup']->notifications()->firstOrFail();
        Ticket::query()->whereKey($ticket->id)->update(['department' => Department::Other->value]);

        $this->as('agSup')->get(route('notices.index'))->assertOk()->assertDontSee('Ticket nuevo sin asignar')->assertDontSee('Asunto de prueba')->assertSee('Nada sin leer');
        $this->as('agSup')->get(route('notices.open', $aviso->id))->assertRedirect(route('notices.index'));
        $this->as('agSup')->get(route('tickets.show', $ticket))->assertForbidden();
    }

    #[TestDox('Las listas marcan lo no leído: «Respuesta del cliente» en la bandeja y «Respuesta nueva» en Mis tickets')]
    public function test_lists_mark_unread(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $this->as('cuA')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'hola']);
        $this->as('agSup')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'respuesta']);

        $this->as('agSup')->get(route('inbox'))->assertOk()->assertSee('Respuesta del cliente')->assertSee('sin-leer');
        $this->as('cuA')->get(route('tickets.index'))->assertOk()->assertSee('Respuesta nueva')->assertSee('sin-leer');

        $this->as('agSup')->get(route('tickets.show', $ticket));
        $this->as('agSup')->get(route('inbox'))->assertDontSee('Respuesta del cliente');
    }

    #[TestDox('La campana de la cabecera muestra cuántos avisos sin leer tiene la persona y desaparece el número al leerlos')]
    public function test_bell_shows_unread_count(): void
    {
        $this->abrirTicket('cuA', Department::Support);
        $this->abrirTicket('cuA', Department::Support);

        $this->as('agSup')->get(route('inbox'))->assertOk()->assertSee('aria-label="Avisos: 2 sin leer"', false)->assertSee('<b aria-hidden="true">2</b>', false)->assertSee('<svg', false);

        $this->as('agSup')->post(route('notices.read-all'));
        $this->as('agSup')->get(route('inbox'))->assertOk()->assertDontSee('sin leer', false)->assertDontSee('<b aria-hidden="true">', false);
        $this->as('cuA')->get(route('tickets.index'))->assertOk()->assertSee('aria-label="Avisos: 2 sin leer"', false);
    }

    #[TestDox('Con más de 9 avisos sin leer la insignia pone «9+» y la campana sigue siendo un icono, no la palabra «Avisos»')]
    public function test_bell_caps_the_badge_and_is_an_icon(): void
    {
        foreach (range(1, 10) as $i) {
            $this->abrirTicket('cuA', Department::Support);
        }

        $html = $this->as('agSup')->get(route('inbox'))->assertOk()->assertSee('aria-label="Avisos: 10 sin leer"', false)->assertSee('<b aria-hidden="true">9+</b>', false)->getContent();

        $this->assertMatchesRegularExpression('/<a class="campana"[^>]*>\s*<svg/s', $html, 'la campana empieza por el icono');
        $this->assertDoesNotMatchRegularExpression('/<a class="campana"[^>]*>\s*Avisos/s', $html, 'no debe mostrarse como texto');
    }

    #[TestDox('R13: la campana no cuenta los avisos de tickets que la persona ya no puede ver')]
    public function test_bell_does_not_count_inaccessible_tickets(): void
    {
        $ticket = $this->abrirTicket('cuA', Department::Support);
        Ticket::query()->whereKey($ticket->id)->update(['department' => Department::Other->value]);

        $this->as('agSup')->get(route('inbox'))->assertOk()->assertDontSee('sin leer', false);
    }

    #[TestDox('Sin sesión no se ven los avisos')]
    public function test_notices_require_login(): void
    {
        $this->get(route('notices.index'))->assertRedirect(route('login'));
    }

    // ---------- la notificación en sí ----------

    #[TestDox('El correo lleva el aviso a la persona correcta y el texto sale en español con enlace al ticket')]
    public function test_mail_message_shape(): void
    {
        $aviso = new TicketNotice(TicketNoticeKind::Replied, 7, 'Laura ha respondido en #2026-0007', 'Asunto');

        $correo = $aviso->toMail(User::factory()->create(['name' => 'María']));

        $this->assertSame('[Ling Marco Tickets] Laura ha respondido en #2026-0007', $correo->subject);
        $this->assertSame(route('tickets.show', 7), $correo->actionUrl);
        $this->assertSame('Hola, María', $correo->greeting);
    }
}
