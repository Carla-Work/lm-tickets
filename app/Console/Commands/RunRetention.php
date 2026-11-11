<?php

/*
 * Aplica el plan de conservación de datos: `php artisan retention:run [--dry-run]`.
 * Cuándo tocarlo: al cambiar qué se purga. Plan y decisiones: docs/plan/conservacion-datos.md. Plazos: config/retention.php.
 *
 * Qué hace (decidido por la usuaria el 2026-11-10, plazos PROVISIONALES de 2 años):
 *  1. Tickets CERRADOS hace más del plazo: se ANONIMIZAN (asunto, solución y TODOS los mensajes, también las notas
 *     internas, se sustituyen por un texto fijo), se BORRAN sus adjuntos (fichero y fila) y sus avisos de la campana.
 *     El ticket, quién lo abrió, su clasificación y su historial de estados se conservan (estadística).
 *     La valoración de la atención (P1) conserva su PUNTUACIÓN y pierde el comentario (texto libre del cliente).
 *  2. Registro de correos y auditoría: se BORRAN las filas más antiguas que el plazo.
 *  3. (2026-11-11) Enlaces de contraseña caducados, avisos de la campana antiguos y trabajos fallidos antiguos.
 *  4. (2026-11-11) Usuarios dados de baja hace más del plazo: se ANONIMIZAN (la fila y su id quedan).
 *
 * Qué NO hace (a propósito): no toca las empresas (obligaciones contables y contractuales) ni las copias de
 * seguridad (su rotación fija su plazo real).
 *
 * Trampas:
 *  - NO está programado: la usuaria decidió dejarlo manual hasta validar el plazo con la asesoría legal. `--dry-run`
 *    enseña qué afectaría sin cambiar nada. Para programarlo: `Schedule::command('retention:run')->daily()` en
 *    routes/console.php, solo cuando se dé el visto bueno.
 *  - Es irreversible: lo anonimizado y lo borrado no se recupera salvo de una copia de seguridad.
 *  - Cada ticket va en su propia transacción; los ficheros se borran DESPUÉS de confirmarla, para no dejar filas
 *    apuntando a ficheros ya borrados si algo falla. Se puede repetir sin riesgo: lo ya anonimizado se salta.
 *  - La auditoría solo se añade (`Audit::record`); esta purga es el ÚNICO sitio que borra filas de `audit_logs`.
 *  - Deja una fila de auditoría con los recuentos (sin datos personales).
 */

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RunRetention extends Command
{
    /** Texto que sustituye al contenido anonimizado. Fijo: no depende del ticket. */
    public const REPLACEMENT_SUBJECT = 'Ticket anonimizado';

    public const REPLACEMENT_BODY = '[Contenido eliminado por el plazo de conservación]';

    /** Nombre que sustituye al de un usuario anonimizado. El correo pasa a `baja-{id}@anonimo.invalid` (único). */
    public const REPLACEMENT_USER_NAME = 'Persona dada de baja';

    protected $signature = 'retention:run {--dry-run : Solo muestra qué se aplicaría, sin cambiar nada}';

    protected $description = 'Aplica el plan de conservación: anonimiza tickets cerrados antiguos, borra sus adjuntos y purga correos y auditoría';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $ticketsCutoff = now()->subYears((int) config('retention.tickets_years'));
        $logsCutoff = now()->subYears((int) config('retention.logs_years'));

        $this->line(($dry ? '[SIMULACIÓN] ' : '').'Tickets cerrados antes de '.$ticketsCutoff->format('Y-m-d').'; registros anteriores a '.$logsCutoff->format('Y-m-d').'.');

        $due = $this->dueTickets($ticketsCutoff);
        $emailLogs = EmailLog::query()->where('created_at', '<', $logsCutoff);
        $auditLogs = AuditLog::query()->where('created_at', '<', $logsCutoff);

        $tokens = $this->expiredResetTokens();
        $oldNotices = DB::table('notifications')->where('created_at', '<', $logsCutoff);
        $oldFailedJobs = DB::table('failed_jobs')->where('failed_at', '<', now()->subDays((int) config('retention.failed_jobs_days')));
        $usersDue = $this->dueUsers(now()->subYears((int) config('retention.deactivated_users_years')));

        $totals = ['tickets' => 0, 'attachments' => 0, 'notices' => 0, 'email_logs' => 0, 'audit_logs' => 0, 'reset_tokens' => 0, 'old_notices' => 0, 'failed_jobs' => 0, 'users' => 0];

        if ($dry) {
            $totals['tickets'] = (clone $due)->count();
            $totals['attachments'] = Attachment::query()->whereIn('ticket_id', (clone $due)->select('id'))->count();
            $totals['email_logs'] = (clone $emailLogs)->count();
            $totals['audit_logs'] = (clone $auditLogs)->count();
            $totals['reset_tokens'] = (clone $tokens)->count();
            $totals['old_notices'] = (clone $oldNotices)->count();
            $totals['failed_jobs'] = (clone $oldFailedJobs)->count();
            $totals['users'] = (clone $usersDue)->count();
        } else {
            (clone $due)->chunkById(100, function ($tickets) use (&$totals): void {
                foreach ($tickets as $ticket) {
                    [$files, $notices] = $this->anonymize($ticket);
                    $totals['tickets']++;
                    $totals['attachments'] += $files;
                    $totals['notices'] += $notices;
                }
            });
            $totals['email_logs'] = $emailLogs->delete();
            $totals['audit_logs'] = $auditLogs->delete();
            $totals['reset_tokens'] = $tokens->delete();
            $totals['old_notices'] = $oldNotices->delete();
            $totals['failed_jobs'] = $oldFailedJobs->delete();
            (clone $usersDue)->each(function (User $user) use (&$totals): void {
                $this->anonymizeUser($user);
                $totals['users']++;
            });
        }

        $this->table(['Qué', $dry ? 'Afectaría a' : 'Afectados'], [
            ['Tickets anonimizados', $totals['tickets']],
            ['Adjuntos borrados', $totals['attachments']],
            ['Avisos de la campana borrados', $dry ? '—' : $totals['notices']],
            ['Correos del registro borrados', $totals['email_logs']],
            ['Filas de auditoría borradas', $totals['audit_logs']],
            ['Enlaces de contraseña caducados borrados', $totals['reset_tokens']],
            ['Avisos antiguos borrados', $totals['old_notices']],
            ['Trabajos fallidos antiguos borrados', $totals['failed_jobs']],
            ['Usuarios de baja anonimizados', $totals['users']],
        ]);

        if (! $dry) {
            // Después de purgar la auditoría, para que esta fila (sin datos personales) no se borre a sí misma.
            Audit::record('retention_run', "Purga por plazo: {$totals['tickets']} tickets anonimizados, {$totals['attachments']} adjuntos, {$totals['email_logs']} correos y {$totals['audit_logs']} filas de auditoría y {$totals['reset_tokens']} enlaces caducados, {$totals['old_notices']} avisos y {$totals['failed_jobs']} trabajos fallidos borrados; {$totals['users']} usuarios de baja anonimizados.", null, null, null, $totals);
        }

        return self::SUCCESS;
    }

    /**
     * Enlaces de recuperación e invitación que ya no sirven (defecto D-5). Laravel solo borra un token al USARLO, y los
     * que nadie abre se quedarían para siempre con el correo de la persona. Los dos tipos comparten tabla: se usa el
     * plazo MÁS LARGO de los dos (la invitación, 7 días), así nunca se borra uno que todavía valga.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function expiredResetTokens()
    {
        $table = (string) config('auth.passwords.users.table');
        $minutes = max((int) config('auth.passwords.users.expire'), (int) config('auth.passwords.invitations.expire'));

        return DB::table($table)->where('created_at', '<', now()->subMinutes($minutes));
    }

    /** @return Builder<User> usuarios dados de baja hace más del plazo y aún sin anonimizar */
    private function dueUsers(\DateTimeInterface $cutoff): Builder
    {
        return User::query()->whereNotNull('deactivated_at')->where('deactivated_at', '<=', $cutoff)->whereNull('anonymized_at');
    }

    /**
     * Deja a la persona irreconocible conservando su fila y su id (los tickets, mensajes y eventos apuntan a ella con
     * claves RESTRICT). La contraseña pasa a un valor aleatorio que nadie conoce y se quitan el segundo factor, las
     * sesiones, los avisos y cualquier enlace pendiente: aunque alguien reactivara la cuenta a mano, no entra nadie.
     */
    private function anonymizeUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($user->anonymized_at !== null) {
                return; // otro proceso se adelantó
            }

            $email = $user->email;

            $user->forceFill([
                'name' => self::REPLACEMENT_USER_NAME,
                'email' => "baja-{$user->id}@anonimo.invalid",
                'phone' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'anonymized_at' => now(),
            ])->save();
            $user->clearTwoFactor();

            DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table((string) config('auth.passwords.users.table'))->where('email', $email)->delete();
        });
    }

    /** @return Builder<Ticket> */
    private function dueTickets(\DateTimeInterface $cutoff): Builder
    {
        return Ticket::query()->where('status', TicketStatus::Closed->value)->whereNull('anonymized_at')->where('closed_at', '<=', $cutoff);
    }

    /** @return array{int, int} adjuntos borrados y avisos borrados */
    private function anonymize(Ticket $ticket): array
    {
        $paths = [];
        $notices = 0;

        DB::transaction(function () use ($ticket, &$paths, &$notices): void {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($ticket->anonymized_at !== null) {
                return; // otro proceso se adelantó
            }

            TicketMessage::query()->where('ticket_id', $ticket->id)->update(['body' => self::REPLACEMENT_BODY]);

            $attachments = Attachment::query()->where('ticket_id', $ticket->id)->get(['id', 'path']);
            $paths = $attachments->pluck('path')->all();
            Attachment::query()->whereIn('id', $attachments->pluck('id'))->delete();

            // Los avisos de la campana llevan el asunto y la empresa del ticket (no su texto): se borran.
            $notices = DB::table('notifications')
                ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.ticket_id')) AS UNSIGNED) = ?", [$ticket->id])->delete();

            // El comentario de la valoración es texto libre del cliente: se sustituye. La puntuación se conserva (estadística).
            DB::table('ticket_ratings')->where('ticket_id', $ticket->id)->whereNotNull('comment')->update(['comment' => self::REPLACEMENT_BODY]);

            $ticket->forceFill(['subject' => self::REPLACEMENT_SUBJECT, 'resolution' => null, 'anonymized_at' => now()])->save();
        });

        Storage::disk(Attachment::DISK)->delete($paths); // tras confirmar: si falla, quedan ficheros huérfanos, no filas rotas

        return [count($paths), $notices];
    }
}
