<?php

/*
 * Códigos de recuperación del segundo factor: la salida si el administrador pierde el móvil (R21).
 * Cuándo tocarlo: al cambiar su formato o cuántos se generan (config/access.php).
 *
 * Decisiones y trampas:
 *  - Se guardan con HASH (como una contraseña): ni el servidor puede volver a mostrarlos. Los códigos
 *    en claro existen solo en el momento de crearlos.
 *  - Cada código sirve UNA vez: al usarse se quita de la lista.
 *  - El alfabeto no tiene 0/O ni 1/I/L para que no se confundan al copiarlos a mano.
 *  - Al comparar se normaliza (mayúsculas, sin guion ni espacios): da igual cómo los escriba la persona.
 */

namespace App\Support;

use Illuminate\Support\Facades\Hash;

final class RecoveryCodes
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // sin 0 O 1 I L

    /**
     * @return list<string> códigos legibles con formato XXXX-XXXX
     */
    public static function generate(int $count): array
    {
        $codigos = [];
        while (count($codigos) < $count) {
            $crudo = '';
            for ($i = 0; $i < 8; $i++) {
                $crudo .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codigo = substr($crudo, 0, 4).'-'.substr($crudo, 4);
            if (! in_array($codigo, $codigos, true)) { // dos iguales en la misma tanda: imposible en la práctica, pero barato de evitar
                $codigos[] = $codigo;
            }
        }

        return $codigos;
    }

    /** Lo que se compara: mayúsculas y solo letras y números. */
    public static function normalize(string $codigo): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo) ?? '');
    }

    /**
     * @param  list<string>  $codigos  en claro
     * @return list<string> con hash, listos para guardar
     */
    public static function hashAll(array $codigos): array
    {
        return array_map(fn (string $c) => Hash::make(self::normalize($c)), $codigos);
    }

    /**
     * Si el código coincide con alguno, devuelve la lista SIN ese (uso único); si no, null.
     *
     * @param  list<string>  $hashes
     * @return list<string>|null
     */
    public static function consume(array $hashes, string $introducido): ?array
    {
        $normalizado = self::normalize($introducido);
        if ($normalizado === '') {
            return null;
        }

        foreach ($hashes as $posicion => $hash) {
            if (Hash::check($normalizado, $hash)) {
                unset($hashes[$posicion]);

                return array_values($hashes);
            }
        }

        return null;
    }
}
