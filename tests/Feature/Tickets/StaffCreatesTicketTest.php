<?php

/*
 * Ticket creado POR EL EQUIPO a nombre de un cliente (P1; docs/plan/p1-ticket-creado-por-agente.md, mockups validados 2026-11-12).
 * Cuándo tocarlo: al cambiar quién puede crearlos, a quién se asignan o qué ve el cliente.
 * Lo que de verdad vigila:
 *  - agentes y administrador crean; el cliente recibe 403 (y no se crea nada);
 *  - nace «Abierto» y asignado, a nombre del cliente (su empresa, R1), con canal y «creado por» INTERNOS (el cliente no los ve);
 *  - un agente solo crea en lo que atiende y asigna a quien lo atiende (R11/R13); el administrador crea pero no atiende (R10);
 *  - R6 igual que en el formulario del cliente (producto de SU empresa; categoría del departamento);
 *  - el aviso al cliente solo sale si quien lo crea lo marca; nunca a quien actúa.
 * Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Tickets;

use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\StaffCreation;
use App\Enums\Department;
use App\Enums\MessageKind;
use App\Enums\TicketChannel;
use App\Enums\TicketEventType;
use App\Enums\TicketNoticeKind;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Notifications\TicketNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class StaffCreatesTicketTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    private Product $producto;

    private Category $categoria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
        $this->producto = Product::factory()->create(['name' => 'oneFact']);
        $this->empresaA->products()->attach($this->producto);
        $this->categoria = Category::factory()->forDepartment(Department::WebApps)->create();
    }

    private function como(string $quien): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->a[$quien]);
    }

    /** @param array<string, mixed> $override */
    private function datos(array $override = []): array
    {
        return array_merge([
            'solicitante' => $this->a['cuA']->id,
            'canal' => TicketChannel::Phone->value,
            'department' => Department::WebApps->value,
            'category_id' => $this->categoria->id,
            'product_id' => $this->producto->id,
            'subject' => 'No puede emitir la factura',
            'description' => 'María llama porque sale un error al emitir.',
            'asignado' => $this->a['agApp']->id,
        ], $override);
    }

    #[TestDox('Un agente crea un ticket a nombre del cliente: nace Abierto, asignado, de la empresa del cliente, con su primer mensaje y el historial (visible para el cliente + interno para el equipo)')]
    public function test_agent_creates_a_ticket_for_a_customer(): void
    {
        Notification::fake();

        $this->como('agApp')->post(route('tickets.staff.store'), $this->datos())->assertSessionHasNoErrors();

        $ticket = Ticket::query()->sole();
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame($this->empresaA->id, $ticket->company_id, 'la empresa sale del cliente elegido (R1), no de quien lo crea');
        $this->assertSame($this->a['cuA']->id, $ticket->requester_id);
        $this->assertSame($this->a['agApp']->id, $ticket->created_by_id);
        $this->assertSame($this->a['agApp']->id, $ticket->assigned_to);
        $this->assertSame(TicketChannel::Phone, $ticket->channel);
        $this->assertSame($this->producto->id, $ticket->product_id);

        $mensaje = $ticket->messages()->sole();
        $this->assertSame(MessageKind::Public, $mensaje->kind);
        $this->assertSame($this->a['cuA']->id, $mensaje->author_id, 'el cliente ve el ticket como suyo');

        $visible = $ticket->events()->where('type', TicketEventType::Created->value)->sole();
        $this->assertSame($this->a['cuA']->id, $visible->actor_id);
        $this->assertTrue($visible->visible_to_customer);

        $interno = $ticket->events()->where('type', TicketEventType::CreatedByStaff->value)->sole();
        $this->assertSame($this->a['agApp']->id, $interno->actor_id);
        $this->assertFalse($interno->visible_to_customer, 'quién lo creó y el canal son internos');
        $this->assertSame('phone', $interno->data['channel']);
        $this->assertSame('Agente Aplicaciones', $interno->data['to_name']);
    }

    #[TestDox('El cliente NO ve el canal ni quién lo creó: ni en el detalle ni entre los eventos que le llegan (R2-like: lo interno no sale)')]
    public function test_customer_does_not_see_channel_or_creator(): void
    {
        // Lo crea el agente de Soporte y se asigna a otro: el cliente SÍ ve quién lo atiende (es normal), pero no quién lo creó.
        $this->como('agSup')->post(route('tickets.staff.store'), $this->datos(['canal' => TicketChannel::InPerson->value, 'asignado' => $this->a['agApp']->id]));
        $ticket = Ticket::query()->sole();

        $this->assertSame(0, TicketEvent::query()->visibleTo($this->a['cuA'])->where('type', TicketEventType::CreatedByStaff->value)->count());

        $html = $this->como('cuA')->get(route('tickets.show', $ticket))->assertOk()->getContent();
        $this->assertStringNotContainsString('Agente Soporte', $html, 'quién lo creó es interno');
        $this->assertStringNotContainsString('En persona', $html);
        $this->assertStringNotContainsString('Canal', $html);

        $equipo = $this->como('agSup')->get(route('tickets.show', $ticket))->assertOk()->getContent();
        $this->assertStringContainsString('En persona', $equipo, 'el equipo sí ve el canal');
        $this->assertStringContainsString('creó el ticket a nombre de', $equipo);
    }

    #[TestDox('El administrador crea y asigna a un agente que atienda el departamento; sin elegir agente, o eligiéndose a sí mismo, se rechaza (R10: no atiende)')]
    public function test_admin_creates_but_must_assign_an_agent(): void
    {
        Notification::fake();

        $this->como('admin')->post(route('tickets.staff.store'), $this->datos(['asignado' => $this->a['agSup']->id]))->assertSessionHasNoErrors();
        $ticket = Ticket::query()->sole();
        $this->assertSame($this->a['admin']->id, $ticket->created_by_id);
        $this->assertSame($this->a['agSup']->id, $ticket->assigned_to, 'un agente de Soporte atiende también Aplicaciones (R11)');
        Notification::assertSentTo($this->a['agSup'], TicketNotice::class, fn (TicketNotice $n) => $n->kind === TicketNoticeKind::Assigned);

        $this->como('admin')->post(route('tickets.staff.store'), $this->datos(['asignado' => '']))->assertSessionHasErrors('asignado');
        $this->como('admin')->post(route('tickets.staff.store'), $this->datos(['asignado' => $this->a['admin']->id]))->assertSessionHasErrors('asignado');
        $this->assertSame(1, Ticket::query()->count());
    }

    #[TestDox('Un cliente (usuario o administrador de empresa) recibe 403 en el formulario y al enviar, y no se crea nada')]
    public function test_customers_cannot_create_for_customers(): void
    {
        foreach (['cuA', 'caA', 'cuB'] as $quien) {
            $this->como($quien)->get(route('tickets.staff.create'))->assertForbidden();
            $this->como($quien)->post(route('tickets.staff.store'), $this->datos())->assertForbidden();
        }
        $this->assertSame(0, Ticket::query()->count());
    }

    #[TestDox('R13: un agente de Soporte o de Desarrollo no crea en Contabilidad; el de Contabilidad solo crea en Contabilidad')]
    public function test_department_limits_follow_the_team(): void
    {
        $contabilidad = Category::factory()->forDepartment(Department::Other)->create();
        $datosConta = $this->datos(['department' => Department::Other->value, 'category_id' => $contabilidad->id, 'product_id' => $this->producto->id, 'asignado' => $this->a['agCon']->id]);

        $this->como('agSup')->post(route('tickets.staff.store'), $datosConta)->assertSessionHasErrors('department');
        $this->como('agApp')->post(route('tickets.staff.store'), $datosConta)->assertSessionHasErrors('department');
        $this->como('agCon')->post(route('tickets.staff.store'), $datosConta)->assertSessionHasNoErrors();
        $this->assertSame(Department::Other, Ticket::query()->sole()->department);

        $this->como('agCon')->post(route('tickets.staff.store'), $this->datos())->assertSessionHasErrors('department');
        $this->assertSame(1, Ticket::query()->count());
    }

    #[TestDox('Solo se asigna a un agente en activo que atienda el departamento: ni a quien es de otro equipo, ni a una cuenta de baja, ni a un cliente')]
    public function test_assignee_must_be_able_to_work_the_department(): void
    {
        $baja = User::factory()->agent()->deactivated()->create();

        foreach ([$this->a['agCon']->id, $baja->id, $this->a['cuA']->id] as $asignado) {
            $this->como('agApp')->post(route('tickets.staff.store'), $this->datos(['asignado' => $asignado]))->assertSessionHasErrors('asignado');
        }
        $this->assertSame(0, Ticket::query()->count());
    }

    #[TestDox('El cliente elegido debe ser una persona activa de una empresa activa y de rol cliente; si no, el mismo error (R17)')]
    public function test_customer_must_be_active_person_of_an_active_company(): void
    {
        $empresaBaja = Company::factory()->deactivated()->create();
        $deEmpresaBaja = User::factory()->forCompany($empresaBaja)->create();
        $deBaja = User::factory()->forCompany($this->empresaA)->deactivated()->create();

        foreach ([$deEmpresaBaja->id, $deBaja->id, $this->a['agApp']->id, 999999] as $solicitante) {
            $this->como('agApp')->post(route('tickets.staff.store'), $this->datos(['solicitante' => $solicitante]))
                ->assertSessionHasErrors(['solicitante' => 'Elige a una persona activa de una empresa activa.']);
        }
        $this->assertSame(0, Ticket::query()->count());
    }

    #[TestDox('R6 como en el formulario del cliente: el producto debe ser de la empresa del CLIENTE, la categoría del departamento, y el producto es obligatorio si la empresa tiene alguno')]
    public function test_r6_is_validated_on_the_server(): void
    {
        $ajeno = Product::factory()->create();
        $this->empresaB->products()->attach($ajeno);
        $otraCategoria = Category::factory()->forDepartment(Department::Support)->create();

        $this->como('agApp')->post(route('tickets.staff.store'), $this->datos(['product_id' => $ajeno->id]))->assertSessionHasErrors('product_id');
        $this->como('agApp')->post(route('tickets.staff.store'), $this->datos(['product_id' => null]))->assertSessionHasErrors('product_id');
        $this->como('agApp')->post(route('tickets.staff.store'), $this->datos(['category_id' => $otraCategoria->id]))->assertSessionHasErrors('category_id');
        $this->como('agApp')->post(route('tickets.staff.store'), $this->datos(['canal' => TicketChannel::Web->value]))->assertSessionHasErrors('canal');
        $this->assertSame(0, Ticket::query()->count());

        // Cliente de una empresa SIN productos: no se admite producto.
        $sinProductos = Company::factory()->create();
        $cliente = User::factory()->forCompany($sinProductos)->create();
        $this->como('agApp')->post(route('tickets.staff.store'), $this->datos(['solicitante' => $cliente->id]))->assertSessionHasErrors('product_id');
        $this->como('agApp')->post(route('tickets.staff.store'), $this->datos(['solicitante' => $cliente->id, 'product_id' => null]))->assertSessionHasNoErrors();
        $this->assertSame($sinProductos->id, Ticket::query()->sole()->company_id);
    }

    #[TestDox('El aviso al cliente solo sale si quien lo crea marca la casilla; a quien se asigna se le avisa salvo que se lo asigne él mismo; nunca se avisa al equipo de «sin asignar»')]
    public function test_notices_follow_the_checkbox(): void
    {
        Notification::fake();

        // Sin casilla y asignado a sí mismo: no sale ningún aviso.
        $this->como('agApp')->post(route('tickets.staff.store'), $this->datos());
        Notification::assertNothingSent();

        // Con casilla y asignado a otro: cliente + asignado; el que actúa, nada.
        $this->como('agApp')->post(route('tickets.staff.store'), $this->datos(['notificar' => '1', 'asignado' => $this->a['agSup']->id]));
        Notification::assertSentTo($this->a['cuA'], TicketNotice::class, fn (TicketNotice $n) => $n->kind === TicketNoticeKind::OpenedByStaff && ! str_contains($n->title.$n->detail, 'Agente Aplicaciones'));
        Notification::assertSentTo($this->a['agSup'], TicketNotice::class, fn (TicketNotice $n) => $n->kind === TicketNoticeKind::Assigned);
        Notification::assertNotSentTo($this->a['agApp'], TicketNotice::class);
        Notification::assertNotSentTo($this->a['agSup'], TicketNotice::class, fn (TicketNotice $n) => $n->kind === TicketNoticeKind::NewUnassigned);
    }

    #[TestDox('Los adjuntos (capturas, PDF) del ticket creado por el equipo pasan por la misma lista cerrada y quedan a nombre de quien los subió')]
    public function test_attachments_use_the_same_rules(): void
    {
        Storage::fake(Attachment::DISK);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $ruta = tempnam(sys_get_temp_dir(), 'lm').'.png';
        file_put_contents($ruta, $png);
        $php = tempnam(sys_get_temp_dir(), 'lm').'.php';
        file_put_contents($php, '<?php echo 1;');

        $this->como('agApp')->post(route('tickets.staff.store'), $this->datos(['adjuntos' => [new UploadedFile($php, 'malo.php', null, null, true)]]))->assertSessionHasErrors('adjuntos.0');
        $this->assertSame(0, Ticket::query()->count());

        $this->como('agApp')->post(route('tickets.staff.store'), $this->datos(['adjuntos' => [new UploadedFile($ruta, 'captura.png', null, null, true)]]))->assertSessionHasNoErrors();
        $adjunto = Attachment::query()->sole();
        $this->assertSame($this->a['agApp']->id, $adjunto->uploaded_by);
        $this->assertSame('image/png', $adjunto->mime_type);
        @unlink($ruta);
        @unlink($php);
    }

    #[TestDox('El paso 1 solo ofrece personas activas de empresas activas; el paso 2 solo los productos de SU empresa y solo los departamentos que atiende quien lo crea')]
    public function test_the_screens_only_offer_what_is_valid(): void
    {
        $empresaBaja = Company::factory()->deactivated()->create(['name' => 'Empresa de baja SL']);
        User::factory()->forCompany($empresaBaja)->create(['name' => 'Persona de empresa de baja']);
        User::factory()->forCompany($this->empresaA)->deactivated()->create(['name' => 'Persona dada de baja']);
        $otroProducto = Product::factory()->create(['name' => 'ProductoDeB']);
        $this->empresaB->products()->attach($otroProducto);

        $paso1 = $this->como('agApp')->get(route('tickets.staff.create'))->assertOk()->getContent();
        $this->assertStringContainsString('Usuario A', $paso1);
        $this->assertStringNotContainsString('Persona de empresa de baja', $paso1);
        $this->assertStringNotContainsString('Persona dada de baja', $paso1);
        $this->assertStringNotContainsString('Agente Aplicaciones</option>', $paso1, 'los agentes no son clientes');

        $paso2 = $this->como('agApp')->get(route('tickets.staff.create', ['solicitante' => $this->a['cuA']->id]))->assertOk()->getContent();
        $this->assertStringContainsString('oneFact', $paso2);
        $this->assertStringNotContainsString('ProductoDeB', $paso2);
        $this->assertStringNotContainsString('name="department" value="'.Department::Other->value.'"', $paso2, 'un agente de Desarrollo no ve Contabilidad (R13)');
        $this->assertStringNotContainsString('Elige un agente', $paso2, 'el agente se asigna a sí mismo por defecto');

        $admin = $this->como('admin')->get(route('tickets.staff.create', ['solicitante' => $this->a['cuA']->id]))->assertOk()->getContent();
        $this->assertStringContainsString('name="department" value="'.Department::Other->value.'"', $admin);
        $this->assertStringContainsString('Elige un agente', $admin, 'el administrador debe elegir agente (R10)');

        // Una persona no válida en el paso 2 vuelve al paso 1 con el mismo mensaje genérico.
        $this->como('agApp')->get(route('tickets.staff.create', ['solicitante' => $this->a['agSup']->id]))->assertRedirect(route('tickets.staff.create'));
    }

    #[TestDox('Segunda capa: la ACCIÓN recomprueba permiso, departamento, cliente y asignado aunque se la llame sin pasar por el formulario')]
    public function test_the_action_rechecks_everything(): void
    {
        $accion = app(CreateTicket::class);
        $llamar = fn (User $actor, User $cliente, Department $dep, User $asignado) => $accion(
            $cliente, $dep, Category::factory()->forDepartment($dep)->create(), null, 'Asunto', 'Texto', [], null,
            new StaffCreation($actor, TicketChannel::Phone, $asignado, false),
        );
        $esperaError = function (string $campo, \Closure $f): void {
            try {
                $f();
                $this->fail("Debía rechazarse ($campo)");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($campo, $e->errors());
            }
        };

        // Un cliente no puede (la policy lo impide aunque alguien llame a la acción).
        try {
            $llamar($this->a['cuA'], $this->a['cuA'], Department::WebApps, $this->a['agApp']);
            $this->fail('Un cliente no debería poder crear a nombre de otro');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $esperaError('department', fn () => $llamar($this->a['agSup'], $this->a['cuA'], Department::Other, $this->a['agCon']));
        $esperaError('asignado', fn () => $llamar($this->a['agApp'], $this->a['cuA'], Department::WebApps, $this->a['agCon']));
        $esperaError('asignado', fn () => $llamar($this->a['agApp'], $this->a['cuA'], Department::WebApps, $this->a['admin']));
        $esperaError('solicitante', fn () => $llamar($this->a['agApp'], User::factory()->forCompany($this->empresaA)->deactivated()->create(), Department::WebApps, $this->a['agApp']));
        $esperaError('solicitante', fn () => $llamar($this->a['agApp'], $this->a['agSup'], Department::WebApps, $this->a['agApp']));
        $this->assertSame(0, Ticket::query()->count());
    }

    #[TestDox('La base impide un canal inventado y un ticket sin «creado por»')]
    public function test_database_constraints(): void
    {
        $ticket = $this->ticket();

        $this->expectException(QueryException::class);
        DB::table('tickets')->where('id', $ticket->id)->update(['channel' => 'sms']);
    }

    #[TestDox('created_by_id es obligatorio en la base de datos')]
    public function test_created_by_is_not_null(): void
    {
        $ticket = $this->ticket();

        $this->expectException(QueryException::class);
        DB::table('tickets')->where('id', $ticket->id)->update(['created_by_id' => null]);
    }
}
