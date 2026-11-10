<?php

/*
 * «Abrir un ticket nuevo» relacionado con uno resuelto o cerrado. Reglas: R15 (nadie reabre: el original sigue
 * final), R16 (solo el cliente abre el relacionado; ni agentes ni administrador), R1 (solo con tickets a los que
 * esa persona puede responder con uno nuevo), R13 (el enlace al relacionado no se pinta a quien no puede verlo).
 * Cuándo tocarlo: al cambiar esas reglas. NO se adapta un test para que pase.
 */

namespace Tests\Feature\Tickets;

use App\Enums\Department;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class RelatedTicketTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    private function as(string $quien): static
    {
        return $this->actingAs($this->a[$quien]);
    }

    /** Envía el formulario de nuevo ticket relacionado con `$original` (o sin relación si es null). */
    private function enviar(string $quien, ?Ticket $original, array $extra = [])
    {
        $categoria = Category::factory()->forDepartment(Department::Support)->create();

        return $this->as($quien)->post(route('tickets.store'), array_filter([
            'department' => 'support', 'category_id' => $categoria->id, 'subject' => 'Vuelve a fallar',
            'description' => 'Otra vez el mismo problema', 'related_ticket_id' => $original?->id,
        ] + $extra, fn ($v) => $v !== null));
    }

    #[TestDox('R15: el cliente abre un ticket relacionado con uno RESUELTO; el original sigue resuelto y el evento recuerda la relación')]
    public function test_client_opens_related_to_resolved(): void
    {
        $original = $this->ticket(Department::Support, TicketStatus::Resolved);

        $this->enviar('cuA', $original)->assertSessionHasNoErrors();

        $nuevo = Ticket::query()->where('subject', 'Vuelve a fallar')->firstOrFail();
        $this->assertSame($original->id, $nuevo->related_ticket_id);
        $this->assertSame(TicketStatus::Open, $nuevo->status);
        $this->assertSame(TicketStatus::Resolved, $original->fresh()->status, 'nadie reabre el original');
        $evento = TicketEvent::query()->where('ticket_id', $nuevo->id)->where('type', TicketEventType::Created->value)->firstOrFail();
        $this->assertSame($original->id, $evento->data['related_ticket_id']);
    }

    #[TestDox('También se puede relacionar con uno CERRADO')]
    public function test_client_opens_related_to_closed(): void
    {
        $original = $this->ticket(Department::Support, TicketStatus::Closed);

        $this->enviar('cuA', $original)->assertSessionHasNoErrors();

        $this->assertSame($original->id, Ticket::query()->where('subject', 'Vuelve a fallar')->firstOrFail()->related_ticket_id);
    }

    #[TestDox('Un ticket que sigue vivo no admite relacionados (para eso se responde en él): no se crea nada')]
    public function test_cannot_relate_to_live_ticket(): void
    {
        foreach ([TicketStatus::Open, TicketStatus::InProgress, TicketStatus::PendingCustomer] as $estado) {
            $vivo = $this->ticket(Department::Support, $estado);

            $this->enviar('cuA', $vivo)->assertSessionHasErrors('related_ticket_id');
            $this->as('cuA')->get(route('tickets.create', ['relacionado' => $vivo->id]))->assertForbidden();
        }
        $this->assertSame(0, Ticket::query()->where('subject', 'Vuelve a fallar')->count());
    }

    #[TestDox('R16: agentes y administrador no abren tickets relacionados (solo el cliente), ni por el formulario ni enviándolo')]
    public function test_staff_cannot_open_related(): void
    {
        $original = $this->ticket(Department::Support, TicketStatus::Resolved, 'agSup');

        foreach (['agSup', 'agApp', 'admin'] as $quien) {
            $this->as($quien)->get(route('tickets.create', ['relacionado' => $original->id]))->assertForbidden();
            $this->enviar($quien, $original)->assertForbidden();
        }
        $this->assertSame(0, Ticket::query()->where('subject', 'Vuelve a fallar')->count());
    }

    #[TestDox('R1: ni otra empresa ni un compañero relacionan con un ticket ajeno; un id inexistente da lo mismo que uno ajeno')]
    public function test_cannot_relate_to_foreign_ticket(): void
    {
        $original = $this->ticket(Department::Support, TicketStatus::Resolved); // abierto por cuA

        foreach (['cuB', 'caB', 'cuA2'] as $otro) {
            $this->enviar($otro, $original)->assertSessionHasErrors('related_ticket_id');
            $this->as($otro)->get(route('tickets.create', ['relacionado' => $original->id]))->assertForbidden();
        }
        $this->as('cuA')->get(route('tickets.create', ['relacionado' => 999999]))->assertForbidden();
        $this->as('cuA')->post(route('tickets.store'), [
            'department' => 'support', 'category_id' => Category::factory()->forDepartment(Department::Support)->create()->id,
            'subject' => 'x', 'description' => 'y', 'related_ticket_id' => 999999,
        ])->assertSessionHasErrors('related_ticket_id');
        $this->assertSame(0, Ticket::query()->where('subject', 'Vuelve a fallar')->count());
    }

    #[TestDox('El administrador de empresa relaciona con cualquier ticket final de SU empresa, pero no de otra')]
    public function test_company_admin_can_relate_within_company(): void
    {
        $deUnCompanero = $this->ticket(Department::Support, TicketStatus::Resolved); // lo abrió cuA
        $deOtraEmpresa = Ticket::factory()->create([
            'company_id' => $this->empresaB->id, 'requester_id' => $this->a['cuB']->id, 'status' => TicketStatus::Resolved->value,
        ]);

        $this->enviar('caA', $deUnCompanero)->assertSessionHasNoErrors();
        $this->enviar('caA', $deOtraEmpresa)->assertSessionHasErrors('related_ticket_id');
    }

    #[TestDox('Una petición sin relación sigue creando un ticket normal (sin related_ticket_id)')]
    public function test_plain_ticket_has_no_relation(): void
    {
        $this->enviar('cuA', null)->assertSessionHasNoErrors();

        $this->assertNull(Ticket::query()->where('subject', 'Vuelve a fallar')->firstOrFail()->related_ticket_id);
    }

    #[TestDox('El formulario rellena departamento, tipo y producto con los del original y avisa de la relación')]
    public function test_form_is_prefilled_from_original(): void
    {
        $producto = Product::factory()->create(['name' => 'ProductoRelacionado']);
        $this->empresaA->products()->attach($producto);
        $categoria = Category::factory()->forDepartment(Department::WebApps)->create(['name' => 'CategoriaOriginal']);
        $original = Ticket::factory()->create([
            'company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA']->id, 'department' => 'web_apps',
            'category_id' => $categoria->id, 'product_id' => $producto->id, 'status' => TicketStatus::Resolved->value, 'subject' => 'Original roto',
        ]);

        $html = $this->as('cuA')->get(route('tickets.create', ['relacionado' => $original->id]))->assertOk()
            ->assertSee('quedará relacionado con el')->assertSee('Original roto')
            ->assertSee('name="related_ticket_id" value="'.$original->id.'"', false)->getContent();

        $this->assertMatchesRegularExpression('/value="web_apps"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/value="'.$categoria->id.'"[^>]*selected/', $html);
        $this->assertMatchesRegularExpression('/value="'.$producto->id.'"[^>]*selected/', $html);
    }

    #[TestDox('R6: si la categoría del original se desactivó o el producto ya no es de la empresa, NO se ofrecen como valor por defecto')]
    public function test_defaults_skip_values_no_longer_valid(): void
    {
        $producto = Product::factory()->create();
        $categoria = Category::factory()->forDepartment(Department::WebApps)->create(['active' => false]);
        $original = Ticket::factory()->create([
            'company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA']->id, 'department' => 'web_apps',
            'category_id' => $categoria->id, 'product_id' => $producto->id, 'status' => TicketStatus::Resolved->value,
        ]); // el producto nunca se asignó a la empresa A

        $respuesta = $this->as('cuA')->get(route('tickets.create', ['relacionado' => $original->id]))->assertOk();

        $this->assertNull($respuesta->viewData('defaults')['category_id']);
        $this->assertNull($respuesta->viewData('defaults')['product_id']);
    }

    #[TestDox('El detalle ofrece «Abrir un ticket nuevo» solo en un ticket final propio y no al equipo')]
    public function test_button_only_when_allowed(): void
    {
        $final = $this->ticket(Department::Support, TicketStatus::Resolved, 'agSup');
        $vivo = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $enlace = route('tickets.create', ['relacionado' => $final->id]);

        $this->as('cuA')->get(route('tickets.show', $final))->assertOk()->assertSee('Abrir un ticket nuevo')->assertSee($enlace, false);
        $this->as('cuA')->get(route('tickets.show', $vivo))->assertOk()->assertDontSee('Abrir un ticket nuevo');
        $this->as('caA')->get(route('tickets.show', $final))->assertOk()->assertSee('Abrir un ticket nuevo'); // administrador de su empresa
        $this->as('agSup')->get(route('tickets.show', $final))->assertOk()->assertDontSee('Abrir un ticket nuevo');
    }

    #[TestDox('El detalle del ticket nuevo enlaza al original; el equipo también lo ve, salvo que el original sea de «Otros» y quien mira sea un agente (R13)')]
    public function test_detail_links_to_related_respecting_visibility(): void
    {
        $normal = $this->ticket(Department::Support, TicketStatus::Resolved);
        $deOtros = $this->ticket(Department::Other, TicketStatus::Resolved);
        $conNormal = Ticket::factory()->create(['company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA']->id, 'department' => 'support', 'related_ticket_id' => $normal->id]);
        $conOtros = Ticket::factory()->create(['company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA']->id, 'department' => 'support', 'related_ticket_id' => $deOtros->id]);

        $this->as('cuA')->get(route('tickets.show', $conNormal))->assertOk()->assertSee(route('tickets.show', $normal), false)->assertSee('Ticket relacionado');
        $this->as('agSup')->get(route('tickets.show', $conNormal))->assertOk()->assertSee(route('tickets.show', $normal), false);
        $this->as('agSup')->get(route('tickets.show', $conOtros))->assertOk()->assertDontSee(route('tickets.show', $deOtros), false)->assertDontSee('Relacionado con');
        $this->as('admin')->get(route('tickets.show', $conOtros))->assertOk()->assertSee(route('tickets.show', $deOtros), false);
    }

    #[TestDox('Sin sesión no se puede abrir un ticket relacionado')]
    public function test_requires_login(): void
    {
        $original = $this->ticket(Department::Support, TicketStatus::Resolved);

        $this->get(route('tickets.create', ['relacionado' => $original->id]))->assertRedirect(route('login'));
    }
}
