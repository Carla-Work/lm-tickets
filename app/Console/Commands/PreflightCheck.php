<?php

/*
 * Comprueba si la instalación está lista para producción: `php artisan preflight:check [--strict]`. Defectos D-2 y D-3 de
 * docs/defectos-conocidos.md; guía y valores en docs/plan/produccion.md. Cuándo tocarlo: al añadir una comprobación nueva o
 * cambiar el criterio de una. Se ejecuta antes de abrir al público, tras cada despliegue y cuando se sospeche que algo falla.
 *
 * Dos clases de comprobación:
 *  - SIEMPRE ERROR si falla (da igual el entorno): clave de la aplicación, base de datos que responde y en UTC (D-13), migraciones
 *    al día, almacenamiento privado (R3: sin `public/storage`), un único administrador activo y carpeta de copias usable.
 *  - SOLO de producción: depuración desactivada, HTTPS, registros con rotación y sin nivel debug, sesión cifrada y con cookie
 *    segura, correo real, cola que no sea `sync` Y viva, copia reciente, segundo factor del administrador y ninguna cuenta con la
 *    contraseña provisional. En local salen como AVISO; con `APP_ENV=production` o `--strict` son ERROR.
 *
 * Trampas:
 *  - «Cola viva»: no se puede preguntar a un trabajador si está vivo; se mira si hay trabajos PENDIENTES desde hace más de
 *    `STALE_JOB_MINUTES`. Un trabajador sano vacía la cola en segundos, así que un trabajo viejo = nadie los procesa (sin él no
 *    salen correos ni avisos).
 *  - «Contraseña provisional»: se prueba `password` contra el hash de cada cuenta (decenas de comprobaciones, no es caro). Es la
 *    contraseña que dejan la factoría de pruebas y la cuenta provisional de Contabilidad.
 *  - Solo LEE: no cambia nada ni escribe en la base de datos.
 *  - El código de salida es 1 si hay algún ERROR: sirve para cortar un despliegue (`php artisan preflight:check && …`).
 */

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\BackupStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class PreflightCheck extends Command
{
    /** Minutos que un trabajo puede esperar en la cola antes de dar por muerto al trabajador. */
    public const STALE_JOB_MINUTES = 10;

    /** Horas máximas de antigüedad de la última copia (la programada es diaria: 36 h deja margen a un retraso). */
    public const BACKUP_MAX_HOURS = 36;

    protected $signature = 'preflight:check {--strict : Tratar como ERROR lo que solo importa en producción (por defecto, solo con APP_ENV=production)}';

    protected $description = 'Comprueba si la configuración y el estado de la instalación están listos para producción';

    /** @var list<array{string, string, string}> nivel (ok|aviso|error), comprobación, detalle */
    private array $rows = [];

    private bool $strict = false;

    public function handle(): int
    {
        // Artisan reutiliza la MISMA instancia del comando dentro de un proceso (tests, llamadas desde código): sin vaciar las filas,
        // una segunda ejecución arrastraría los resultados de la primera.
        $this->rows = [];
        $this->strict = (bool) $this->option('strict') || config('app.env') === 'production';

        // Siempre error.
        $this->universal();
        // Producción.
        $this->environment();
        $this->logging();
        $this->session();
        $this->mail();
        $this->queue();
        $this->backups();
        $this->accounts();
        $this->inbound();

        $this->table(['', 'Comprobación', 'Detalle'], array_map(fn (array $r) => [match ($r[0]) {
            'ok' => 'OK', 'aviso' => 'AVISO', default => 'ERROR'
        }, $r[1], $r[2]], $this->rows));

        $errors = count(array_filter($this->rows, fn (array $r) => $r[0] === 'error'));
        $warnings = count(array_filter($this->rows, fn (array $r) => $r[0] === 'aviso'));
        $mode = $this->strict ? 'modo producción' : 'modo local (lo de producción sale como aviso)';
        $this->line("{$errors} errores, {$warnings} avisos · {$mode}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function ok(string $label, string $detail = ''): void
    {
        $this->rows[] = ['ok', $label, $detail];
    }

    /** Falla siempre con ERROR. */
    private function must(bool $pass, string $label, string $okDetail, string $failDetail): void
    {
        $this->rows[] = [$pass ? 'ok' : 'error', $label, $pass ? $okDetail : $failDetail];
    }

    /** Falla con ERROR en producción y con AVISO en local. */
    private function prod(bool $pass, string $label, string $okDetail, string $failDetail): void
    {
        $this->rows[] = [$pass ? 'ok' : ($this->strict ? 'error' : 'aviso'), $label, $pass ? $okDetail : $failDetail];
    }

    private function universal(): void
    {
        $this->must(config('app.key') !== null && config('app.key') !== '', 'Clave de la aplicación', 'definida', 'APP_KEY vacía: `php artisan key:generate` (sin ella ni las sesiones ni el segundo factor cifrado funcionan)');

        try {
            DB::connection()->getPdo();
            $this->ok('Base de datos', 'responde');
        } catch (Throwable $e) {
            $this->rows[] = ['error', 'Base de datos', 'no responde: '.get_class($e)];

            return; // sin base de datos no se pueden hacer las demás comprobaciones que la usan
        }

        $zone = (string) (DB::selectOne('select @@session.time_zone as zona')->zona ?? '');
        $this->must($zone === '+00:00', 'Zona horaria de la base de datos', 'sesión en UTC', "la sesión de MySQL usa «{$zone}»: falta 'timezone' => '+00:00' en la conexión (D-13)");

        $pending = $this->pendingMigrations();
        $this->must($pending === 0, 'Migraciones', 'al día', "{$pending} migraciones sin aplicar: `php artisan migrate --force` (con copia de seguridad antes)");

        $this->must(! file_exists(public_path('storage')) && ! is_link(public_path('storage')), 'Almacenamiento privado (R3)', 'no hay `public/storage`', '`public/storage` existe: los adjuntos serían públicos. Bórralo (no ejecutes `storage:link`)');

        $privado = storage_path('app/private');
        $this->must(is_dir($privado) ? is_writable($privado) : is_writable(dirname($privado)), 'Carpeta de adjuntos', 'se puede escribir', "no se puede escribir en {$privado}");

        $admins = User::query()->where('role', UserRole::Admin->value)->whereNull('deactivated_at')->whereNotNull('activated_at')->count();
        $this->must($admins === 1, 'Administrador', 'hay uno activo (R9)', $admins === 0 ? 'no hay ningún administrador activo: `php artisan admin:recover`' : "hay {$admins} administradores activos (debe haber uno, R9)");

        $this->must((new BackupStore)->isUsable(), 'Carpeta de copias', 'se puede escribir', 'no se puede escribir en la carpeta de copias (config/access.php → records.backups_path)');
    }

    private function environment(): void
    {
        $this->prod(config('app.env') === 'production', 'Entorno', 'production', 'APP_ENV es «'.config('app.env').'», no «production»');
        $this->prod(config('app.debug') === false, 'Depuración', 'desactivada', 'APP_DEBUG está activo: enseñaría rutas, consultas y variables a cualquiera que provoque un error');
        $this->prod(str_starts_with((string) config('app.url'), 'https://'), 'Dirección (HTTPS)', (string) config('app.url'), 'APP_URL no empieza por https:// («'.config('app.url').'»): los enlaces de los correos irían sin cifrar');
    }

    private function logging(): void
    {
        $daily = in_array('daily', (array) config('logging.channels.stack.channels', []), true) || config('logging.default') === 'daily';
        $this->prod($daily, 'Registros con rotación', 'canal diario', 'el registro es un único fichero que crece sin límite: LOG_STACK=daily');
        $level = (string) config('logging.channels.daily.level', 'debug');
        $this->prod($level !== 'debug', 'Nivel de registro', $level, 'LOG_LEVEL=debug registra todo (también datos de clientes en consultas): usa warning o error');
    }

    private function session(): void
    {
        $this->prod((bool) config('session.encrypt'), 'Sesión cifrada', 'sí', 'SESSION_ENCRYPT=true');
        $this->prod(config('session.secure') === true, 'Cookie de sesión segura', 'solo por HTTPS', 'SESSION_SECURE_COOKIE=true (con HTTPS)');
    }

    private function mail(): void
    {
        $mailer = (string) config('mail.default');
        $this->prod(! in_array($mailer, ['log', 'array'], true), 'Correo real', "mailer «{$mailer}»", "MAIL_MAILER={$mailer}: los correos se escribirían en el registro y nadie los recibiría");
        $from = (string) config('mail.from.address');
        $this->prod($from !== '' && ! str_contains($from, 'example.'), 'Remitente del correo', $from, "MAIL_FROM_ADDRESS «{$from}» es un ejemplo: pon el buzón real de Ling Marco");
    }

    /** Correo entrante (P1): si está activado, buzones configurados, extensiones y comprobación SPF/DKIM exigida en producción. */
    private function inbound(): void
    {
        if (! config('inbound.enabled')) {
            $this->ok('Correo entrante', 'desactivado (INBOUND_ENABLED=false): no se leen los buzones');

            return;
        }

        $missing = [];
        foreach ((array) config('inbound.mailboxes') as $mailbox) {
            if (empty($mailbox['host']) || empty($mailbox['username']) || empty($mailbox['password'])) {
                $missing[] = (string) $mailbox['key'];
            }
        }
        $this->must($missing === [], 'Correo entrante: buzones', 'host, usuario y contraseña de los tres buzones', 'faltan datos de: '.implode(', ', $missing).' (INBOUND_*_HOST/USERNAME/PASSWORD)');

        $extensions = array_filter(['zip', 'iconv', 'mbstring', 'fileinfo', 'openssl'], fn (string $e) => ! extension_loaded($e));
        $this->must($extensions === [], 'Correo entrante: extensiones de PHP', 'zip, iconv, mbstring, fileinfo y openssl', 'faltan: '.implode(', ', $extensions));

        $this->prod((bool) config('inbound.require_auth'), 'Correo entrante: SPF/DKIM', 'se exige (el «De» se falsifica)', 'INBOUND_REQUIRE_AUTH=false: cualquiera podría abrir tickets haciéndose pasar por un cliente');
    }

    private function queue(): void
    {
        $connection = (string) config('queue.default');
        $this->prod($connection !== 'sync', 'Cola de trabajos', "conexión «{$connection}»", 'QUEUE_CONNECTION=sync: los correos se enviarían dentro de la petición del usuario (lento y frágil)');

        if ($connection !== 'database') {
            return;
        }
        $limit = now()->subMinutes(self::STALE_JOB_MINUTES)->getTimestamp();
        $stale = DB::table('jobs')->where('created_at', '<', $limit)->count();
        $this->prod($stale === 0, 'Trabajador de colas vivo', 'no hay trabajos esperando', "{$stale} trabajos llevan más de ".self::STALE_JOB_MINUTES.' minutos sin procesarse: el trabajador de colas está parado (sin él no salen correos ni avisos). Ver docs/plan/produccion.md');

        $failed = DB::table('failed_jobs')->count();
        $this->rows[] = [$failed === 0 ? 'ok' : 'aviso', 'Trabajos fallidos', $failed === 0 ? 'ninguno' : "{$failed} en `failed_jobs`: revísalos (`php artisan queue:failed`)"];
    }

    private function backups(): void
    {
        $copies = (new BackupStore)->all();
        if ($copies === []) {
            $this->prod(false, 'Copia de seguridad', '', 'no hay ninguna copia: `bash scripts/db-backup.sh` y la entrada de cron del planificador (docs/plan/produccion.md)');

            return;
        }
        $hours = (int) abs(now()->diffInHours($copies[0]['date'], false));
        $this->prod($hours <= self::BACKUP_MAX_HOURS, 'Copia de seguridad reciente', "la última, de hace {$hours} h", "la última copia es de hace {$hours} h (más de ".self::BACKUP_MAX_HOURS.' h): ¿corre el planificador? Falta la entrada de cron `schedule:run`');
    }

    private function accounts(): void
    {
        $admin = User::query()->where('role', UserRole::Admin->value)->whereNull('deactivated_at')->first();
        if ($admin !== null) {
            $this->prod($admin->hasTwoFactorEnabled(), 'Segundo factor del administrador', 'activado', 'el administrador no tiene la verificación en dos pasos (es opcional, R21, pero muy recomendable: lo ve todo)');
        }

        $provisional = 0;
        foreach (User::query()->whereNotNull('activated_at')->get(['id', 'password']) as $user) {
            if (Hash::check('password', $user->password)) {
                $provisional++;
            }
        }
        $this->prod($provisional === 0, 'Contraseñas provisionales', 'ninguna', "{$provisional} cuentas siguen con la contraseña provisional «password» (p. ej. la de Contabilidad): cámbialas antes de abrir");
    }

    private function pendingMigrations(): int
    {
        $files = collect(glob(database_path('migrations/*.php')) ?: [])->map(fn (string $f) => basename($f, '.php'));
        $ran = DB::table('migrations')->pluck('migration');

        return $files->diff($ran)->count();
    }
}
