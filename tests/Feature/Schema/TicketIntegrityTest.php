<?php

/*
 * Integridad de los tickets: numeración, estados válidos, hilo e historial.
 * Cuándo tocarlo: si cambia la estructura del ticket. Reglas: R2 (notas internas), R5 (historial).
 */

namespace Tests\Feature\Schema;

use App\Enums\Department;
use App\Enums\MessageKind;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class TicketIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // se restaura el reloj: no debe contaminar otros tests
        parent::tearDown();
    }

    #[TestDox('Los tickets del mismo año se numeran 1, 2, 3… y se muestran como 2026-0001')]
    public function test_numbering_is_sequential_within_a_year(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');

        $primero = Ticket::factory()->create();
        $segundo = Ticket::factory()->create();

        $this->assertSame(1, $primero->sequence);
        $this->assertSame(2, $segundo->sequence);
        $this->assertSame('2026-0001', $primero->number);
        $this->assertSame('2026-0002', $segundo->number);
    }

    #[TestDox('Al cambiar de año la numeración vuelve a empezar')]
    public function test_numbering_restarts_each_year(): void
    {
        Carbon::setTestNow('2026-12-31 23:00:00');
        Ticket::factory()->create();

        Carbon::setTestNow('2027-01-01 09:00:00');
        $nuevoAno = Ticket::factory()->create();

        $this->assertSame('2027-0001', $nuevoAno->number);
    }

    #[TestDox('Dos tickets no pueden tener el mismo año y secuencia')]
    public function test_year_and_sequence_are_unique(): void
    {
        $ticket = Ticket::factory()->create();

        $this->expectException(QueryException::class);
        Ticket::factory()->create(['year' => $ticket->year, 'sequence' => $ticket->sequence]);
    }

    #[TestDox('Un ticket nuevo nace Abierto y sin asignar')]
    public function test_new_ticket_defaults(): void
    {
        $ticket = Ticket::factory()->create()->fresh();

        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertNull($ticket->assigned_to);
        // Se lee la columna tal cual está en la base (el valor por defecto lo pone la propia base de datos).
        $actividad = DB::table('tickets')->where('id', $ticket->id)->value('last_activity_at');
        $this->assertEqualsWithDelta(now()->timestamp, Carbon::parse($actividad)->timestamp, 5, 'la actividad arranca ahora');
    }

    #[TestDox('Un estado inventado no se puede guardar')]
    public function test_invalid_status_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('tickets')->where('id', $ticket->id)->update(['status' => 'reabierto']); // nadie reabre
    }

    #[TestDox('Un departamento inventado no se puede guardar')]
    public function test_invalid_department_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('tickets')->where('id', $ticket->id)->update(['department' => 'comercial']);
    }

    #[TestDox('Un tipo de mensaje inventado no se puede guardar')]
    public function test_invalid_message_kind_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('ticket_messages')->insert([
            'ticket_id' => $ticket->id, 'author_id' => $ticket->requester_id, 'kind' => 'privado',
            'body' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[TestDox('El ámbito «público» devuelve solo los mensajes públicos y nunca las notas internas (R2)')]
    public function test_public_scope_excludes_internal_notes(): void
    {
        $ticket = Ticket::factory()->create();
        TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $ticket->requester_id]);
        TicketMessage::factory()->internal()->create(['ticket_id' => $ticket->id, 'body' => 'NOTA SECRETA']);

        $visibles = $ticket->messages()->public()->get();

        $this->assertCount(1, $visibles);
        $this->assertSame(MessageKind::Public, $visibles->first()->kind);
        $this->assertFalse($visibles->contains(fn (TicketMessage $m) => $m->body === 'NOTA SECRETA'));
        $this->assertCount(2, $ticket->messages, 'sin el ámbito, el hilo completo sí trae la nota (por eso el ámbito es obligatorio)');
    }

    #[TestDox('El historial solo se añade: un evento no tiene fecha de modificación (R5)')]
    public function test_events_are_append_only(): void
    {
        $ticket = Ticket::factory()->create();

        $evento = $ticket->events()->forceCreate([
            'type' => TicketEventType::Created->value,
            'actor_id' => $ticket->requester_id,
            'data' => ['departamento' => 'support'],
            'visible_to_customer' => true,
        ])->fresh();

        $this->assertEqualsWithDelta(now()->timestamp, $evento->created_at->timestamp, 5, 'el evento se fecha al crearse');
        $this->assertArrayNotHasKey('updated_at', $evento->getAttributes());
        $this->assertSame(['departamento' => 'support'], $evento->data);
        $this->assertTrue($evento->visible_to_customer);
    }

    #[TestDox('Un ticket puede enlazarse con el ticket del que se clonó')]
    public function test_related_ticket_link(): void
    {
        $original = Ticket::factory()->status(TicketStatus::Resolved)->create();
        $nuevo = Ticket::factory()->create([
            'company_id' => $original->company_id,
            'requester_id' => $original->requester_id,
            'related_ticket_id' => $original->id,
        ]);

        $this->assertTrue($nuevo->relatedTicket->is($original));
    }

    #[TestDox('Un ticket con mensajes no se puede borrar (el historial se conserva)')]
    public function test_ticket_with_messages_cannot_be_deleted(): void
    {
        $ticket = Ticket::factory()->create();
        TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $ticket->requester_id]);

        $this->expectException(QueryException::class);
        $ticket->delete();
    }

    #[TestDox('El equipo que atiende un ticket se deduce de su departamento, y Contabilidad (valor `other`) tiene el suyo (R13)')]
    public function test_team_is_derived_from_department(): void
    {
        $this->assertSame('support', Department::Support->team()->value);
        $this->assertSame('applications', Department::WebApps->team()->value);
        $this->assertSame('applications', Department::Websites->team()->value);
        $this->assertSame('accounting', Department::Other->team()->value);
    }
}
