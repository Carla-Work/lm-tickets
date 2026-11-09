<?php

/*
 * Contraseñas de un solo uso basadas en tiempo (TOTP, RFC 6238), el algoritmo de Google Authenticator,
 * Microsoft Authenticator, Authy, etc. Se usa para el segundo factor del administrador (R21).
 * Cuándo tocarlo: casi nunca. Si se toca, TotpTest debe seguir pasando: contiene los VECTORES DE
 * PRUEBA OFICIALES del RFC 4226 y del RFC 6238, que son lo único que demuestra que esto es correcto.
 *
 * Por qué está escrito a mano (decisión de la usuaria, delegada en Claude, 2026-11-09): no añadir
 * una librería. Es poco código (HMAC-SHA1 + truncado), y PHP ya trae hash_hmac. A cambio, sin
 * código QR (exigiría otra librería): se muestra la clave para escribirla a mano.
 *
 * Trampas:
 *  - NO es criptografía propia: la parte criptográfica es hash_hmac() de PHP. Aquí solo se
 *    ensamblan los pasos del RFC. Aun así, no «optimizar» ni «simplificar» sin volver a pasar los vectores.
 *  - La comparación de códigos usa hash_equals (tiempo constante).
 *  - verify() devuelve el INTERVALO en que coincidió, no un booleano: quien llama debe guardarlo y
 *    pasarlo como `$lastUsedStep` la próxima vez, o un mismo código serviría varias veces.
 */

namespace App\Support;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // base32 (RFC 4648)

    public function __construct(
        private readonly int $digits = 6,
        private readonly int $period = 30,
    ) {}

    /** Clave nueva de 160 bits (lo que recomienda el RFC), en base32 y sin relleno: 32 caracteres. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $trozo) {
            $out .= self::ALPHABET[bindec(str_pad($trozo, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    /** Acepta mayúsculas o minúsculas, espacios, guiones y relleno `=`: lo que la gente copia y pega. */
    public static function base32Decode(string $base32): string
    {
        $limpio = strtoupper(preg_replace('/[\s=-]+/', '', $base32) ?? '');
        $bits = '';
        foreach (str_split($limpio) as $caracter) {
            $valor = strpos(self::ALPHABET, $caracter);
            if ($valor === false) {
                throw new \InvalidArgumentException('Carácter no válido en una clave base32.');
            }
            $bits .= str_pad(decbin($valor), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) { // los bits sobrantes del final son relleno y se descartan
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    /** Intervalo de tiempo (de `period` segundos) al que pertenece un instante. */
    public function stepAt(int $timestamp): int
    {
        return intdiv($timestamp, $this->period);
    }

    /** El código de un intervalo concreto (HOTP del RFC 4226 con el intervalo como contador). */
    public function codeForStep(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true); // 'J' = 64 bits, big endian
        $desplazamiento = ord($hash[19]) & 0x0F;
        $numero = ((ord($hash[$desplazamiento]) & 0x7F) << 24)
            | ((ord($hash[$desplazamiento + 1]) & 0xFF) << 16)
            | ((ord($hash[$desplazamiento + 2]) & 0xFF) << 8)
            | (ord($hash[$desplazamiento + 3]) & 0xFF);

        return str_pad((string) ($numero % (10 ** $this->digits)), $this->digits, '0', STR_PAD_LEFT);
    }

    /** El código que muestra la app en un instante dado. */
    public function code(string $secret, int $timestamp): string
    {
        return $this->codeForStep($secret, $this->stepAt($timestamp));
    }

    /**
     * Comprueba un código y devuelve el intervalo en que coincidió, o null.
     *
     * @param  int  $window  intervalos tolerados a cada lado (desfase del reloj del móvil)
     * @param  int|null  $lastUsedStep  último intervalo ya aceptado: los iguales o anteriores se rechazan
     *                                  (así un código no se puede reutilizar)
     */
    public function verify(string $secret, string $code, int $timestamp, int $window = 1, ?int $lastUsedStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{'.$this->digits.'}$/', $code)) {
            return null;
        }

        $actual = $this->stepAt($timestamp);
        for ($desfase = -$window; $desfase <= $window; $desfase++) {
            $intervalo = $actual + $desfase;
            if ($lastUsedStep !== null && $intervalo <= $lastUsedStep) {
                continue;
            }
            if (hash_equals($this->codeForStep($secret, $intervalo), $code)) {
                return $intervalo;
            }
        }

        return null;
    }

    /** Enlace que las apps de autenticación saben abrir para añadir la cuenta (sin código QR). */
    public static function otpauthUri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }
}
