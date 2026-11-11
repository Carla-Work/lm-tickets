<?php

/*
 * Valoración de la atención (P1; docs/plan/p1-valoracion-atencion.md). Cuándo tocarlo: al cambiar quién valora, cuándo o qué se ve.
 * Lo que de verdad vigila:
 *  - solo quien abrió el ticket valora, con el ticket resuelto o cerrado, y una sola vez (la base lo impide también);
 *  - valorar no es un mensaje: no cambia el estado, no mueve la actividad, no avisa a nadie;
 *  - el comentario es texto libre del cliente: se escapa al pintarlo y se borra al anonimizar el ticket (la puntuación se conserva).
 * Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Tickets;

use App\Console\Commands\RunRetention;
use App\Enums\Department;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketRating;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class TicketRatingTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    private function como(string $quien): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->a[$quien]);
    }

    private function final(TicketStatus $estado = TicketStatus::Closed, Department $dep = Department::Support, array $extra = []): Ticket
    {
        return Ticket::factory()->create(array_merge(['company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA']->id, 'department' => $dep->value, 'status' => $estado->value, 'subject' => 'Ticket de prueba'], $extra));
    }

    #[TestDox('Quien abrió el ticket lo valora cuando está resuelto o cerrado: queda la valoración y un evento visible al cliente (sin el comentario), SIN cambiar el estado, SIN mover la actividad y SIN avisos')]
    public function test_requester_rates_a_final_ticket(): void
    {
        Notification::fake();
        foreach ([TicketStatus::Resolved, TicketStatus::Closed] as $estado) {
            $ticket = $this->final($estado, extra: ['last_activity_at' => now()->subDays(3)]);
            $antes = $ticket->fresh()->last_activity_at;

            $this->como('cuA')->post(route('tickets.rating.store', $ticket), ['puntuacion' => 4, 'comentario' => 'Rápidos y claros.'])->assertRedirect(route('tickets.show', $ticket));

            $rating = TicketRating::where('ticket_id', $ticket->id)->firstOrFail();
            $this->assertSame(4, $rating->score);
            $this->assertSame('Rápidos y claros.', $rating->comment);
            $this->assertSame($this->a['cuA']->id, $rating->rated_by);
            $this->assertSame($estado, $ticket->fresh()->status, 'valorar no cambia el estado');
            $this->assertEquals($antes, $ticket->fresh()->last_activity_at, 'valorar no mueve la actividad (reordenaría la lista)');

            $evento = TicketEvent::where('ticket_id', $ticket->id)->where('type', TicketEventType::Rated->value)->firstOrFail();
            $this->assertEquals(['score' => 4], $evento->data, 'el historial no guarda el texto libre');
            $this->assertTrue($evento->visible_to_customer);
        }
        Notification::assertNothingSent();
    }

    #[TestDox('El comentario es opcional: vacío o solo espacios se guarda como nulo')]
    public function test_comment_is_optional(): void
    {
        $a = $this->final();
        $b = $this->final(extra: ['requester_id' => $this->a['cuA']->id]);

        $this->como('cuA')->post(route('tickets.rating.store', $a), ['puntuacion' => 5])->assertRedirect();
        $this->como('cuA')->post(route('tickets.rating.store', $b), ['puntuacion' => 3, 'comentario' => "   \n "])->assertRedirect();

        $this->assertNull(TicketRating::where('ticket_id', $a->id)->firstOrFail()->comment);
        $this->assertNull(TicketRating::where('ticket_id', $b->id)->firstOrFail()->comment);
    }

    #[TestDox('Solo con el ticket resuelto o cerrado: abierto, en curso o pendiente no se valora (403)')]
    public function test_only_final_tickets(): void
    {
        foreach ([TicketStatus::Open, TicketStatus::InProgress, TicketStatus::PendingCustomer] as $estado) {
            $ticket = $this->final($estado);
            $this->como('cuA')->post(route('tickets.rating.store', $ticket), ['puntuacion' => 5])->assertForbidden();
            $this->como('cuA')->get(route('tickets.show', $ticket))->assertOk()->assertDontSee('Enviar valoración');
        }
        $this->assertSame(0, TicketRating::count());
    }

    #[TestDox('Solo quien abrió el ticket valora: ni su administrador de empresa, ni un compañero, ni otra empresa, ni el equipo, ni el administrador (403, sin mensajes de validación aunque los datos sean inválidos)')]
    public function test_only_the_requester(): void
    {
        $ticket = $this->final();

        foreach (['caA', 'cuA2', 'cuB', 'caB', 'agSup', 'agApp', 'agCon', 'admin'] as $quien) {
            $this->como($quien)->post(route('tickets.rating.store', $ticket), ['puntuacion' => 99])->assertForbidden();
            $this->como($quien)->post(route('tickets.rating.store', $ticket), ['puntuacion' => 5])->assertForbidden();
        }
        $this->assertSame(0, TicketRating::count());

        // El administrador de empresa no ve el formulario (no es quien lo abrió).
        $this->como('caA')->get(route('tickets.show', $ticket))->assertOk()->assertDontSee('Enviar valoración');
    }

    #[TestDox('Una sola vez: la segunda valoración es un error claro y se queda la primera; la base también lo impide')]
    public function test_only_once(): void
    {
        $ticket = $this->final();

        $this->como('cuA')->post(route('tickets.rating.store', $ticket), ['puntuacion' => 2, 'comentario' => 'primera'])->assertRedirect();
        $this->como('cuA')->post(route('tickets.rating.store', $ticket), ['puntuacion' => 5, 'comentario' => 'segunda'])->assertSessionHasErrors('puntuacion');

        $this->assertSame(1, TicketRating::where('ticket_id', $ticket->id)->count());
        $this->assertSame(2, TicketRating::where('ticket_id', $ticket->id)->firstOrFail()->score);
        $this->assertSame(1, TicketEvent::where('type', TicketEventType::Rated->value)->count());
        $this->como('cuA')->get(route('tickets.show', $ticket))->assertDontSee('Enviar valoración');

        $this->expectException(QueryException::class);
        DB::table('ticket_ratings')->insert(['ticket_id' => $ticket->id, 'rated_by' => $this->a['cuA']->id, 'score' => 3]);
    }

    #[TestDox('Puntuación fuera de 1-5 o ausente, y comentario de más de 500 caracteres, se rechazan; la base rechaza una puntuación fuera de rango')]
    public function test_validation(): void
    {
        $ticket = $this->final();

        foreach ([0, 6, -1, 'mala', ''] as $mala) {
            $this->como('cuA')->post(route('tickets.rating.store', $ticket), ['puntuacion' => $mala])->assertSessionHasErrors('puntuacion');
        }
        $this->como('cuA')->post(route('tickets.rating.store', $ticket), ['comentario' => 'sin nota'])->assertSessionHasErrors('puntuacion');
        $this->como('cuA')->post(route('tickets.rating.store', $ticket), ['puntuacion' => 3, 'comentario' => str_repeat('x', 501)])->assertSessionHasErrors('comentario');
        $this->assertSame(0, TicketRating::count());

        $this->como('cuA')->post(route('tickets.rating.store', $ticket), ['puntuacion' => 3, 'comentario' => str_repeat('x', 500)])->assertRedirect();
        $this->assertSame(500, mb_strlen((string) TicketRating::firstOrFail()->comment));

        $otro = $this->final();
        $this->expectException(QueryException::class);
        DB::table('ticket_ratings')->insert(['ticket_id' => $otro->id, 'rated_by' => $this->a['cuA']->id, 'score' => 6]);
    }

    #[TestDox('El comentario se escapa donde se pinte (cliente y equipo): un comentario con HTML no se ejecuta')]
    public function test_comment_is_escaped(): void
    {
        $ticket = $this->final();
        $this->como('cuA')->post(route('tickets.rating.store', $ticket), ['puntuacion' => 1, 'comentario' => '<script>alert(1)</script><b>mal</b>']);

        foreach (['cuA', 'caA', 'agSup', 'admin'] as $quien) {
            $html = (string) $this->como($quien)->get(route('tickets.show', $ticket))->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html, "$quien: el comentario se pinta sin escapar");
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, "$quien: debería ver el comentario escapado");
        }
    }

    #[TestDox('Quién ve qué: el solicitante ve «Gracias por tu valoración»; su administrador de empresa ve la valoración con quién la hizo, sin formulario; el equipo la ve en su detalle; el de Contabilidad no ve la de Soporte')]
    public function test_who_sees_the_rating(): void
    {
        $ticket = $this->final();
        $this->como('cuA')->post(route('tickets.rating.store', $ticket), ['puntuacion' => 4, 'comentario' => 'Todo bien']);

        $this->como('cuA')->get(route('tickets.show', $ticket))->assertOk()->assertSee('Gracias por tu valoración')->assertSee('★★★★☆')->assertSee('Todo bien')->assertSee('Valoraste la atención', false);
        $this->como('caA')->get(route('tickets.show', $ticket))->assertOk()->assertSee('Valoración de la atención')->assertSee('De '.$this->a['cuA']->name)->assertDontSee('Enviar valoración');
        $this->como('agSup')->get(route('tickets.show', $ticket))->assertOk()->assertSee('Valoración de la atención')->assertSee('4/5')->assertSee('Todo bien')->assertSee('valoró la atención');
        $this->como('admin')->get(route('tickets.show', $ticket))->assertOk()->assertSee('4/5');
        $this->como('agCon')->get(route('tickets.show', $ticket))->assertForbidden();
        $this->como('cuB')->get(route('tickets.show', $ticket))->assertForbidden();
    }

    #[TestDox('Bandeja: la puntuación junto al estado, filtro «Valoración» (mala, normal, buena, valoradas, sin valorar), vista rápida «Mal valorados»; el cliente no puede filtrar por valoración')]
    public function test_inbox_filter(): void
    {
        $mala = $this->final(extra: ['subject' => 'Mala']);
        $media = $this->final(extra: ['subject' => 'Media']);
        $buena = $this->final(extra: ['subject' => 'Buena']);
        $this->final(extra: ['subject' => 'Sin valorar']);
        foreach ([[$mala, 1], [$media, 3], [$buena, 5]] as [$t, $n]) {
            $this->como('cuA')->post(route('tickets.rating.store', $t), ['puntuacion' => $n]);
        }

        $asuntos = function (string $quien, array $f, bool $equipo = true): array {
            $html = $this->como($quien)->get(route($equipo ? 'inbox' : 'tickets.index', $f))->assertOk()->getContent();
            preg_match_all('#class="asunto" href="[^"]+">([^<]+)</a>#', (string) $html, $m);

            return $m[1];
        };

        $this->assertSame(['Mala'], $asuntos('agSup', ['valoracion' => 'low']));
        $this->assertSame(['Media'], $asuntos('agSup', ['valoracion' => 'mid']));
        $this->assertSame(['Buena'], $asuntos('agSup', ['valoracion' => 'good']));
        $this->assertEqualsCanonicalizing(['Mala', 'Media', 'Buena'], $asuntos('agSup', ['valoracion' => 'rated']));
        $this->assertSame(['Sin valorar'], $asuntos('agSup', ['valoracion' => 'unrated']));
        $this->como('agSup')->get(route('inbox', ['valoracion' => 'rara']))->assertSessionHasErrors('valoracion');

        $html = (string) $this->como('agSup')->get(route('inbox', ['valoracion' => 'low']))->getContent();
        $this->assertStringContainsString('★ 1/5', $html);
        $this->assertMatchesRegularExpression('#aria-current="true"[^>]*>Mal valorados#', $html);

        // El cliente no tiene ese filtro: se ignora y ve su lista completa.
        $this->assertCount(4, $asuntos('cuA', ['valoracion' => 'low'], equipo: false));
        // Un agente de otro equipo no obtiene la valoración de un ticket que no ve (R13).
        $conta = $this->final(TicketStatus::Closed, Department::Other, ['subject' => 'Conta mala']);
        $this->como('cuA')->post(route('tickets.rating.store', $conta), ['puntuacion' => 1]);
        $this->assertSame(['Mala'], $asuntos('agSup', ['valoracion' => 'low']));
        $this->assertSame(['Conta mala'], $asuntos('agCon', ['valoracion' => 'low']), 'el agente de Contabilidad solo ve las suyas');
    }

    #[TestDox('Al anonimizar un ticket por plazo se borra el comentario de su valoración y se conserva la puntuación')]
    public function test_retention_wipes_the_comment_keeps_the_score(): void
    {
        $viejo = $this->final(extra: ['closed_at' => now()->subYears(3)]);
        $reciente = $this->final(extra: ['closed_at' => now()->subDay()]);
        foreach ([$viejo, $reciente] as $t) {
            $this->como('cuA')->post(route('tickets.rating.store', $t), ['puntuacion' => 2, 'comentario' => 'Me llamo María y vivo en la calle X']);
        }

        $this->artisan('retention:run')->assertSuccessful();

        $v = TicketRating::where('ticket_id', $viejo->id)->firstOrFail();
        $this->assertSame(RunRetention::REPLACEMENT_BODY, $v->comment);
        $this->assertSame(2, $v->score, 'la puntuación se conserva');
        $this->assertSame('Me llamo María y vivo en la calle X', TicketRating::where('ticket_id', $reciente->id)->firstOrFail()->comment, 'un ticket reciente no se toca');
    }

    #[TestDox('Una valoración no se borra ni siquiera borrando el ticket (RESTRICT)')]
    public function test_foreign_keys_restrict(): void
    {
        $ticket = $this->final();
        $this->como('cuA')->post(route('tickets.rating.store', $ticket), ['puntuacion' => 5]);

        $this->expectException(QueryException::class);
        DB::table('tickets')->where('id', $ticket->id)->delete();
    }
}
