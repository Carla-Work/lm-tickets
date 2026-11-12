<?php

/*
 * El comando `inbound:fetch` (P1). Cuándo tocarlo: al cambiar cómo se recorren los buzones.
 * Vigila: no hace nada si está desactivado; procesa cada buzón con SU departamento; no repite lo ya procesado (el buzón no se toca);
 * un buzón que falla no impide leer los demás y devuelve error; un correo que falla no se da por procesado.
 */

namespace Tests\Feature\Inbound;

use App\Models\InboundEmail;
use App\Models\IncidentWord;
use App\Models\Ticket;
use App\Support\Inbound\MailboxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\BuildsIncomingEmails;
use Tests\Concerns\CreatesActors;
use Tests\Concerns\FakeMailboxClient;
use Tests\TestCase;

class FetchInboundEmailCommandTest extends TestCase
{
    use BuildsIncomingEmails, CreatesActors, RefreshDatabase;

    private FakeMailboxClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
        IncidentWord::create(['word' => 'error']);
        Mail::fake();
        Notification::fake();
        $this->client = new FakeMailboxClient;
        $this->app->instance(MailboxClient::class, $this->client);

        // Los tres buzones «configurados» (host, usuario y contraseña de mentira: el cliente es el falso).
        config(['inbound.enabled' => true, 'inbound.mailboxes' => array_map(
            fn (array $m) => array_merge($m, ['host' => 'imap.test', 'username' => 'u', 'password' => 'p']),
            config('inbound.mailboxes'),
        )]);
    }

    #[TestDox('Desactivado (INBOUND_ENABLED=false): no lee ningún buzón')]
    public function test_disabled_does_nothing(): void
    {
        config(['inbound.enabled' => false]);

        $this->artisan('inbound:fetch')->assertSuccessful();

        $this->assertSame(0, $this->client->calls);
    }

    #[TestDox('Lee los tres buzones y cada correo toma el departamento de SU buzón; una segunda ejecución no duplica nada')]
    public function test_it_processes_each_mailbox_once(): void
    {
        $this->client->emails = [
            'apps' => [$this->incoming(['from' => $this->a['cuA']->email, 'subject' => 'Error A'])],
            'support' => [$this->incoming(['from' => $this->a['cuA']->email, 'subject' => 'Error S'])],
            'accounting' => [$this->incoming(['from' => 'spam@x.test', 'subject' => 'Oferta', 'text' => 'Compra'])],
        ];

        $this->artisan('inbound:fetch')->assertSuccessful();
        $this->artisan('inbound:fetch')->assertSuccessful();

        $this->assertSame(6, $this->client->calls, 'tres buzones leídos en cada una de las dos ejecuciones');
        $this->assertSame(['web_apps', 'support'], Ticket::query()->orderBy('id')->get()->map(fn (Ticket $t) => $t->department->value)->all(), 'cada ticket, con el departamento de su buzón');
        $this->assertSame(2, Ticket::query()->count(), 'la segunda ejecución no duplica');
        $this->assertSame(3, InboundEmail::query()->count());
        $this->assertSame('spam', InboundEmail::query()->where('mailbox', 'accounting')->sole()->status);
    }

    #[TestDox('Un buzón que falla no impide leer los demás: se informa y el código de salida es 1')]
    public function test_a_failing_mailbox_does_not_stop_the_others(): void
    {
        $this->client->failures['apps'] = new \RuntimeException('Contraseña incorrecta');
        $this->client->emails['support'] = [$this->incoming(['from' => $this->a['cuA']->email])];

        $this->artisan('inbound:fetch')->assertFailed();

        $this->assertSame(1, Ticket::query()->count());
    }

    #[TestDox('Un buzón sin configurar (sin host, usuario o contraseña) se salta con aviso y error')]
    public function test_an_unconfigured_mailbox_is_skipped(): void
    {
        config(['inbound.mailboxes' => [array_merge(config('inbound.mailboxes.0'), ['password' => null])]]);

        $this->artisan('inbound:fetch')->assertFailed();

        $this->assertSame(0, $this->client->calls);
    }

    #[TestDox('--mailbox solo lee ese buzón')]
    public function test_the_mailbox_option(): void
    {
        $this->artisan('inbound:fetch', ['--mailbox' => 'support'])->assertSuccessful();

        $this->assertSame(1, $this->client->calls);
    }
}
