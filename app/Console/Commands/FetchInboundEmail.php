<?php

/*
 * Lee los buzones compartidos (aplicaciones@, soporte@, comercial@) y procesa los correos nuevos: `php artisan inbound:fetch [--mailbox=clave]`.
 * Cuándo tocarlo: al cambiar cómo se recorren los buzones. Lo programa routes/console.php cada minuto SOLO si INBOUND_ENABLED=true.
 * Qué hace con cada correo: App\Actions\Inbound\ProcessIncomingEmail (el filtro de entrada). Plan: docs/plan/p1-email-entrante.md.
 *
 * Trampas:
 *  - NO cambia nada en los buzones (son compartidos con personas): ni marca como leído, ni borra, ni mueve. Se mira un margen de días (config inbound.lookback_days)
 *    y los ya procesados se saltan por su Message-ID.
 *  - Un buzón que falla (contraseña, red) no impide leer los demás: se informa y el código de salida es 1; el siguiente minuto reintenta.
 *  - Un correo que falla al procesarse se registra con report() y NO se marca como procesado: se reintenta en la siguiente ejecución.
 *  - Artisan reutiliza la MISMA instancia del comando dentro de un proceso: los contadores se vacían al empezar handle().
 */

namespace App\Console\Commands;

use App\Actions\Inbound\ProcessIncomingEmail;
use App\Support\Inbound\MailboxClient;
use Illuminate\Console\Command;
use Throwable;

class FetchInboundEmail extends Command
{
    protected $signature = 'inbound:fetch {--mailbox= : Solo este buzón (clave de config/inbound.php: apps, support, accounting)}';

    protected $description = 'Lee los buzones de correo entrante y crea tickets, mensajes o entradas de cuarentena';

    public function handle(MailboxClient $client, ProcessIncomingEmail $process): int
    {
        if (! config('inbound.enabled')) {
            $this->line('Correo entrante desactivado (INBOUND_ENABLED=false). No se lee ningún buzón.');

            return self::SUCCESS;
        }

        /** @var array<string, array<string, int>> $summary buzón → estado → cantidad */
        $summary = [];
        $failed = false;
        $since = now()->subDays(max(1, (int) config('inbound.lookback_days')));

        foreach ((array) config('inbound.mailboxes') as $mailbox) {
            if ($this->option('mailbox') !== null && $this->option('mailbox') !== $mailbox['key']) {
                continue;
            }
            if (empty($mailbox['host']) || empty($mailbox['username']) || empty($mailbox['password'])) {
                $this->warn("Buzón {$mailbox['key']} sin configurar (host, usuario o contraseña): se salta.");
                $failed = true;

                continue;
            }

            $summary[$mailbox['key']] = [];
            try {
                foreach ($client->fetch($mailbox, $since) as $mail) {
                    try {
                        $email = $process($mail, $mailbox);
                        $summary[$mailbox['key']][$email->status] = ($summary[$mailbox['key']][$email->status] ?? 0) + 1;
                    } catch (Throwable $e) {
                        report($e);
                        $summary[$mailbox['key']]['error'] = ($summary[$mailbox['key']]['error'] ?? 0) + 1;
                        $failed = true;
                    }
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("Buzón {$mailbox['key']}: no se pudo leer ({$e->getMessage()}).");
                $failed = true;
            }
        }

        $rows = [];
        foreach ($summary as $key => $counts) {
            $rows[] = [$key, ...array_map(fn (string $s) => $counts[$s] ?? 0, ['ticket', 'reply', 'pending', 'spam', 'ignored', 'rejected', 'error'])];
        }
        $this->table(['Buzón', 'Tickets', 'Respuestas', 'Pendientes', 'Spam', 'Ignorados', 'Rechazados', 'Errores'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
