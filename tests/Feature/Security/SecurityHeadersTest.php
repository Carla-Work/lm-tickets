<?php

/*
 * Cabeceras de seguridad de todas las respuestas web (defecto D-1). Cuándo tocarlo: si cambia SecurityHeaders.
 * Si falla, se arregla el middleware o se discute la política: NO se adapta el test.
 */

namespace Tests\Feature\Security;

use App\Models\EmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    #[TestDox('Las pantallas públicas y las del panel prohíben que otra web las incruste y llevan nosniff y Referrer-Policy')]
    public function test_pages_carry_the_headers(): void
    {
        foreach ([$this->get(route('login')), $this->actingAs($this->a['cuA'])->get(route('tickets.index'))] as $respuesta) {
            $respuesta->assertOk()
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'")
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'same-origin');
        }
    }

    #[TestDox('HSTS solo se envía cuando la petición llegó por HTTPS')]
    public function test_hsts_only_over_https(): void
    {
        $this->get('http://localhost/acceso')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/acceso')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    #[TestDox('El registro de correos sigue mostrando el cuerpo en su iframe aislado: srcdoc con sandbox vacío')]
    public function test_mail_preview_iframe_unaffected(): void
    {
        $log = EmailLog::create(['status' => EmailLog::SENT, 'subject' => 'X', 'body' => '<p>Hola</p>']);

        $this->actingAs($this->a['admin'])->get(route('records.mail', ['ver' => $log->id]))
            ->assertOk()->assertSee('sandbox=""', false)->assertSee('srcdoc=', false);
    }
}
