<?php

/*
 * Cabeceras de seguridad comunes a todas las respuestas web (defecto D-1 de docs/defectos-conocidos.md).
 * Cuándo tocarlo: al añadir algo que deba poder incrustarse en otra web, o al fijar el dominio con HTTPS.
 *
 * Trampas:
 *  - NO se pone una CSP general: las vistas usan atributos `style="…"` y una política con `style-src` estricta
 *    las rompería. Solo se prohíbe que otra web incruste el panel (`frame-ancestors`), que es lo que importa.
 *  - El iframe del registro de correos usa `srcdoc`, al que no le afectan estas cabeceras: su aislamiento es el
 *    `sandbox` y la CSP propia de MailLogController. No quitarlos pensando que esto los sustituye.
 *  - HSTS solo se envía si la petición llegó por HTTPS: enviarlo por HTTP no sirve y, si se fijara con un dominio
 *    aún sin certificado, dejaría a los navegadores sin poder entrar. Detrás de un proxy hay que configurar
 *    los proxies de confianza para que `isSecure()` acierte.
 *  - No pisa una cabecera que la respuesta ya traiga (p. ej. la descarga de adjuntos).
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
