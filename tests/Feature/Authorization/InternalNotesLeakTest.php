<?php

/*
 * R2: las notas internas NUNCA llegan al cliente, por ninguna vía de consulta.
 * Cuándo tocarlo: al añadir otra forma de listar mensajes, eventos o adjuntos (una pantalla, un correo,
 * un aviso): cada una debe usar los ámbitos visibleTo y tener aquí su comprobación.
 * Si falla un test, se arregla el código; NO se adapta el test.
 */

namespace Tests\Feature\Authorization;

use App\Enums\Department;
use App\Enums\TicketEventType;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class InternalNotesLeakTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    private Ticket $ticket;

    private TicketMessage $publico;

    private TicketMessage $nota;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();

        $this->ticket = $this->ticket(assignedTo: 'agApp');
        $this->publico = TicketMessage::factory()->create(['ticket_id' => $this->ticket->id, 'author_id' => $this->a['cuA']->id, 'body' => 'MENSAJE PÚBLICO']);
        $this->nota = TicketMessage::factory()->internal()->create(['ticket_id' => $this->ticket->id, 'author_id' => $this->a['agApp']->id, 'body' => 'NOTA INTERNA SECRETA']);
    }

    private function adjunto(TicketMessage $mensaje, string $nombre): Attachment
    {
        $adjunto = new Attachment;
        $adjunto->forceFill([
            'ticket_id' => $mensaje->ticket_id, 'ticket_message_id' => $mensaje->id, 'uploaded_by' => $mensaje->author_id,
            'original_name' => $nombre, 'path' => 'attachments/'.$nombre, 'mime_type' => 'text/plain', 'size' => 10,
        ])->save();

        return $adjunto;
    }

    private function evento(bool $visible, string $texto): TicketEvent
    {
        $evento = new TicketEvent;
        $evento->forceFill([
            'ticket_id' => $this->ticket->id, 'actor_id' => $this->a['agApp']->id,
            'type' => TicketEventType::Assigned->value, 'data' => ['texto' => $texto], 'visible_to_customer' => $visible,
        ])->save();

        return $evento;
    }

    // ---------- mensajes ----------

    #[TestDox('Los clientes (usuario y administrador de empresa) solo reciben los mensajes PÚBLICOS del hilo (R2)')]
    public function test_clients_only_get_public_messages(): void
    {
        foreach (['cuA', 'caA'] as $cliente) {
            $mensajes = TicketMessage::visibleTo($this->a[$cliente])->where('ticket_id', $this->ticket->id)->pluck('body')->all();

            $this->assertSame(['MENSAJE PÚBLICO'], $mensajes, "$cliente no debe recibir la nota interna");
        }
    }

    #[TestDox('El equipo (agentes y administrador) recibe el hilo completo, notas incluidas')]
    public function test_staff_get_the_full_thread(): void
    {
        foreach (['agApp', 'agSup', 'admin'] as $empleado) {
            $this->assertCount(2, TicketMessage::visibleTo($this->a[$empleado])->where('ticket_id', $this->ticket->id)->get(), $empleado);
        }
    }

    #[TestDox('Recorriendo TODOS los mensajes de la base de datos, un cliente nunca recibe una nota, ni de su empresa ni de otra')]
    public function test_a_client_never_gets_any_note_anywhere(): void
    {
        for ($i = 0; $i < 5; $i++) {
            TicketMessage::factory()->internal()->create(['ticket_id' => $this->ticket->id, 'author_id' => $this->a['agSup']->id]);
        }
        $otro = Ticket::factory()->create(['company_id' => $this->empresaB->id, 'requester_id' => $this->a['cuB']->id]);
        TicketMessage::factory()->internal()->create(['ticket_id' => $otro->id, 'author_id' => $this->a['agSup']->id]);

        foreach (['cuA', 'cuA2', 'caA', 'cuB', 'caB'] as $cliente) {
            $this->assertSame(0, TicketMessage::visibleTo($this->a[$cliente])->where('kind', 'internal')->count(), "$cliente recibe notas");
        }
    }

    #[TestDox('La policy de mensaje: la nota la ve el equipo y no el cliente; el mensaje público no lo ve quien no ve el ticket (R1, R2)')]
    public function test_message_policy(): void
    {
        $this->assertFalse($this->a['cuA']->can('view', $this->nota));
        $this->assertFalse($this->a['caA']->can('view', $this->nota));
        foreach (['agApp', 'agSup', 'admin'] as $empleado) {
            $this->assertTrue($this->a[$empleado]->can('view', $this->nota), $empleado);
        }

        $this->assertTrue($this->a['cuA']->can('view', $this->publico));
        $this->assertFalse($this->a['cuA2']->can('view', $this->publico), 'un compañero sin acceso al ticket tampoco ve sus mensajes públicos');
        $this->assertFalse($this->a['cuB']->can('view', $this->publico), 'otra empresa (R1)');
    }

    #[TestDox('En un ticket de «Otros» las notas son solo del administrador: ningún agente las ve ni puede leer el mensaje')]
    public function test_notes_of_other_tickets_are_not_visible_to_agents(): void
    {
        $deOtros = $this->ticket(Department::Other);
        $nota = TicketMessage::factory()->internal()->create(['ticket_id' => $deOtros->id, 'author_id' => $this->a['admin']->id]);

        $this->assertTrue($this->a['admin']->can('view', $nota));
        $this->assertFalse($this->a['agApp']->can('view', $nota));
        $this->assertFalse($this->a['agSup']->can('view', $nota));
        $this->assertFalse($this->a['cuA']->can('view', $nota));
    }

    #[TestDox('Un empleado dado de baja deja de ver notas aunque su rol sea interno: el ámbito lo trata como cliente')]
    public function test_deactivated_staff_gets_only_public_messages(): void
    {
        $this->a['agApp']->forceFill(['deactivated_at' => now()])->save();

        $mensajes = TicketMessage::visibleTo($this->a['agApp']->fresh())->where('ticket_id', $this->ticket->id)->pluck('body')->all();

        $this->assertSame(['MENSAJE PÚBLICO'], $mensajes);
    }

    // ---------- eventos del historial ----------

    #[TestDox('Un cliente solo ve los eventos marcados como visibles (no las reasignaciones entre agentes); el equipo, todos')]
    public function test_events_visibility(): void
    {
        $this->evento(false, 'REASIGNACIÓN INTERNA');
        $this->evento(true, 'CAMBIO DE DEPARTAMENTO');

        foreach (['cuA', 'caA'] as $cliente) {
            $textos = TicketEvent::visibleTo($this->a[$cliente])->get()->map(fn ($e) => $e->data['texto'])->all();
            $this->assertSame(['CAMBIO DE DEPARTAMENTO'], $textos, $cliente);
        }
        $this->assertCount(2, TicketEvent::visibleTo($this->a['agApp'])->get());
        $this->assertCount(2, TicketEvent::visibleTo($this->a['admin'])->get());
    }

    // ---------- adjuntos ----------

    #[TestDox('Los adjuntos de una NOTA interna no los recibe un cliente; los de un mensaje público, sí')]
    public function test_attachments_scope(): void
    {
        $this->adjunto($this->publico, 'captura-publica.png');
        $this->adjunto($this->nota, 'informe-interno.pdf');

        foreach (['cuA', 'caA'] as $cliente) {
            $nombres = Attachment::visibleTo($this->a[$cliente])->pluck('original_name')->all();
            $this->assertSame(['captura-publica.png'], $nombres, $cliente);
        }
        $this->assertCount(2, Attachment::visibleTo($this->a['agApp'])->get());
        $this->assertCount(2, Attachment::visibleTo($this->a['admin'])->get());
    }

    // ---------- el recuento tampoco filtra ----------

    #[TestDox('Los contadores tampoco delatan: un cliente cuenta solo los mensajes públicos, no el total con notas')]
    public function test_counts_do_not_reveal_notes(): void
    {
        $this->assertSame(1, TicketMessage::visibleTo($this->a['cuA'])->where('ticket_id', $this->ticket->id)->count());
        $this->assertSame(2, TicketMessage::visibleTo($this->a['agApp'])->where('ticket_id', $this->ticket->id)->count());
    }
}
