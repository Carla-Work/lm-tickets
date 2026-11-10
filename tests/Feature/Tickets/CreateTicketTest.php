<?php

/*
 * Abrir un ticket. Reglas: R1 (la empresa sale del solicitante), R5 (queda en el historial), R6 (producto de
 * su empresa, obligatorio si la empresa tiene), solo los clientes abren tickets.
 * Cuándo tocarlo: solo si la usuaria cambia esas reglas. Si falla un test, se arregla el código o se discute
 * la regla; NO se adapta el test.
 */

namespace Tests\Feature\Tickets;

use App\Enums\Department;
use App\Enums\MessageKind;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class CreateTicketTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    private Product $productA;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
        $this->productA = Product::factory()->create(['name' => 'oneFact']);
        $this->empresaA->products()->attach($this->productA);
        $this->category = Category::factory()->forDepartment(Department::WebApps)->create();
    }

    /** @param array<string, mixed> $override */
    private function datos(array $override = []): array
    {
        return array_merge([
            'department' => Department::WebApps->value,
            'category_id' => $this->category->id,
            'product_id' => $this->productA->id,
            'subject' => 'No puedo emitir la factura',
            'description' => "Al pulsar «Emitir» sale un error.\nSegunda línea.",
        ], $override);
    }

    public function test_un_cliente_abre_un_ticket_con_su_primer_mensaje_y_su_historial(): void
    {
        $respuesta = $this->actingAs($this->a['cuA'])->post(route('tickets.store'), $this->datos());

        $ticket = Ticket::query()->firstOrFail();
        $respuesta->assertRedirect(route('tickets.show', $ticket));
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame($this->empresaA->id, $ticket->company_id);
        $this->assertSame($this->a['cuA']->id, $ticket->requester_id);
        $this->assertSame($this->productA->id, $ticket->product_id);
        $this->assertSame(now()->format('Y').'-0001', $ticket->number);

        $mensaje = $ticket->messages()->sole();
        $this->assertSame(MessageKind::Public, $mensaje->kind);
        $this->assertSame($this->a['cuA']->id, $mensaje->author_id);
        $this->assertStringContainsString('Segunda línea', $mensaje->body);

        $evento = $ticket->events()->sole();
        $this->assertSame(TicketEventType::Created, $evento->type);
        $this->assertTrue($evento->visible_to_customer);
    }

    public function test_el_administrador_de_empresa_tambien_abre_tickets(): void
    {
        $this->actingAs($this->a['caA'])->post(route('tickets.store'), $this->datos())->assertRedirect();
        $this->assertSame($this->a['caA']->id, Ticket::query()->sole()->requester_id);
    }

    public function test_la_empresa_sale_del_solicitante_y_no_del_formulario_r1(): void
    {
        $this->actingAs($this->a['cuA'])->post(route('tickets.store'), $this->datos([
            'company_id' => $this->empresaB->id,
            'requester_id' => $this->a['cuB']->id,
            'status' => 'closed',
            'assigned_to' => $this->a['agSup']->id,
        ]));

        $ticket = Ticket::query()->sole();
        $this->assertSame($this->empresaA->id, $ticket->company_id);
        $this->assertSame($this->a['cuA']->id, $ticket->requester_id);
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertNull($ticket->assigned_to);
    }

    public function test_un_producto_de_otra_empresa_se_rechaza_r6(): void
    {
        $ajeno = Product::factory()->create();
        $this->empresaB->products()->attach($ajeno);

        $this->actingAs($this->a['cuA'])->post(route('tickets.store'), $this->datos(['product_id' => $ajeno->id]))
            ->assertSessionHasErrors('product_id');
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_un_producto_existente_pero_no_asignado_a_la_empresa_se_rechaza_r6(): void
    {
        $sinAsignar = Product::factory()->create();

        $this->actingAs($this->a['cuA'])->post(route('tickets.store'), $this->datos(['product_id' => $sinAsignar->id]))
            ->assertSessionHasErrors('product_id');
    }

    public function test_si_la_empresa_tiene_productos_el_producto_es_obligatorio_r6(): void
    {
        $this->actingAs($this->a['cuA'])->post(route('tickets.store'), $this->datos(['product_id' => null]))
            ->assertSessionHasErrors('product_id');
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_un_producto_desactivado_no_se_puede_elegir_r6(): void
    {
        $this->productA->update(['active' => false]);

        // Sin productos activos la empresa «no tiene productos»: no se admite ninguno.
        $this->actingAs($this->a['cuA'])->post(route('tickets.store'), $this->datos())->assertSessionHasErrors('product_id');
    }

    public function test_si_la_empresa_no_tiene_productos_no_se_admite_ninguno_y_el_ticket_va_sin_producto(): void
    {
        $this->actingAs($this->a['cuB'])->post(route('tickets.store'), $this->datos(['product_id' => $this->productA->id]))
            ->assertSessionHasErrors('product_id');

        $this->actingAs($this->a['cuB'])->post(route('tickets.store'), $this->datos(['product_id' => null]))->assertRedirect();
        $this->assertNull(Ticket::query()->sole()->product_id);
    }

    public function test_la_categoria_debe_ser_del_departamento_elegido(): void
    {
        $this->actingAs($this->a['cuA'])->post(route('tickets.store'), $this->datos(['department' => Department::Support->value]))
            ->assertSessionHasErrors('category_id');
    }

    public function test_una_categoria_desactivada_se_rechaza(): void
    {
        $this->category->update(['active' => false]);

        $this->actingAs($this->a['cuA'])->post(route('tickets.store'), $this->datos())->assertSessionHasErrors('category_id');
    }

    public function test_asunto_y_descripcion_son_obligatorios_y_el_departamento_debe_existir(): void
    {
        $this->actingAs($this->a['cuA'])->post(route('tickets.store'), $this->datos(['subject' => '', 'description' => '', 'department' => 'inventado']))
            ->assertSessionHasErrors(['subject', 'description', 'department']);
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_los_agentes_y_el_administrador_no_abren_tickets(): void
    {
        foreach (['agSup', 'agApp', 'admin'] as $clave) {
            $this->actingAs($this->a[$clave])->post(route('tickets.store'), $this->datos())->assertForbidden();
            $this->actingAs($this->a[$clave])->get(route('tickets.create'))->assertForbidden();
        }
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_sin_sesion_no_se_puede_abrir_un_ticket(): void
    {
        $this->post(route('tickets.store'), $this->datos())->assertRedirect(route('login'));
    }

    public function test_los_numeros_de_ticket_son_correlativos(): void
    {
        $this->actingAs($this->a['cuA'])->post(route('tickets.store'), $this->datos());
        $this->actingAs($this->a['cuA'])->post(route('tickets.store'), $this->datos());

        $this->assertSame(
            [now()->format('Y').'-0001', now()->format('Y').'-0002'],
            Ticket::query()->orderBy('id')->get()->map(fn (Ticket $t) => $t->number)->all()
        );
    }
}
