<?php

/*
 * La raíz del sitio no tiene página propia: lleva al panel (y el middleware «auth» manda a /acceso a quien no ha
 * entrado). Sustituye al test de ejemplo del esqueleto de Laravel, que esperaba un 200 en «/» cuando existía la
 * página provisional «en construcción». La regla cambió a propósito (routes/web.php); el test sigue la regla.
 * Cuándo tocarlo: si la raíz vuelve a tener una portada real.
 */

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    #[TestDox('La raíz redirige al panel y, sin sesión, el panel manda a la pantalla de acceso')]
    public function test_the_root_redirects_to_the_panel(): void
    {
        $this->get('/')->assertRedirect('/panel');
        $this->get('/panel')->assertRedirect(route('login'));
    }
}
