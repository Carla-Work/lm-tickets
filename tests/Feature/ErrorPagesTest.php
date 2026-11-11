<?php

/*
 * Páginas de error (403, 404, 419, 429, 500, 503): en español, con la marca y SIN filtrar nada interno (defecto D-11).
 * Cuándo tocarlo: al cambiar los errores. Si falla, se arregla la vista o se discute el texto: NO se adapta el test.
 * Lo que de verdad vigila: que la página de error no enseñe el mensaje de la excepción (rutas, SQL, «This action is unauthorized»)
 * ni siquiera con APP_DEBUG activo en las que son errores HTTP, y que no dependa de la sesión ni de la base de datos.
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
        // Rutas de prueba dentro del grupo `web`, como las reales (con sesión y cookies).
        Route::middleware('web')->group(function () {
            foreach ([403, 404, 419, 429, 500, 503] as $codigo) {
                Route::get("/_error/$codigo", fn () => abort($codigo, 'MENSAJE-INTERNO-NO-DEBE-SALIR'));
            }
            Route::get('/_error/excepcion', fn () => throw new \RuntimeException('SELECT * FROM secreto-interno'));
        });
    }

    /** @return array<int, array{0: int, 1: string}> */
    public static function codigos(): array
    {
        return [
            [403, 'No tienes acceso a esta página'],
            [404, 'No encontramos esa página'],
            [419, 'La página ha caducado'],
            [429, 'Demasiados intentos'],
            [500, 'Algo ha ido mal por nuestra parte'],
            [503, 'Estamos en mantenimiento'],
        ];
    }

    #[TestDox('Cada error HTTP sale en español, con el logo y el enlace de vuelta, sin el mensaje de la excepción y sin textos en inglés (con APP_DEBUG activo también)')]
    public function test_http_errors_are_spanish_and_do_not_leak(): void
    {
        foreach ([true, false] as $debug) {
            config(['app.debug' => $debug]);
            foreach (self::codigos() as [$codigo, $titulo]) {
                $html = (string) $this->get("/_error/$codigo")->assertStatus($codigo)->assertSee($titulo)->assertSee('Volver al inicio')->assertSee('img/logo-LM.svg', false)->assertSee("Error $codigo")->getContent();

                $this->assertStringNotContainsString('MENSAJE-INTERNO-NO-DEBE-SALIR', $html, "el $codigo enseña el mensaje de la excepción (debug=".var_export($debug, true).')');
                foreach (['Not Found', 'Forbidden', 'Server Error', 'Too Many Requests', 'Page Expired', 'Service Unavailable', 'Illuminate\\', 'vendor/'] as $ingles) {
                    $this->assertStringNotContainsString($ingles, $html, "el $codigo contiene «{$ingles}»");
                }
            }
        }
    }

    #[TestDox('Una excepción no controlada (500) con APP_DEBUG desactivado enseña la página propia y NO la consulta ni el mensaje internos')]
    public function test_unhandled_exception_is_a_clean_500(): void
    {
        config(['app.debug' => false]);

        $html = (string) $this->get('/_error/excepcion')->assertStatus(500)->assertSee('Algo ha ido mal por nuestra parte')->getContent();

        $this->assertStringNotContainsString('secreto-interno', $html);
        $this->assertStringNotContainsString('SELECT', $html);
    }

    #[TestDox('Un 404 y un 403 REALES de la aplicación usan estas páginas: una ruta que no existe y un cliente entrando en la bandeja')]
    public function test_real_404_and_403(): void
    {
        $this->actingAs($this->a['cuA'])->get('/ruta-que-no-existe')->assertNotFound()->assertSee('No encontramos esa página');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->a['cuA'])->get(route('inbox'))->assertForbidden()->assertSee('No tienes acceso a esta página');
    }

    #[TestDox('La página de error no depende de la sesión ni de quien mira: sale igual sin haber iniciado sesión, y su enlace va a la raíz')]
    public function test_error_page_needs_no_session(): void
    {
        $this->get('/ruta-que-no-existe')->assertNotFound()->assertSee('No encontramos esa página')->assertSee('href="'.url('/').'"', false);
    }

    #[TestDox('Las peticiones que piden JSON siguen recibiendo JSON, no la página')]
    public function test_json_requests_still_get_json(): void
    {
        $this->getJson('/_error/404')->assertNotFound()->assertJsonMissing(['message' => 'No encontramos esa página']);
    }
}
