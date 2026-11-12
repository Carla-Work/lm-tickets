<?php

/*
 * La cuarentena del correo entrante y sus ajustes (P1; mockups correo-cuarentena-a/b y admin-catalogo-c, validados 2026-11-12).
 * Cuándo tocarlo: al cambiar quién la ve o qué puede decidir. Lo que de verdad vigila:
 *  - SOLO el administrador la ve y decide (los agentes y los clientes reciben 403; comercial@ es de Contabilidad, R13);
 *  - el texto del remitente se pinta escapado; el administrador invita (R18) o crea el ticket, nunca responde al remitente (R10);
 *  - crear el ticket reutiliza CreateTicket: departamento del buzón, asignado que pueda atenderlo, adjuntos al ticket, correo vaciado;
 *  - palabras clave normalizadas y únicas; dominios gratuitos o del equipo no se pueden registrar (solo son una pista).
 * Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Inbound;

use App\Actions\Inbound\ProcessIncomingEmail;
use App\Enums\TicketChannel;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CompanyDomain;
use App\Models\InboundEmail;
use App\Models\IncidentWord;
use App\Models\Product;
use App\Models\ProductKeyword;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\BuildsIncomingEmails;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class QuarantineTest extends TestCase
{
    use BuildsIncomingEmails, CreatesActors, RefreshDatabase;

    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
        Storage::fake(Attachment::DISK);
        Mail::fake();
        Notification::fake();
        $this->producto = Product::factory()->create(['name' => 'oneCOP']);
        $this->empresaA->products()->attach($this->producto);
        (new ProductKeyword)->forceFill(['product_id' => $this->producto->id, 'keyword' => 'fichaje'])->save();
        $this->empresaA->domains()->create(['domain' => 'empresa-a.test']);
    }

    private function como(string $quien): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->a[$quien]);
    }

    private function pendiente(array $mail = [], string $buzon = 'apps'): InboundEmail
    {
        return app(ProcessIncomingEmail::class)($this->incoming($mail + ['from' => 'nuevo@empresa-a.test', 'fromName' => 'Luis Nuevo', 'subject' => 'Problema con el fichaje', 'text' => 'No podemos fichar']), $this->mailbox($buzon));
    }

    private function categoria(string $departamento = 'web_apps'): Category
    {
        // La migración ya crea «Sin clasificar» en cada departamento (los tickets por correo la usan).
        return Category::query()->where('department', $departamento)->where('name', 'Sin clasificar')->firstOrFail();
    }

    // ---------------------------------------------------------------- quién

    #[TestDox('Solo el administrador ve la cuarentena y decide: agentes (también el de Contabilidad) y clientes reciben 403 en la lista, el detalle y cada acción')]
    public function test_only_the_admin_gets_in(): void
    {
        $correo = $this->pendiente();

        foreach (['agSup', 'agApp', 'agCon', 'cuA', 'caA'] as $quien) {
            $this->como($quien)->get(route('admin.inbound.index'))->assertForbidden();
            $this->como($quien)->get(route('admin.inbound.show', $correo))->assertForbidden();
            foreach (['spam', 'no-spam'] as $accion) {
                $this->como($quien)->post(route('admin.inbound.'.$accion === 'admin.inbound.spam' ? 'admin.inbound.spam' : 'admin.inbound.not-spam', $correo))->assertForbidden();
            }
            $this->como($quien)->post(route('admin.inbound.ticket', $correo), ['solicitante' => $this->a['cuA']->id])->assertForbidden();
            $this->como($quien)->post(route('admin.inbound.invite', $correo), ['name' => 'X'])->assertForbidden();
        }
        $this->assertSame(InboundEmail::PENDING, $correo->fresh()->status);

        $this->como('admin')->get(route('admin.inbound.index'))->assertOk()->assertSee('Problema con el fichaje');
    }

    #[TestDox('La matriz de InboundEmailPolicy: solo el administrador ve y gestiona; decidir solo vale en cuarentena')]
    public function test_the_policy_matrix(): void
    {
        $pendiente = $this->pendiente();
        $ticket = (new InboundEmail)->forceFill(['status' => InboundEmail::TICKET]);

        foreach ($this->a as $clave => $usuario) {
            $admin = $clave === 'admin';
            $this->assertSame($admin, $usuario->can('viewAny', InboundEmail::class), "$clave viewAny");
            $this->assertSame($admin, $usuario->can('view', $pendiente), "$clave view");
            $this->assertSame($admin, $usuario->can('decide', $pendiente), "$clave decide");
            $this->assertSame($admin, $usuario->can('manageSettings', InboundEmail::class), "$clave manageSettings");
        }
        $this->assertFalse($this->a['admin']->can('decide', $ticket), 'un correo ya convertido en ticket no se decide');
    }

    #[TestDox('El menú «Correo entrante» sale solo para el administrador, con el contador de pendientes')]
    public function test_the_menu_entry(): void
    {
        $this->pendiente();

        $this->como('admin')->get(route('inbox'))->assertOk()->assertSee('href="'.route('admin.inbound.index').'"', false);
        $this->como('agApp')->get(route('inbox'))->assertOk()->assertDontSee(route('admin.inbound.index'), false);
    }

    // ---------------------------------------------------------------- pantallas

    #[TestDox('Lo que escribe el remitente (asunto, nombre, texto, motivos) se pinta ESCAPADO en la lista y el detalle: nunca HTML ni scripts')]
    public function test_sender_text_is_escaped(): void
    {
        $correo = $this->pendiente(['fromName' => '<b>Hacker</b>', 'subject' => '<script>alert(1)</script> fichaje', 'text' => '<img src=x onerror=alert(2)> fichaje']);

        foreach ([route('admin.inbound.index'), route('admin.inbound.show', $correo)] as $url) {
            $html = $this->como('admin')->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringNotContainsString('<b>Hacker</b>', $html);
        }
    }

    #[TestDox('Marcar spam y «No es spam» cambian el estado, quedan en la auditoría y «No es spam» devuelve el correo a pendientes')]
    public function test_spam_and_not_spam(): void
    {
        $correo = $this->pendiente();

        $this->como('admin')->post(route('admin.inbound.spam', $correo))->assertRedirect(route('admin.inbound.index'));
        $this->assertSame(InboundEmail::SPAM, $correo->fresh()->status);
        $this->assertSame($this->a['admin']->id, $correo->fresh()->decided_by);
        $this->assertTrue(AuditLog::query()->where('event', 'inbound_spam')->exists());

        $this->como('admin')->post(route('admin.inbound.not-spam', $correo))->assertRedirect();
        $this->assertSame(InboundEmail::PENDING, $correo->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('event', 'inbound_not_spam')->exists());
    }

    // ---------------------------------------------------------------- invitar

    #[TestDox('R18: invitar al remitente crea una cuenta SIN activar (solo por invitación) en la empresa elegida; el correo sigue pendiente y no se crea ticket solo')]
    public function test_invite_creates_only_an_invitation(): void
    {
        $correo = $this->pendiente();

        $this->como('admin')->post(route('admin.inbound.invite', $correo), ['name' => 'Luis Nuevo', 'company_id' => $this->empresaA->id, 'role' => 'company_user'])->assertSessionHasNoErrors();

        $usuario = User::query()->where('email', 'nuevo@empresa-a.test')->sole();
        $this->assertNull($usuario->activated_at, 'no entra hasta aceptar la invitación');
        $this->assertSame($this->empresaA->id, $usuario->company_id);
        Notification::assertSentTo($usuario, InvitationNotification::class);
        $this->assertSame(InboundEmail::PENDING, $correo->fresh()->status);
        $this->assertSame(0, Ticket::query()->count());
    }

    #[TestDox('No se invita a una dirección que ya tiene cuenta (de baja o no, error genérico) ni a una del propio equipo; el correo a invitar es siempre el del remitente')]
    public function test_invite_guards(): void
    {
        $existente = $this->pendiente(['from' => $this->a['agApp']->email, 'messageId' => 'a@x']);
        $this->como('admin')->post(route('admin.inbound.invite', $existente), ['name' => 'X', 'company_id' => $this->empresaA->id, 'role' => 'company_user'])->assertSessionHasErrors('remitente');

        $baja = User::factory()->forCompany($this->empresaA)->deactivated()->create();
        $deBaja = $this->pendiente(['from' => $baja->email, 'messageId' => 'b@x', 'text' => 'fichaje']);
        $this->como('admin')->post(route('admin.inbound.invite', $deBaja), ['name' => 'X', 'company_id' => $this->empresaA->id, 'role' => 'company_user'])->assertSessionHasErrors('remitente');

        $correo = $this->pendiente(['messageId' => 'c@x']);
        $this->como('admin')->post(route('admin.inbound.invite', $correo), ['name' => 'X', 'company_id' => $this->empresaA->id, 'role' => 'admin', 'email' => 'otro@x.test'])->assertSessionHasErrors('role');
        $this->como('admin')->post(route('admin.inbound.invite', $correo), ['name' => 'X', 'company_id' => 999999, 'role' => 'company_user'])->assertSessionHasErrors('company_id');
        $this->assertNull(User::query()->where('email', 'otro@x.test')->first());
    }

    // ---------------------------------------------------------------- crear ticket

    #[TestDox('Crear el ticket desde la cuarentena: a nombre de la persona elegida, departamento del BUZÓN, canal Correo, asignado, con el Message-ID (las respuestas entrarán en el hilo), adjuntos pasados al ticket y el correo vaciado')]
    public function test_create_ticket_from_quarantine(): void
    {
        $cat = $this->categoria();
        $correo = $this->pendiente(['attachments' => [$this->incomingAttachment('captura.png', $this->png())], 'messageId' => 'orig@cliente.es']);
        $this->assertSame(1, $correo->attachments()->count());
        $ruta = $correo->attachments()->first()->path;

        $this->como('admin')->post(route('admin.inbound.ticket', $correo), [
            'solicitante' => $this->a['cuA']->id, 'category_id' => $cat->id, 'product_id' => $this->producto->id, 'asignado' => $this->a['agApp']->id, 'notificar' => '1',
        ])->assertSessionHasNoErrors();

        $ticket = Ticket::query()->sole();
        $this->assertSame(TicketChannel::Email, $ticket->channel);
        $this->assertSame('web_apps', $ticket->department->value);
        $this->assertSame($this->a['cuA']->id, $ticket->requester_id);
        $this->assertSame($this->a['admin']->id, $ticket->created_by_id);
        $this->assertSame($this->a['agApp']->id, $ticket->assigned_to);
        $this->assertSame('orig@cliente.es', $ticket->messages()->sole()->email_message_id);
        $this->assertSame('No podemos fichar', $ticket->messages()->sole()->body);
        $this->assertSame(['captura.png'], Attachment::query()->pluck('original_name')->all());

        $correo->refresh();
        $this->assertSame(InboundEmail::TICKET, $correo->status);
        $this->assertSame($ticket->id, $correo->ticket_id);
        $this->assertNull($correo->body);
        $this->assertSame(0, $correo->attachments()->count());
        Storage::disk(Attachment::DISK)->assertMissing($ruta);
        $this->assertTrue(AuditLog::query()->where('event', 'inbound_ticket')->exists());

        // Ya no se puede decidir otra vez (no es cuarentena).
        $this->como('admin')->post(route('admin.inbound.spam', $correo))->assertForbidden();
    }

    #[TestDox('R13: un correo de comercial@ es de Contabilidad: solo se asigna a un agente de Contabilidad y la categoría debe ser de ese departamento; el administrador no atiende (no puede ser el asignado)')]
    public function test_accounting_mail_needs_an_accounting_assignee(): void
    {
        $catConta = $this->categoria('other');
        $catApps = $this->categoria('web_apps');
        $correo = $this->pendiente(['text' => 'fichaje'], 'accounting');
        $base = ['solicitante' => $this->a['cuA']->id, 'product_id' => $this->producto->id];

        $this->como('admin')->post(route('admin.inbound.ticket', $correo), $base + ['category_id' => $catConta->id, 'asignado' => $this->a['agApp']->id])->assertSessionHasErrors('asignado');
        $this->como('admin')->post(route('admin.inbound.ticket', $correo), $base + ['category_id' => $catConta->id, 'asignado' => $this->a['admin']->id])->assertSessionHasErrors('asignado');
        $this->como('admin')->post(route('admin.inbound.ticket', $correo), $base + ['category_id' => $catApps->id, 'asignado' => $this->a['agCon']->id])->assertSessionHasErrors('category_id');
        $this->assertSame(0, Ticket::query()->count());

        $this->como('admin')->post(route('admin.inbound.ticket', $correo), $base + ['category_id' => $catConta->id, 'asignado' => $this->a['agCon']->id])->assertSessionHasNoErrors();
        $this->assertSame('other', Ticket::query()->sole()->department->value);
        $this->assertSame(0, Ticket::query()->visibleTo($this->a['agApp'])->count(), 'los demás agentes no ven Contabilidad');
    }

    #[TestDox('Si no hay ningún agente que atienda el departamento del buzón, el detalle lo explica y no deja crear el ticket')]
    public function test_no_agent_for_the_department_is_explained(): void
    {
        $this->a['agCon']->forceFill(['deactivated_at' => now()])->save();
        $correo = $this->pendiente(['text' => 'fichaje'], 'accounting');

        $html = $this->como('admin')->get(route('admin.inbound.show', $correo))->assertOk()->getContent();

        $this->assertStringContainsString('No hay ningún agente activo que atienda Contabilidad', $html);
        $this->assertMatchesRegularExpression('/<button type="submit" class="boton secundario"[^>]*disabled/', $html);
    }

    #[TestDox('R6: el producto debe ser de la empresa de la persona elegida y la persona una cuenta activa de empresa activa; si no, no se crea nada')]
    public function test_r6_and_the_person_are_validated(): void
    {
        $cat = $this->categoria();
        $correo = $this->pendiente();
        $ajeno = Product::factory()->create();
        $this->empresaB->products()->attach($ajeno);
        $baja = User::factory()->forCompany($this->empresaA)->deactivated()->create();
        $base = ['category_id' => $cat->id, 'asignado' => $this->a['agApp']->id];

        $this->como('admin')->post(route('admin.inbound.ticket', $correo), $base + ['solicitante' => $this->a['cuA']->id, 'product_id' => $ajeno->id])->assertSessionHasErrors('product_id');
        $this->como('admin')->post(route('admin.inbound.ticket', $correo), $base + ['solicitante' => $baja->id, 'product_id' => $this->producto->id])->assertSessionHasErrors('solicitante');
        $this->como('admin')->post(route('admin.inbound.ticket', $correo), $base + ['solicitante' => $this->a['agApp']->id, 'product_id' => $this->producto->id])->assertSessionHasErrors('solicitante');
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(InboundEmail::PENDING, $correo->fresh()->status);
    }

    // ---------------------------------------------------------------- ajustes

    #[TestDox('Palabras clave: se guardan normalizadas, deben tener 3 caracteres o más, no se repiten (cada una es de UN producto) y quitar una la borra; solo el administrador')]
    public function test_keywords(): void
    {
        $otro = Product::factory()->create(['name' => 'oneFact']);

        $this->como('admin')->post(route('admin.inbound.keywords.store', $otro), ['palabra' => '  FACTURACIÓN  '])->assertSessionHasNoErrors();
        $this->assertSame('facturacion', ProductKeyword::query()->where('product_id', $otro->id)->sole()->keyword);

        $this->como('admin')->post(route('admin.inbound.keywords.store', $this->producto), ['palabra' => 'facturacion'])->assertSessionHasErrors('palabra');
        $this->como('admin')->post(route('admin.inbound.keywords.store', $this->producto), ['palabra' => 'ab'])->assertSessionHasErrors('palabra');
        $this->como('agApp')->post(route('admin.inbound.keywords.store', $this->producto), ['palabra' => 'nueva'])->assertForbidden();

        $palabra = ProductKeyword::query()->where('keyword', 'facturacion')->sole();
        $this->como('agApp')->post(route('admin.inbound.keywords.destroy', $palabra))->assertForbidden();
        $this->como('admin')->post(route('admin.inbound.keywords.destroy', $palabra))->assertRedirect();
        $this->assertSame(0, ProductKeyword::query()->where('keyword', 'facturacion')->count());
        $this->assertNotNull(Product::query()->find($otro->id), 'el producto no se toca');
    }

    #[TestDox('Palabras de incidencia: se añaden normalizadas, sin repetir, y se quitan')]
    public function test_incident_words(): void
    {
        $this->como('admin')->post(route('admin.inbound.words.store'), ['palabra' => 'Se ha CAÍDO'])->assertSessionHasNoErrors();
        $this->assertTrue(IncidentWord::query()->where('word', 'se ha caido')->exists());
        $this->como('admin')->post(route('admin.inbound.words.store'), ['palabra' => 'se ha caido'])->assertSessionHasErrors('palabra');

        $this->como('admin')->post(route('admin.inbound.words.destroy', IncidentWord::query()->where('word', 'se ha caido')->sole()))->assertRedirect();
        $this->assertSame(0, IncidentWord::query()->count());
    }

    #[TestDox('Dominios: se normalizan y validan; NO se aceptan los de correo gratuito ni el del equipo ni uno repetido; quitar uno de otra empresa da 404; solo el administrador')]
    public function test_company_domains(): void
    {
        $this->como('admin')->post(route('admin.inbound.domains.store', $this->empresaB), ['dominio' => ' @WWW.Empresa-B.test '])->assertSessionHasNoErrors();
        $this->assertTrue($this->empresaB->domains()->where('domain', 'empresa-b.test')->exists());

        foreach (['gmail.com', 'hotmail.es', 'lingmarco.es', 'empresa-a.test', 'no es un dominio', 'sinpunto'] as $malo) {
            $this->como('admin')->post(route('admin.inbound.domains.store', $this->empresaB), ['dominio' => $malo])->assertSessionHasErrors('dominio');
        }
        $this->como('agApp')->post(route('admin.inbound.domains.store', $this->empresaB), ['dominio' => 'otro.test'])->assertForbidden();

        $ajeno = CompanyDomain::query()->where('domain', 'empresa-a.test')->sole();
        $this->como('admin')->post(route('admin.inbound.domains.destroy', [$this->empresaB, $ajeno]))->assertNotFound();
        $this->como('admin')->post(route('admin.inbound.domains.destroy', [$this->empresaA, $ajeno]))->assertRedirect();
        $this->assertSame(0, CompanyDomain::query()->where('domain', 'empresa-a.test')->count());
    }
}
