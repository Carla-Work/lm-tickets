<?php

/*
 * El cliente responde y marca como resuelto. Reglas: R4 (resuelto/cerrado no admite mensajes del cliente),
 * R14 (responder en «Pendiente de cliente» pasa solo a «En curso»), R16 (resolver exige explicar cómo),
 * R1 (solo quien abrió el ticket escribe), R5 (cada cambio deja evento), R2 (el texto no se pinta crudo).
 * Cuándo tocarlo: al cambiar esas reglas. NO se adapta un test para que pase.
 */

namespace Tests\Feature\Tickets;

use App\Actions\Tickets\ReplyToTicket;
use App\Enums\Department;
use App\Enums\MessageKind;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class ClientRepliesTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    private function responder(string $quien, Ticket $ticket, string $texto = 'Hola, sigo con el problema')
    {
        return $this->actingAs($this->a[$quien])->post(route('tickets.messages.store', $ticket), ['mensaje' => $texto]);
    }

    private function resolver(string $quien, Ticket $ticket, ?string $solucion = 'Ya funciona tras reiniciar')
    {
        return $this->actingAs($this->a[$quien])->post(route('tickets.resolve', $ticket), ['solucion' => $solucion]);
    }

    // ---------- responder ----------

    #[TestDox('El cliente que abrió el ticket responde: queda el mensaje público y sube la última actividad')]
    public function test_requester_can_reply(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress);
        $ticket->forceFill(['last_activity_at' => now()->subDay()])->save();

        $this->responder('cuA', $ticket)->assertRedirect(route('tickets.show', $ticket));

        $mensaje = TicketMessage::query()->where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('Hola, sigo con el problema', $mensaje->body);
        $this->assertSame(MessageKind::Public, $mensaje->kind);
        $this->assertSame($this->a['cuA']->id, $mensaje->author_id);
        $this->assertTrue($ticket->fresh()->last_activity_at->isToday());
    }

    #[TestDox('R14: si el cliente responde en «Pendiente de cliente», el estado pasa solo a «En curso» y queda el evento')]
    public function test_reply_moves_pending_customer_to_in_progress(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::PendingCustomer, 'agSup');

        $this->responder('cuA', $ticket);

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $evento = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', TicketEventType::StatusChanged->value)->firstOrFail();
        $this->assertSame('pending_customer', $evento->data['from']);
        $this->assertSame('in_progress', $evento->data['to']);
        $this->assertTrue($evento->visible_to_customer);
        $this->assertSame($this->a['cuA']->id, $evento->actor_id);
    }

    #[TestDox('R14: responder en cualquier otro estado NO cambia el estado')]
    public function test_reply_in_other_states_keeps_status(): void
    {
        foreach ([TicketStatus::Open, TicketStatus::InProgress] as $estado) {
            $ticket = $this->ticket(Department::Support, $estado);

            $this->responder('cuA', $ticket);

            $this->assertSame($estado, $ticket->fresh()->status);
            $this->assertSame(0, TicketEvent::query()->where('ticket_id', $ticket->id)->count());
        }
    }

    #[TestDox('R4: en un ticket resuelto o cerrado el cliente no puede escribir (403) y no queda ningún mensaje')]
    public function test_cannot_reply_in_final_tickets(): void
    {
        foreach ([TicketStatus::Resolved, TicketStatus::Closed] as $estado) {
            $ticket = $this->ticket(Department::Support, $estado);

            $this->responder('cuA', $ticket)->assertForbidden();

            $this->assertSame(0, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
        }
    }

    #[TestDox('R1: nadie más escribe como cliente: ni otra empresa, ni un compañero, ni el administrador de la empresa')]
    public function test_only_the_requester_can_reply(): void
    {
        $ticket = $this->ticket();

        foreach (['cuB', 'caB', 'cuA2', 'caA'] as $otro) {
            $this->responder($otro, $ticket)->assertForbidden();
        }
        $this->assertSame(0, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
    }

    #[TestDox('Sin sesión no se puede escribir en un ticket')]
    public function test_reply_requires_login(): void
    {
        $ticket = $this->ticket();

        $this->post(route('tickets.messages.store', $ticket), ['mensaje' => 'x'])->assertRedirect(route('login'));
    }

    #[TestDox('Un mensaje vacío o demasiado largo se rechaza')]
    public function test_message_validation(): void
    {
        $ticket = $this->ticket();

        $this->responder('cuA', $ticket, '')->assertSessionHasErrors('mensaje');
        $this->responder('cuA', $ticket, str_repeat('a', 10001))->assertSessionHasErrors('mensaje');
        $this->assertSame(0, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
    }

    #[TestDox('Carrera: si el ticket se resuelve entre que se autoriza y se guarda, el mensaje se rechaza igualmente (R4)')]
    public function test_reply_rechecks_permission_under_lock(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress);
        $obsoleto = Ticket::findOrFail($ticket->id); // lo que "vio" el controlador: aún en curso
        Ticket::query()->whereKey($ticket->id)->update(['status' => TicketStatus::Resolved->value]); // otra persona lo resuelve

        $this->expectException(AuthorizationException::class);
        try {
            app(ReplyToTicket::class)($this->a['cuA'], $obsoleto, 'tarde');
        } finally {
            $this->assertSame(0, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
        }
    }

    // ---------- marcar como resuelto ----------

    #[TestDox('R16: el cliente marca resuelto explicando cómo: se guarda la solución, la fecha y un evento visible')]
    public function test_client_can_mark_resolved_with_explanation(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->resolver('cuA', $ticket)->assertRedirect(route('tickets.show', $ticket));

        $ticket = $ticket->fresh();
        $this->assertSame(TicketStatus::Resolved, $ticket->status);
        $this->assertSame('Ya funciona tras reiniciar', $ticket->resolution);
        $this->assertNotNull($ticket->resolved_at);
        $evento = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', TicketEventType::Resolved->value)->firstOrFail();
        $this->assertSame('Ya funciona tras reiniciar', $evento->data['resolution']);
        $this->assertTrue($evento->visible_to_customer);
    }

    #[TestDox('R16: sin explicación no se resuelve (vacía o solo espacios) y el estado no cambia')]
    public function test_resolving_requires_explanation(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress);

        $this->resolver('cuA', $ticket, '')->assertSessionHasErrors('solucion');
        $this->resolver('cuA', $ticket, "   \n  ")->assertSessionHasErrors('solucion');

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->resolved_at);
    }

    #[TestDox('El estado de destino del cliente lo fija el servidor: enviar «closed» o «open» sigue dejando el ticket en Resuelto')]
    public function test_client_cannot_choose_the_target_status(): void
    {
        foreach (['closed', 'open', 'in_progress'] as $estado) {
            $ticket = $this->ticket(Department::Support, TicketStatus::InProgress);

            $this->actingAs($this->a['cuA'])->post(route('tickets.resolve', $ticket), ['solucion' => 'ok', 'estado' => $estado]);

            $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status, "con estado=$estado");
            $this->assertNull($ticket->fresh()->closed_at);
        }
    }

    #[TestDox('R15: un ticket ya resuelto o cerrado no se puede volver a resolver ni «reabrir» (403)')]
    public function test_cannot_resolve_final_tickets(): void
    {
        foreach ([TicketStatus::Resolved, TicketStatus::Closed] as $estado) {
            $ticket = $this->ticket(Department::Support, $estado);

            $this->resolver('cuA', $ticket)->assertForbidden();

            $this->assertSame($estado, $ticket->fresh()->status);
        }
    }

    #[TestDox('R1: solo quien abrió el ticket lo marca como resuelto (ni otra empresa, ni compañeros)')]
    public function test_only_requester_resolves(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress);

        foreach (['cuB', 'caB', 'cuA2', 'caA'] as $otro) {
            $this->resolver($otro, $ticket)->assertForbidden();
        }
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    // ---------- lo que se ve ----------

    #[TestDox('El detalle ofrece responder y «Marcar como resuelto» mientras se puede, y en un ticket final lo explica sin formularios')]
    public function test_detail_shows_forms_by_policy(): void
    {
        $vivo = $this->ticket(Department::Support, TicketStatus::InProgress);
        $this->actingAs($this->a['cuA'])->get(route('tickets.show', $vivo))->assertOk()
            ->assertSee('Enviar respuesta')->assertSee('Marcar como resuelto');

        $final = $this->ticket(Department::Support, TicketStatus::Resolved);
        $this->actingAs($this->a['cuA'])->get(route('tickets.show', $final))->assertOk()
            ->assertDontSee('Enviar respuesta')->assertDontSee('Marcar como resuelto')->assertSee('Este ticket está resuelto');
    }

    #[TestDox('Un administrador de empresa que lee el ticket de un compañero no ve formularios para escribir')]
    public function test_company_admin_sees_no_forms_on_colleague_ticket(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress);

        $this->actingAs($this->a['caA'])->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee('Enviar respuesta')->assertDontSee('Marcar como resuelto');
    }

    #[TestDox('El texto de la solución y de los mensajes se escapa: un <script> no llega al HTML')]
    public function test_user_text_is_escaped(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress);
        $this->resolver('cuA', $ticket, '<script>alert(1)</script> arreglado');

        $this->actingAs($this->a['cuA'])->get(route('tickets.show', $ticket))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
