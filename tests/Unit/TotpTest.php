<?php

/*
 * Prueba de App\Support\Totp contra los VECTORES DE PRUEBA OFICIALES de los RFC 4226 (HOTP), 6238
 * (TOTP) y 4648 (base32). Son la ÚNICA prueba de que el algoritmo es correcto.
 * Cuándo tocarlo: nunca para «hacer que pase». Si falla, está mal el algoritmo, no el test.
 *
 * Los vectores se comprobaron antes con una implementación independiente (la biblioteca estándar
 * de Python) para no fiarse de la memoria: los valores de aquí son los del RFC.
 * Se prueba sin arrancar Laravel: es una clase pura.
 */

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** La clave de los RFC: la cadena ASCII «12345678901234567890» (20 bytes). */
    private const RFC_KEY = '12345678901234567890';

    private function rfcSecret(): string
    {
        return Totp::base32Encode(self::RFC_KEY); // GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ
    }

    /** @return array<string, array{int, string}> contador => código de 6 dígitos (RFC 4226, apéndice D) */
    public static function hotpVectors(): array
    {
        $esperados = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];
        $casos = [];
        foreach ($esperados as $contador => $codigo) {
            $casos["contador $contador"] = [$contador, $codigo];
        }

        return $casos;
    }

    #[DataProvider('hotpVectors')]
    #[TestDox('RFC 4226: el contador $contador da el código $esperado')]
    public function test_matches_rfc4226_hotp_vectors(int $contador, string $esperado): void
    {
        $this->assertSame($esperado, (new Totp(6, 30))->codeForStep($this->rfcSecret(), $contador));
    }

    /** @return array<string, array{int, string}> instante => código de 8 dígitos (RFC 6238, apéndice B, SHA-1) */
    public static function totpVectors(): array
    {
        return [
            'T=59' => [59, '94287082'],
            'T=1111111109' => [1111111109, '07081804'],
            'T=1111111111' => [1111111111, '14050471'],
            'T=1234567890' => [1234567890, '89005924'],
            'T=2000000000' => [2000000000, '69279037'],
            'T=20000000000' => [20000000000, '65353130'],
        ];
    }

    #[DataProvider('totpVectors')]
    #[TestDox('RFC 6238: en el instante $instante el código de 8 dígitos es $esperado')]
    public function test_matches_rfc6238_totp_vectors(int $instante, string $esperado): void
    {
        $this->assertSame($esperado, (new Totp(8, 30))->code($this->rfcSecret(), $instante));
    }

    #[TestDox('Los códigos de 6 dígitos son los 6 últimos de los de 8 (como en el RFC)')]
    public function test_six_digit_code_is_the_tail_of_the_eight_digit_one(): void
    {
        $this->assertSame('287082', (new Totp(6, 30))->code($this->rfcSecret(), 59));
    }

    /** @return array<string, array{string, string}> */
    public static function base32Vectors(): array
    {
        return [
            'vacío' => ['', ''], 'f' => ['f', 'MY'], 'fo' => ['fo', 'MZXQ'], 'foo' => ['foo', 'MZXW6'],
            'foob' => ['foob', 'MZXW6YQ'], 'fooba' => ['fooba', 'MZXW6YTB'], 'foobar' => ['foobar', 'MZXW6YTBOI'],
        ];
    }

    #[DataProvider('base32Vectors')]
    #[TestDox('RFC 4648: «$texto» se codifica como $esperado (sin relleno) y se decodifica de vuelta')]
    public function test_base32_matches_rfc4648_vectors(string $texto, string $esperado): void
    {
        $this->assertSame($esperado, Totp::base32Encode($texto));
        $this->assertSame($texto, Totp::base32Decode($esperado));
    }

    #[TestDox('La decodificación acepta minúsculas, espacios, guiones y relleno: lo que la gente copia y pega')]
    public function test_base32_decode_is_forgiving(): void
    {
        $this->assertSame('foobar', Totp::base32Decode('mzxw 6ytb-oi======'));
    }

    #[TestDox('Un carácter que no es base32 se rechaza con una excepción')]
    public function test_base32_decode_rejects_invalid_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Totp::base32Decode('MZXW1'); // el 1 no existe en base32
    }

    #[TestDox('Una clave nueva tiene 32 caracteres base32 y dos claves nunca coinciden')]
    public function test_generated_secret_shape(): void
    {
        $uno = Totp::generateSecret();
        $dos = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $uno);
        $this->assertNotSame($uno, $dos);
        $this->assertSame(20, strlen(Totp::base32Decode($uno)), '160 bits, lo que recomienda el RFC');
    }

    #[TestDox('El código del instante actual se acepta, y se devuelve el intervalo en que coincidió')]
    public function test_verify_accepts_current_code(): void
    {
        $totp = new Totp;
        $codigo = $totp->code($this->rfcSecret(), 1_700_000_000);

        $this->assertSame($totp->stepAt(1_700_000_000), $totp->verify($this->rfcSecret(), $codigo, 1_700_000_000));
    }

    #[TestDox('Se tolera un intervalo de 30 s de desfase por cada lado, pero no dos')]
    public function test_verify_window_is_one_step_each_side(): void
    {
        $totp = new Totp;
        $ahora = 1_700_000_000;

        $this->assertNotNull($totp->verify($this->rfcSecret(), $totp->code($this->rfcSecret(), $ahora - 30), $ahora), 'uno antes: sí');
        $this->assertNotNull($totp->verify($this->rfcSecret(), $totp->code($this->rfcSecret(), $ahora + 30), $ahora), 'uno después: sí');
        $this->assertNull($totp->verify($this->rfcSecret(), $totp->code($this->rfcSecret(), $ahora - 60), $ahora), 'dos antes: no');
        $this->assertNull($totp->verify($this->rfcSecret(), $totp->code($this->rfcSecret(), $ahora + 60), $ahora), 'dos después: no');
    }

    #[TestDox('Un código ya usado no se acepta otra vez (se pasa el último intervalo aceptado)')]
    public function test_verify_rejects_replay(): void
    {
        $totp = new Totp;
        $ahora = 1_700_000_000;
        $codigo = $totp->code($this->rfcSecret(), $ahora);

        $intervalo = $totp->verify($this->rfcSecret(), $codigo, $ahora);

        $this->assertNotNull($intervalo);
        $this->assertNull($totp->verify($this->rfcSecret(), $codigo, $ahora, 1, $intervalo), 'el mismo código, otra vez: no');
        $this->assertNotNull($totp->verify($this->rfcSecret(), $totp->code($this->rfcSecret(), $ahora + 30), $ahora + 30, 1, $intervalo), 'el del intervalo siguiente: sí');
    }

    #[TestDox('Con un último intervalo aceptado, los códigos de intervalos anteriores también se rechazan')]
    public function test_verify_rejects_older_steps_after_use(): void
    {
        $totp = new Totp;
        $ahora = 1_700_000_000;
        $posterior = $totp->stepAt($ahora) + 1;

        $this->assertNull($totp->verify($this->rfcSecret(), $totp->code($this->rfcSecret(), $ahora - 30), $ahora, 1, $posterior));
    }

    #[TestDox('Los códigos mal formados se rechazan sin llegar a calcular nada: letras, vacío, longitud errónea')]
    public function test_verify_rejects_malformed_codes(): void
    {
        $totp = new Totp;

        foreach (['', 'abcdef', '12345', '1234567', '12 34', '1.23456'] as $malo) {
            $this->assertNull($totp->verify($this->rfcSecret(), $malo, 1_700_000_000), "debería rechazar «{$malo}»");
        }
    }

    #[TestDox('El código se acepta con espacios en medio (así lo muestran algunas apps: «123 456»)')]
    public function test_verify_ignores_spaces(): void
    {
        $totp = new Totp;
        $codigo = $totp->code($this->rfcSecret(), 1_700_000_000);

        $this->assertNotNull($totp->verify($this->rfcSecret(), substr($codigo, 0, 3).' '.substr($codigo, 3), 1_700_000_000));
    }

    #[TestDox('El enlace otpauth lleva el emisor, la cuenta y la clave, bien codificados')]
    public function test_otpauth_uri(): void
    {
        $uri = Totp::otpauthUri('Ling Marco Tickets', 'carlos@lingmarco.example', 'JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('otpauth://totp/Ling%20Marco%20Tickets:carlos%40lingmarco.example?', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Ling%20Marco%20Tickets', $uri);
    }
}
