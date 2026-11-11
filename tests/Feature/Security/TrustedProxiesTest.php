<?php

/*
 * Detrás de un proxy que termina el HTTPS (TRUSTED_PROXIES → config/trustedproxy.php), la aplicación se entera de que la petición
 * era segura. Cuándo tocarlo: al cambiar cómo se declaran los proxies. Lo que vigila:
 *  - SIN proxy declarado, una cabecera X-Forwarded-Proto falsificada por un visitante NO convierte la petición en segura
 *    (si lo hiciera, cualquiera podría provocar HSTS o enlaces https engañosos);
 *  - CON el proxy declarado, X-Forwarded-Proto: https sí hace seguras las peticiones (HSTS) y los enlaces salen con https.
 * Si falla, se arregla la configuración: NO se adapta el test.
 */

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    #[TestDox('Por defecto NO se confía en ningún proxy (TRUSTED_PROXIES vacío): lo seguro hasta que alguien lo declare')]
    public function test_default_trusts_nobody(): void
    {
        $this->assertNull(config('trustedproxy.proxies'));
    }

    #[TestDox('Sin proxy declarado, un X-Forwarded-Proto falsificado NO hace segura la petición: no hay HSTS')]
    public function test_forwarded_headers_are_ignored_without_trusted_proxies(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/acceso', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '1.2.3.4'])
            ->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }

    #[TestDox('Con el proxy declarado, X-Forwarded-Proto: https hace segura la petición: HSTS y enlaces con https')]
    public function test_forwarded_proto_is_honoured_for_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.5']);

        $respuesta = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->get('/acceso', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');

        $this->assertStringContainsString('href="https://', (string) $respuesta->getContent());
    }

    /**
     * Una petición por caso, como en producción (un proceso por petición): el cliente de pruebas arrastra estado estático entre peticiones
     * seguidas DENTRO de un mismo test y daría falsos «seguros».
     *
     * @return array<string, array{string, string, bool}> proxies declarados, IP que llama, ¿debe ser segura?
     */
    public static function casos(): array
    {
        return [
            'lista: está en la lista' => ['192.168.1.1, 10.0.0.0/8', '192.168.1.1', true],
            'rango: dentro del CIDR' => ['192.168.1.1, 10.0.0.0/8', '10.2.3.4', true],
            'lista: otra IP' => ['192.168.1.1, 10.0.0.0/8', '203.0.113.9', false],
            'comodín: quien llame' => ['*', '203.0.113.9', true],
        ];
    }

    #[DataProvider('casos')]
    #[TestDox('Se pueden declarar varios proxies y rangos separados por comas, y «*» confía en quien llame')]
    public function test_lists_ranges_and_wildcard(string $proxies, string $remitente, bool $seguro): void
    {
        config(['trustedproxy.proxies' => $proxies]);

        $respuesta = $this->withServerVariables(['REMOTE_ADDR' => $remitente])->get('/acceso', ['X-Forwarded-Proto' => 'https'])->assertOk();

        $seguro
            ? $respuesta->assertHeader('Strict-Transport-Security', 'max-age=31536000')
            : $respuesta->assertHeaderMissing('Strict-Transport-Security');
    }

    #[TestDox('Un proxy distinto del declarado no es de fiar: sus cabeceras X-Forwarded-* se ignoran')]
    public function test_other_senders_are_not_trusted(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.5']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/acceso', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }
}
