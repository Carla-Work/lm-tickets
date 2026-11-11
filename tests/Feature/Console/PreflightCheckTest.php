<?php

/*
 * `php artisan preflight:check`: la comprobación de «¿está lista para producción?» (defectos D-2, D-3, D-13).
 * Cuándo tocarlo: al añadir o cambiar una comprobación. Lo que vigila: cada comprobación FALLA cuando debe (con su mensaje y con
 * código de salida 1 en modo producción), una instalación bien configurada sale limpia, y lo universal es error SIEMPRE.
 * Si falla, se arregla el comando o se discute el criterio: NO se adapta el test.
 */

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class PreflightCheckTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
        $this->dir = sys_get_temp_dir().'/lm-preflight-'.uniqid();
        File::makeDirectory($this->dir);
        $this->instalacionBuena();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** Deja una instalación como debería estar en producción: todas las comprobaciones en verde. */
    private function instalacionBuena(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://tickets.lingmarco.es',
            'logging.channels.stack.channels' => ['daily'], 'logging.channels.daily.level' => 'warning',
            'session.encrypt' => true, 'session.secure' => true,
            'mail.default' => 'smtp', 'mail.from.address' => 'tickets@lingmarco.es',
            'queue.default' => 'database',
            'access.records.backups_path' => $this->dir,
        ]);
        file_put_contents($this->dir.'/lm_tickets_20261111-030000.sql.gz', 'x');
        // Las cuentas de prueba llevan «password»: nadie en producción.
        DB::table('users')->update(['password' => Hash::make('una-clave-de-verdad-123')]);
        $this->a['admin']->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['x']])->save();
    }

    /** @return array{int, string} código de salida y lo que imprimió */
    private function ejecutar(array $opciones = []): array
    {
        $salida = new BufferedOutput;
        $codigo = Artisan::call('preflight:check', $opciones, $salida);

        return [$codigo, $salida->fetch()];
    }

    #[TestDox('Una instalación bien configurada sale limpia: 0 errores, 0 avisos, código de salida 0')]
    public function test_a_good_installation_passes(): void
    {
        [$codigo, $salida] = $this->ejecutar();

        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('0 errores, 0 avisos', $salida);
    }

    /** @return array<string, array{callable, string}> lo que se rompe y la comprobación que lo debe delatar */
    public static function rompibles(): array
    {
        return [
            'entorno' => [fn () => config(['app.env' => 'local']), 'Entorno'],
            'depuración' => [fn () => config(['app.debug' => true]), 'Depuración'],
            'http' => [fn () => config(['app.url' => 'http://tickets.lingmarco.es']), 'Dirección (HTTPS)'],
            'registro sin rotación' => [fn () => config(['logging.channels.stack.channels' => ['single']]), 'Registros con rotación'],
            'nivel debug' => [fn () => config(['logging.channels.daily.level' => 'debug']), 'Nivel de registro'],
            'sesión sin cifrar' => [fn () => config(['session.encrypt' => false]), 'Sesión cifrada'],
            'cookie no segura' => [fn () => config(['session.secure' => null]), 'Cookie de sesión segura'],
            'correo en el registro' => [fn () => config(['mail.default' => 'log']), 'Correo real'],
            'remitente de ejemplo' => [fn () => config(['mail.from.address' => 'hello@example.com']), 'Remitente del correo'],
            'cola síncrona' => [fn () => config(['queue.default' => 'sync']), 'Cola de trabajos'],
            'trabajador parado' => [fn () => DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time() - 3600, 'created_at' => time() - 3600]), 'Trabajador de colas vivo'],
            'sin copias' => [fn () => array_map('unlink', glob(config('access.records.backups_path').'/*') ?: []), 'Copia de seguridad'],
            'copia vieja' => [function () {
                foreach (glob(config('access.records.backups_path').'/*') ?: [] as $f) {
                    touch($f, time() - 3 * 86400);
                }
            }, 'Copia de seguridad reciente'],
            'administrador sin 2FA' => [fn () => User::query()->where('role', 'admin')->update(['two_factor_confirmed_at' => null]), 'Segundo factor del administrador'],
            'contraseña provisional' => [fn () => DB::table('users')->where('email', 'like', '%')->limit(1)->update(['password' => Hash::make('password')]), 'Contraseñas provisionales'],
        ];
    }

    #[DataProvider('rompibles')]
    #[TestDox('En modo producción, cada comprobación de producción falla con ERROR y código 1 cuando se rompe; en local solo AVISA y no corta')]
    public function test_each_production_check_fails(callable $romper, string $comprobacion): void
    {
        $romper();

        [$codigo, $salida] = $this->ejecutar(['--strict' => true]);
        $this->assertSame(1, $codigo, "«{$comprobacion}» debería cortar en modo producción:\n$salida");
        $this->assertMatchesRegularExpression('/ERROR\s+\|\s+'.preg_quote($comprobacion, '/').'/', $salida, "falta el ERROR de «{$comprobacion}»");

        // En local (sin --strict y con APP_ENV distinto de production) es un aviso, no un error.
        config(['app.env' => 'local']);
        [$codigoLocal, $salidaLocal] = $this->ejecutar();
        $this->assertStringNotContainsString('ERROR | '.$comprobacion, $salidaLocal);
        $this->assertSame(0, $codigoLocal, "en local «{$comprobacion}» no debe cortar:\n$salidaLocal");
    }

    #[TestDox('Lo universal es ERROR siempre, también en local: sin clave, base de datos fuera de UTC, migración sin aplicar, adjuntos públicos, sin administrador o carpeta de copias inservible')]
    public function test_universal_checks_always_error(): void
    {
        $casos = [
            'clave' => [fn () => config(['app.key' => '']), 'Clave de la aplicación', fn () => config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))])],
            'zona horaria' => [fn () => DB::statement("set time_zone = 'SYSTEM'"), 'Zona horaria de la base de datos', fn () => DB::statement("set time_zone = '+00:00'")],
            'migración pendiente' => [fn () => DB::table('migrations')->orderByDesc('id')->limit(1)->delete(), 'Migraciones', fn () => null],
            'sin administrador' => [fn () => User::query()->where('role', 'admin')->update(['deactivated_at' => now()]), 'Administrador', fn () => null],
            'copias inservibles' => [fn () => config(['access.records.backups_path' => '/no-existe-esta-ruta/copias']), 'Carpeta de copias', fn () => null],
        ];

        foreach ($casos as $nombre => [$romper, $etiqueta, $arreglar]) {
            config(['app.env' => 'local']); // en local: lo universal sigue siendo error
            $romper();
            [$codigo, $salida] = $this->ejecutar();
            $this->assertSame(1, $codigo, "«{$nombre}» debe ser error también en local:\n$salida");
            $this->assertMatchesRegularExpression('/ERROR\s+\|\s+'.preg_quote($etiqueta, '/').'/', $salida);
            $arreglar();
            $this->refrescarBase($nombre);
        }
    }

    /** Devuelve la instalación al estado bueno entre casos del test anterior. */
    private function refrescarBase(string $nombre): void
    {
        $this->instalacionBuena();
        DB::table('users')->where('role', 'admin')->update(['deactivated_at' => null]);
        if ($nombre === 'migración pendiente') {
            // La migración quitada se vuelve a anotar para que las siguientes comprobaciones partan de cero.
            $archivos = collect(glob(database_path('migrations/*.php')) ?: [])->map(fn (string $f) => basename($f, '.php'))->sort()->values();
            $ya = DB::table('migrations')->pluck('migration');
            foreach ($archivos->diff($ya) as $falta) {
                DB::table('migrations')->insert(['migration' => $falta, 'batch' => 1]);
            }
        }
    }

    #[TestDox('Si `public/storage` existe, los adjuntos serían públicos (R3): error siempre, sea enlace o carpeta')]
    public function test_public_storage_is_an_error(): void
    {
        $publica = sys_get_temp_dir().'/lm-public-'.uniqid();
        File::makeDirectory($publica);
        $this->app->usePublicPath($publica);

        [$codigo] = $this->ejecutar();
        $this->assertSame(0, $codigo, 'sin public/storage está bien');

        File::makeDirectory($publica.'/storage');
        [$codigo, $salida] = $this->ejecutar();
        $this->assertSame(1, $codigo);
        $this->assertMatchesRegularExpression('/ERROR\s+\|\s+Almacenamiento privado/', $salida);

        File::deleteDirectory($publica.'/storage');
        symlink($this->dir, $publica.'/storage');
        [$codigo] = $this->ejecutar();
        $this->assertSame(1, $codigo, 'también si es un enlace simbólico');
        File::deleteDirectory($publica);
    }

    #[TestDox('El comando solo LEE: no cambia ninguna fila de usuarios ni escribe en la auditoría')]
    public function test_it_only_reads(): void
    {
        $firma = fn () => DB::table('users')->orderBy('id')->get()->map(fn ($u) => $u->id.$u->password.$u->deactivated_at)->implode('|');
        $antes = $firma();

        $this->ejecutar();
        $this->ejecutar(['--strict' => true]);

        $this->assertSame($antes, $firma());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }
}
