<?php

/*
 * R18: NO hay registro libre. Las cuentas nacen por invitación.
 * Cuándo tocarlo: nunca para «permitir registrarse»; eso es una decisión de la usuaria.
 * Este test existe porque los kits de acceso de Laravel traen un registro público por defecto y
 * basta añadir uno sin darse cuenta para abrir la aplicación a cualquiera.
 */

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class NoRegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('No existe ninguna ruta de registro público (R18)')]
    public function test_no_registration_route_exists(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $this->assertDoesNotMatchRegularExpression('#regist|signup|sign-up|alta-usuario#i', $ruta->uri(), 'ruta de registro: '.$ruta->uri());
            $this->assertDoesNotMatchRegularExpression('#regist|signup#i', (string) $ruta->getName(), 'ruta de registro: '.$ruta->getName());
        }
    }

    #[TestDox('Las direcciones habituales de registro dan «no encontrado»')]
    public function test_common_registration_urls_are_not_found(): void
    {
        foreach (['/registro', '/register', '/registrarse', '/signup'] as $url) {
            $this->get($url)->assertNotFound();
            $this->post($url, ['name' => 'X', 'email' => 'x@example.test', 'password' => 'una frase larga'])->assertNotFound();
        }
    }

    #[TestDox('La pantalla de acceso no ofrece crear una cuenta')]
    public function test_login_page_does_not_offer_to_register(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('Regístrate')->assertDontSee('Crear cuenta')->assertSee('no hay registro libre');
    }
}
