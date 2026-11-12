<?php

/*
 * ¿El servidor que recibió el correo dice que el remitente es quien dice ser? Cuándo tocarlo: al cambiar el criterio (SPF/DKIM/DMARC).
 *
 * Criterio (decidido con la usuaria, 2026-11-12: «el De se falsifica»): se exige SPF o DKIM en «pass» en la cabecera `Authentication-Results`
 * que añadió NUESTRO servidor, y que DMARC no esté en «fail». Sin cabecera → no pasa (a cuarentena), salvo `INBOUND_REQUIRE_AUTH=false` (solo para pruebas).
 *
 * Trampa: depende de que el proveedor del buzón (IONOS) añada esa cabecera. Si no lo hace, TODO caerá en cuarentena: se ve al instante en
 * «Correo entrante» (motivo «sin comprobación del servidor») y `preflight:check` avisa si se desactiva la comprobación en producción.
 */

namespace App\Support\Inbound;

final class EmailAuthentication
{
    public static function passes(?string $authResults): bool
    {
        if (! config('inbound.require_auth')) {
            return true;
        }
        if ($authResults === null || trim($authResults) === '') {
            return false;
        }

        $header = strtolower($authResults);
        if (preg_match('/dmarc\s*=\s*fail/', $header)) {
            return false;
        }

        return (bool) preg_match('/\b(spf|dkim)\s*=\s*pass\b/', $header);
    }
}
