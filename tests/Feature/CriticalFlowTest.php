<?php

/*
 * TEST DE INTEGRACIÓN DEL FLUJO CRÍTICO (cierre de la Fase 2). Recorre de punta a punta, con sesiones REALES
 * (inicio de sesión por el formulario, no `actingAs`), lo que hace una empresa cliente y el equipo de Ling Marco:
 *
 *   1 el usuario inicia sesión · 2 abre un ticket con adjunto · 3 recibe confirmación y se avisa al equipo ·
 *   4 un agente lo ve en la bandeja, se lo asigna y cambia el estado · 5 responde (y deja una nota interna) ·
 *   6 el cliente recibe el aviso, responde y el hilo continúa · 7 lo resuelve explicando cómo, el agente lo cierra,
 *   nadie lo reabre y el cliente abre uno nuevo relacionado.
 *
 * Cuándo tocarlo: al cambiar el flujo crítico. Si falla, NO se ajusta el test al código: se arregla el código o se
 * discute la regla (procedimiento de trabajo, regla 6). Cada paso lleva el número de la regla (R1…R21) que ejercita.
 * Los tests de cada regla por separado están en tests/Feature/Tickets y tests/Feature/Authorization; este recorre
 * TODO junto porque los fallos que aparecen al encadenar (estado, avisos, visibilidad) no salen en pruebas sueltas.
 */

namespace Tests\Feature;

use App\Enums\Department;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class CriticalFlowTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    /** @var list<string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->crearActores();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
        parent::tearDown();
    }

    // ---------- sesiones reales ----------

    /** Inicia sesión por el formulario real y deja a la persona en su pantalla de inicio. */
    private function entrar(string $quien): void
    {
        $this->post(route('login.store'), ['email' => $this->a[$quien]->email, 'password' => 'password'])
            ->assertRedirect(route('panel'));
        $this->assertAuthenticatedAs($this->a[$quien]);
    }

    /**
     * Simula que la siguiente petición llega "de cero": en producción cada petición vuelve a leer la cuenta y la
     * empresa de la base de datos, pero dentro de un test todas comparten la misma aplicación y el guardián de sesión
     * se queda con el usuario cargado al entrar. Sin esto, dar de baja a alguien "con la sesión abierta" no se vería.
     */
    private function nuevaPeticion(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function salir(): void
    {
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    private function captura(string $nombre = 'captura.png'): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'flujo');
        file_put_contents($ruta, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $this->temporales[] = $ruta;

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    /** Tipos de aviso que tiene una persona (campana). @return list<string> */
    private function avisos(string $quien): array
    {
        return $this->a[$quien]->fresh()->notifications()->get()->pluck('data.kind')->sort()->values()->all();
    }

    // =====================================================================================================
    //  EL FLUJO COMPLETO
    // =====================================================================================================

    #[TestDox('Flujo crítico completo: abrir un ticket, atenderlo, responder, resolver, cerrar y abrir uno relacionado')]
    public function test_the_whole_critical_flow(): void
    {
        $producto = Product::factory()->create(['name' => 'oneCOP']);
        $this->empresaA->products()->attach($producto);
        $categoria = Category::factory()->forDepartment(Department::Support)->create(['name' => 'Correo electrónico']);

        // ---- 1. El usuario de la empresa inicia sesión en su panel (R17, R18) ----
        $this->entrar('cuA');
        $this->get(route('panel'))->assertRedirect(route('tickets.index'));
        $this->get(route('tickets.index'))->assertOk()->assertSee('Mis tickets')->assertSee('No hay tickets que mostrar');

        // ---- 2. Abre un ticket: asunto, categoría, producto, descripción y adjunto (R3, R6) ----
        $this->get(route('tickets.create'))->assertOk()->assertSee('oneCOP')->assertSee('Adjuntos');
        $this->post(route('tickets.store'), [
            'department' => 'support', 'category_id' => $categoria->id, 'product_id' => $producto->id,
            'subject' => 'El correo de administración no recibe mensajes', 'description' => 'Desde el lunes no llega nada.',
            'adjuntos' => [$this->captura()],
        ])->assertSessionHasNoErrors();

        // ---- 3. Recibe confirmación con número de ticket y se avisa al equipo interno ----
        $ticket = Ticket::query()->firstOrFail();
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame($this->empresaA->id, $ticket->company_id, 'R1: la empresa sale del solicitante');
        $this->assertSame($producto->id, $ticket->product_id);
        $this->get(route('tickets.show', $ticket))->assertOk()->assertSee("Ticket #{$ticket->number}")->assertSee('El correo de administración')->assertSee('captura.png');
        $this->get(route('tickets.index'))->assertSee($ticket->subject);
        $this->assertSame(['received'], $this->avisos('cuA'), 'confirmación en la campana del cliente');
        $this->assertSame(['new_unassigned'], $this->avisos('agSup'), 'el equipo de Soporte, avisado');
        $this->assertSame([], $this->avisos('agApp'), 'el de Aplicaciones, no');
        $this->assertSame([], $this->avisos('admin'));
        $adjunto = Attachment::query()->firstOrFail();
        Storage::disk('local')->assertExists($adjunto->path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'R3: nada del adjunto en el disco público');

        // R1: otra empresa no sabe que existe (lista, detalle, adjunto).
        $this->salir();
        $this->entrar('cuB');
        $this->get(route('tickets.index'))->assertOk()->assertDontSee($ticket->subject);
        $this->get(route('tickets.show', $ticket))->assertForbidden();
        $this->get(route('attachments.download', $adjunto))->assertForbidden();
        $this->salir();

        // ---- 4. Un agente lo ve en la bandeja, se lo asigna y cambia su estado (R5, R11) ----
        $this->entrar('agSup');
        $this->get(route('panel'))->assertRedirect(route('inbox'));
        $this->get(route('inbox'))->assertOk()->assertSee($ticket->subject)->assertSee('Sin asignar')->assertSee('Avisos: 1 sin leer', false);
        $this->get(route('tickets.show', $ticket))->assertOk()->assertSee('Asignármelo')->assertSee('captura.png');
        $this->get(route('attachments.download', $adjunto))->assertOk();
        $this->post(route('tickets.assignment.update', $ticket), ['asignado' => $this->a['agSup']->id])->assertRedirect();
        $this->post(route('tickets.status.update', $ticket), ['estado' => 'in_progress'])->assertRedirect();
        $this->assertSame($this->a['agSup']->id, $ticket->fresh()->assigned_to);
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);

        // ---- 5. Responde al cliente y deja una nota interna que el cliente NUNCA ve (R2) ----
        $this->post(route('tickets.messages.store', $ticket), ['mensaje' => 'Hola, ¿puedes decirnos si el buzón está lleno?'])->assertRedirect();
        $this->post(route('tickets.notes.store', $ticket), ['mensaje' => 'NOTA-INTERNA: parece el MX del dominio'])->assertRedirect();
        $this->post(route('tickets.status.update', $ticket), ['estado' => 'pending_customer'])->assertRedirect();
        $this->get(route('tickets.show', $ticket))->assertOk()->assertSee('NOTA-INTERNA')->assertSee('Solo equipo de Ling Marco');
        $this->salir();

        // ---- 6. El usuario recibe aviso (campana), responde, y el hilo continúa (R14) ----
        $this->entrar('cuA');
        $this->get(route('tickets.index'))->assertOk()->assertSee('Avisos: 3 sin leer', false)->assertSee('Respuesta nueva');
        $this->assertSame(['received', 'replied', 'status_changed', 'status_changed'], $this->avisos('cuA'));
        $this->assertSame(0, $this->a['cuA']->notifications()->where('data', 'like', '%NOTA-INTERNA%')->count(), 'R2: la nota no genera aviso');
        $this->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('Hola, ¿puedes decirnos si el buzón está lleno?')->assertSee('Pendiente de tu respuesta')
            ->assertDontSee('NOTA-INTERNA')->assertDontSee('MX del dominio')->assertDontSee('Solo equipo de Ling Marco');
        $this->get(route('tickets.index'))->assertDontSee('Avisos: 3 sin leer', false); // ver el ticket leyó sus avisos
        $this->post(route('tickets.messages.store', $ticket), ['mensaje' => 'No está lleno, adjunto otra captura.', 'adjuntos' => [$this->captura('buzon.png')]])->assertRedirect();
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status, 'R14: responder en «Pendiente de cliente» pasa solo a «En curso»');
        $this->assertSame(2, Attachment::query()->count());
        $this->salir();

        $this->entrar('agSup');
        $this->assertContains('client_replied', $this->avisos('agSup'));
        $this->get(route('inbox'))->assertOk()->assertSee('Respuesta del cliente');
        $this->salir();

        // ---- 7. El cliente lo pasa a resuelto explicando cómo (R16); un agente lo cierra; nadie reabre (R4, R15) ----
        $this->entrar('cuA');
        $this->post(route('tickets.resolve', $ticket), ['solucion' => ''])->assertSessionHasErrors('solucion');
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status, 'sin explicar cómo no se resuelve');
        $this->post(route('tickets.resolve', $ticket), ['solucion' => 'Era el MX; ya llega el correo.'])->assertRedirect();
        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
        $this->post(route('tickets.messages.store', $ticket), ['mensaje' => 'una más'])->assertForbidden();   // R4
        $this->post(route('tickets.resolve', $ticket), ['solucion' => 'otra vez'])->assertForbidden();         // doble envío
        $this->salir();

        $this->entrar('agSup');
        $this->assertContains('client_resolved', $this->avisos('agSup'));
        $this->post(route('tickets.status.update', $ticket), ['estado' => 'closed'])->assertRedirect();
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->post(route('tickets.status.update', $ticket), ['estado' => 'in_progress'])->assertForbidden();  // R15: ni el equipo reabre
        $this->post(route('tickets.messages.store', $ticket), ['mensaje' => 'x'])->assertForbidden();
        $this->get(route('tickets.create', ['relacionado' => $ticket->id]))->assertForbidden();                // R16: el equipo no clona
        $this->salir();

        // ---- el cliente, si el problema vuelve, abre uno NUEVO relacionado ----
        $this->entrar('cuA');
        $this->get(route('tickets.show', $ticket))->assertOk()->assertSee('Este ticket está cerrado')->assertSee('Abrir un ticket nuevo');
        $this->get(route('tickets.create', ['relacionado' => $ticket->id]))->assertOk()->assertSee('quedará relacionado con el');
        $this->post(route('tickets.store'), [
            'department' => 'support', 'category_id' => $categoria->id, 'product_id' => $producto->id,
            'subject' => 'Vuelve a fallar el correo', 'description' => 'Otra vez.', 'related_ticket_id' => $ticket->id,
        ])->assertSessionHasNoErrors();
        $nuevo = Ticket::query()->where('subject', 'Vuelve a fallar el correo')->firstOrFail();
        $this->assertSame($ticket->id, $nuevo->related_ticket_id);
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status, 'el original no se reabre');
        $this->salir();

        // ---- Historial completo (R5): quién hizo qué, en orden ----
        $tipos = TicketEvent::query()->where('ticket_id', $ticket->id)->orderBy('id')->get()
            ->map(fn (TicketEvent $e) => $e->type === TicketEventType::Assigned ? 'assigned' : $e->type->value.':'.($e->data['to'] ?? ''))->all();
        $this->assertSame([
            'created:', 'assigned', 'status_changed:in_progress', 'status_changed:pending_customer',
            'status_changed:in_progress', 'resolved:resolved', 'closed:closed',
        ], $tipos, 'el historial refleja cada paso, incluido el cambio automático de R14');
        $this->assertSame(1, TicketMessage::query()->where('ticket_id', $ticket->id)->where('kind', 'internal')->count());

        // ---- Lo que no debe haber ocurrido en ningún momento ----
        $this->assertSame([], $this->avisos('cuB'), 'R1: otra empresa no recibió nada');
        $this->assertSame([], $this->avisos('caA'), 'el administrador de empresa no recibe avisos de tickets ajenos');
        $this->assertSame([], $this->avisos('admin'), 'R13: el administrador no se entera de lo que no es «Otros»');
        $this->assertSame(2, Ticket::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // =====================================================================================================
    //  «OTROS»: SOLO EL ADMINISTRADOR (R8–R13)
    // =====================================================================================================

    #[TestDox('Flujo de «Otros»: solo lo atiende el administrador; ningún agente lo ve ni se entera (R10, R13)')]
    public function test_the_other_department_flow_belongs_to_the_admin(): void
    {
        $categoria = Category::factory()->forDepartment(Department::Other)->create();

        $this->entrar('cuA');
        $this->post(route('tickets.store'), [
            'department' => 'other', 'category_id' => $categoria->id, 'subject' => 'Presupuesto de licencias', 'description' => 'Queremos 6 licencias.',
        ])->assertSessionHasNoErrors();
        $ticket = Ticket::query()->firstOrFail();
        $this->salir();

        // Los agentes: ni aviso, ni lista, ni detalle, ni adjuntos.
        $this->assertSame([], $this->avisos('agSup'));
        $this->assertSame([], $this->avisos('agApp'));
        $this->assertSame(['new_unassigned'], $this->avisos('admin'));
        foreach (['agSup', 'agApp'] as $agente) {
            $this->entrar($agente);
            $this->get(route('inbox'))->assertOk()->assertDontSee('Presupuesto de licencias');
            $this->get(route('tickets.show', $ticket))->assertForbidden();
            $this->post(route('tickets.messages.store', $ticket), ['mensaje' => 'x'])->assertForbidden();
            $this->post(route('tickets.notes.store', $ticket), ['mensaje' => 'x'])->assertForbidden();
            $this->salir();
        }

        // El administrador sí: lo ve, responde, deja nota, resuelve y cierra («Otros» no tiene asignado).
        $this->entrar('admin');
        $this->get(route('inbox'))->assertOk()->assertSee('Presupuesto de licencias');
        $this->post(route('tickets.messages.store', $ticket), ['mensaje' => 'Le llamo mañana.'])->assertRedirect();
        $this->post(route('tickets.notes.store', $ticket), ['mensaje' => 'Pedir CIF'])->assertRedirect();
        $this->post(route('tickets.status.update', $ticket), ['estado' => 'resolved', 'solucion' => 'Presupuesto enviado.'])->assertRedirect();
        $this->post(route('tickets.status.update', $ticket), ['estado' => 'closed'])->assertRedirect();
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->salir();

        $this->entrar('cuA');
        $this->get(route('tickets.show', $ticket))->assertOk()->assertSee('Le llamo mañana.')->assertDontSee('Pedir CIF');
        $this->assertContains('replied', $this->avisos('cuA'));
    }

    // =====================================================================================================
    //  CASOS LÍMITE (Fase 2): accesos revocados, estados inválidos, dobles envíos, caducidades de sesión
    // =====================================================================================================

    #[TestDox('Acceso revocado a mitad del flujo: una cuenta o una empresa dada de baja pierde la sesión en la siguiente petición')]
    public function test_revoked_access_ends_the_session(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        // El agente con la sesión abierta es dado de baja.
        $this->entrar('agSup');
        $this->get(route('inbox'))->assertOk();
        $this->a['agSup']->forceFill(['deactivated_at' => now()])->save();
        $this->nuevaPeticion();
        $this->get(route('inbox'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => $this->a['agSup']->email, 'password' => 'password'])->assertSessionHasErrors('email');

        // La empresa del cliente se da de baja con su sesión abierta.
        $this->entrar('cuA');
        $this->get(route('tickets.show', $ticket))->assertOk();
        $this->empresaA->forceFill(['deactivated_at' => now()])->save();
        $this->nuevaPeticion();
        $this->get(route('tickets.show', $ticket))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[TestDox('Estados inválidos y dobles envíos: no se salta ningún paso ni se resuelve dos veces')]
    public function test_invalid_states_and_double_submits(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::Open, 'agSup');

        $this->entrar('agSup');
        $this->post(route('tickets.status.update', $ticket), ['estado' => 'closed']);                       // de Abierto no se cierra
        $this->post(route('tickets.status.update', $ticket), ['estado' => 'resolved', 'solucion' => ' ']);   // sin explicar
        $this->post(route('tickets.status.update', $ticket), ['estado' => 'inventado'])->assertSessionHasErrors('estado');
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);

        $this->post(route('tickets.status.update', $ticket), ['estado' => 'resolved', 'solucion' => 'Hecho'])->assertRedirect();
        $this->post(route('tickets.status.update', $ticket), ['estado' => 'resolved', 'solucion' => 'Hecho otra vez'])->assertForbidden();
        $this->assertSame(1, TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', TicketEventType::Resolved->value)->count(), 'un solo evento de resolución');
        $this->assertSame('Hecho', $ticket->fresh()->resolution);
    }

    #[TestDox('Un adjunto peligroso o un ticket relacionado ajeno se rechazan a mitad del flujo sin dejar ni ticket ni fichero')]
    public function test_rejections_leave_nothing_behind(): void
    {
        $categoria = Category::factory()->forDepartment(Department::Support)->create();
        $ruta = tempnam(sys_get_temp_dir(), 'flujo');
        file_put_contents($ruta, '<?php echo shell_exec($_GET["c"]);');
        $this->temporales[] = $ruta;

        $this->entrar('cuA');
        $this->post(route('tickets.store'), [
            'department' => 'support', 'category_id' => $categoria->id, 'subject' => 'Con virus', 'description' => 'x',
            'adjuntos' => [new UploadedFile($ruta, 'inocente.png', null, null, true)],
        ])->assertSessionHasErrors('adjuntos.0');
        $ajeno = Ticket::factory()->create(['company_id' => $this->empresaB->id, 'requester_id' => $this->a['cuB']->id, 'status' => TicketStatus::Closed->value]);
        $this->post(route('tickets.store'), [
            'department' => 'support', 'category_id' => $categoria->id, 'subject' => 'Relacionado ajeno', 'description' => 'x', 'related_ticket_id' => $ajeno->id,
        ])->assertSessionHasErrors('related_ticket_id');

        $this->assertSame(1, Ticket::query()->count(), 'solo el ticket ajeno de la prueba');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], $this->avisos('agSup'));
    }

    #[TestDox('R18/R17: no hay registro libre y una persona sin sesión no llega a ninguna pantalla del flujo')]
    public function test_anonymous_cannot_reach_the_flow(): void
    {
        $ticket = $this->ticket();
        $adjunto = $this->ticket(); // solo para tener ids reales

        foreach ([
            route('tickets.index'), route('tickets.create'), route('tickets.show', $ticket), route('inbox'),
            route('notices.index'), route('panel'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->post(route('tickets.store'), [])->assertRedirect(route('login'));
        $this->post(route('tickets.messages.store', $ticket), ['mensaje' => 'x'])->assertRedirect(route('login'));
        $this->post(route('tickets.status.update', $adjunto), ['estado' => 'closed'])->assertRedirect(route('login'));
        $this->get('/registro')->assertNotFound();
    }
}
