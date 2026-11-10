<?php

/*
 * Pantallas del cliente: lista, formulario y detalle. Reglas: R1 (nada de otras empresas ni de compañeros
 * para el usuario normal), R2 (ninguna nota interna llega al cliente), filtros y buscador sin fugas.
 * Cuándo tocarlo: al cambiar esas pantallas. NO se adapta un test para que pase.
 */

namespace Tests\Feature\Tickets;

use App\Enums\Department;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class ClientScreensTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    private function ticketDe(string $solicitante, string $asunto, TicketStatus $estado = TicketStatus::Open, Department $dep = Department::WebApps): Ticket
    {
        $actor = $this->a[$solicitante];

        return Ticket::factory()->create([
            'company_id' => $actor->company_id, 'requester_id' => $actor->id,
            'subject' => $asunto, 'status' => $estado->value, 'department' => $dep->value,
        ]);
    }

    public function test_la_lista_del_usuario_solo_trae_los_suyos(): void
    {
        $this->ticketDe('cuA', 'Mío visible');
        $this->ticketDe('cuA2', 'De un compañero');
        $this->ticketDe('caA', 'Del administrador de mi empresa');
        $this->ticketDe('cuB', 'De otra empresa');

        $this->actingAs($this->a['cuA'])->get(route('tickets.index'))
            ->assertOk()->assertSee('Mío visible')
            ->assertDontSee('De un compañero')->assertDontSee('Del administrador de mi empresa')->assertDontSee('De otra empresa');
    }

    public function test_el_administrador_de_empresa_ve_los_de_su_empresa_y_ninguno_de_otra(): void
    {
        $this->ticketDe('cuA', 'Ticket de Ana');
        $this->ticketDe('cuA2', 'Ticket de Luis');
        $this->ticketDe('cuB', 'De otra empresa');

        $this->actingAs($this->a['caA'])->get(route('tickets.index'))
            ->assertOk()->assertSee('Ticket de Ana')->assertSee('Ticket de Luis')->assertDontSee('De otra empresa');
    }

    public function test_los_contadores_no_cuentan_tickets_ajenos(): void
    {
        $this->ticketDe('cuA', 'Mío', TicketStatus::PendingCustomer);
        $this->ticketDe('cuB', 'Ajeno 1', TicketStatus::PendingCustomer);
        $this->ticketDe('cuB', 'Ajeno 2', TicketStatus::PendingCustomer);

        $this->actingAs($this->a['cuA'])->get(route('tickets.index'))->assertViewHas('summary', fn (array $s) => $s['pending'] === 1)
            ->assertViewHas('total', 1);
    }

    public function test_el_filtro_por_estado_y_departamento_y_la_busqueda_por_numero(): void
    {
        $abierto = $this->ticketDe('cuA', 'Alfa abierto');
        $this->ticketDe('cuA', 'Beta resuelto', TicketStatus::Resolved, Department::Support);

        $this->actingAs($this->a['cuA'])->get(route('tickets.index', ['estado' => 'resolved']))->assertSee('Beta resuelto')->assertDontSee('Alfa abierto');
        $this->actingAs($this->a['cuA'])->get(route('tickets.index', ['departamento' => 'web_apps']))->assertSee('Alfa abierto')->assertDontSee('Beta resuelto');
        $this->actingAs($this->a['cuA'])->get(route('tickets.index', ['q' => '#'.$abierto->number]))->assertSee('Alfa abierto')->assertDontSee('Beta resuelto');
    }

    public function test_un_filtro_por_usuario_no_destapa_tickets_de_otro_para_un_usuario_normal(): void
    {
        $this->ticketDe('cuA2', 'De un compañero');

        $this->actingAs($this->a['cuA'])->get(route('tickets.index', ['usuario' => $this->a['cuA2']->id]))
            ->assertOk()->assertDontSee('De un compañero');
    }

    public function test_un_filtro_con_valor_invalido_se_rechaza_sin_error_de_servidor(): void
    {
        $this->actingAs($this->a['cuA'])->get(route('tickets.index', ['estado' => 'inventado']))->assertSessionHasErrors('estado');
    }

    public function test_el_detalle_solo_lo_ve_quien_tiene_permiso_r1(): void
    {
        $ticket = $this->ticketDe('cuA', 'Asunto privado');

        $this->actingAs($this->a['cuA'])->get(route('tickets.show', $ticket))->assertOk()->assertSee('Asunto privado');
        $this->actingAs($this->a['caA'])->get(route('tickets.show', $ticket))->assertOk();
        $this->actingAs($this->a['cuA2'])->get(route('tickets.show', $ticket))->assertForbidden();
        $this->actingAs($this->a['cuB'])->get(route('tickets.show', $ticket))->assertForbidden();
        $this->actingAs($this->a['caB'])->get(route('tickets.show', $ticket))->assertForbidden();
    }

    public function test_las_notas_internas_y_los_eventos_internos_no_llegan_al_cliente_r2(): void
    {
        $ticket = $this->ticketDe('cuA', 'Con notas');
        TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->a['cuA']->id, 'body' => 'Mensaje público del cliente']);
        TicketMessage::factory()->internal()->create(['ticket_id' => $ticket->id, 'author_id' => $this->a['agApp']->id, 'body' => 'NOTA-INTERNA-SECRETA']);
        $evento = new TicketEvent;
        $evento->ticket_id = $ticket->id;
        $evento->type = TicketEventType::Assigned;
        $evento->data = ['to' => 'DATO-INTERNO-SECRETO'];
        $evento->visible_to_customer = false;
        $evento->save();

        foreach (['cuA', 'caA'] as $clave) {
            $this->actingAs($this->a[$clave])->get(route('tickets.show', $ticket))
                ->assertOk()->assertSee('Mensaje público del cliente')
                ->assertDontSee('NOTA-INTERNA-SECRETA')->assertDontSee('DATO-INTERNO-SECRETO');
        }
    }

    public function test_el_texto_del_usuario_se_escapa_y_no_ejecuta_html(): void
    {
        $ticket = $this->ticketDe('cuA', '<script>alert(1)</script>');
        TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->a['cuA']->id, 'body' => '<img src=x onerror=alert(2)>']);

        $html = $this->actingAs($this->a['cuA'])->get(route('tickets.show', $ticket))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', (string) $html);
        $this->assertStringNotContainsString('<img src=x', (string) $html);
    }

    public function test_el_formulario_solo_ofrece_los_productos_de_la_empresa(): void
    {
        $propio = Product::factory()->create(['name' => 'ProductoPropio']);
        $ajeno = Product::factory()->create(['name' => 'ProductoAjeno']);
        $this->empresaA->products()->attach($propio);
        $this->empresaB->products()->attach($ajeno);

        $this->actingAs($this->a['cuA'])->get(route('tickets.create'))->assertOk()->assertSee('ProductoPropio')->assertDontSee('ProductoAjeno');
    }

    public function test_un_cliente_que_entra_al_panel_va_a_sus_tickets_y_el_equipo_a_la_bandeja(): void
    {
        $this->actingAs($this->a['cuA'])->get(route('panel'))->assertRedirect(route('tickets.index'));
        // Cambiado en el paso 4b: antes el equipo veía una página provisional (la bandeja aún no existía).
        $this->actingAs($this->a['agSup'])->get(route('panel'))->assertRedirect(route('inbox'));
    }

    public function test_la_lista_y_el_detalle_exigen_sesion(): void
    {
        $ticket = $this->ticketDe('cuA', 'X');
        $this->get(route('tickets.index'))->assertRedirect(route('login'));
        $this->get(route('tickets.show', $ticket))->assertRedirect(route('login'));
    }
}
