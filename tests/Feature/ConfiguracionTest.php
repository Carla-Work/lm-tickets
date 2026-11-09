<?php

/*
 * Test de configuración base de la aplicación.
 *
 * Qué es: comprueba decisiones de arranque que no se ven en el código de negocio.
 * Cuándo tocarlo: solo si se cambia una de esas decisiones a propósito (p. ej. añadir un
 * segundo idioma). Si falla, NO se adapta el test: se arregla la configuración o se discute
 * la decisión con la usuaria.
 */

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class ConfiguracionTest extends TestCase
{
    #[TestDox('La interfaz está en español por defecto, también el idioma de reserva')]
    public function test_idioma_por_defecto_es_espanol(): void
    {
        // Por qué: todo lo que lee una persona (interfaz, errores, emails) va en español.
        // Si el idioma de reserva fuera inglés, un texto sin traducir saldría en inglés
        // en lugar de en español sin avisar a nadie.
        $this->assertSame('es', config('app.locale'));
        $this->assertSame('es', config('app.fallback_locale'));
    }
}
