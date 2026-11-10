<?php

/*
 * Copias de seguridad: la lista, el botón y el trabajo. Cuándo tocarlo: al cambiar cómo se hace la copia.
 * Nunca se ejecuta el script real en los tests (volcaría una base de verdad): se simula con Process::fake.
 */

namespace Tests\Feature\Records;

use App\Jobs\CreateBackup;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\TestCase;

class BackupsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/lm-backups-'.uniqid();
        mkdir($this->dir);
        config(['access.records.backups_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    #[TestDox('La lista enseña las copias de la más reciente a la más antigua, con su tamaño')]
    public function test_lists_backups_newest_first(): void
    {
        file_put_contents($this->dir.'/lm_tickets_20260101-000000.sql.gz', str_repeat('a', 2048));
        touch($this->dir.'/lm_tickets_20260101-000000.sql.gz', strtotime('2026-01-01 00:00:00'));
        file_put_contents($this->dir.'/lm_tickets_20260301-000000.sql.gz', 'x');
        touch($this->dir.'/lm_tickets_20260301-000000.sql.gz', strtotime('2026-03-01 00:00:00'));
        file_put_contents($this->dir.'/notas.txt', 'no es una copia');

        $this->actingAs(User::factory()->admin()->create())->get(route('records.backups'))
            ->assertOk()
            ->assertSeeInOrder(['lm_tickets_20260301-000000.sql.gz', 'lm_tickets_20260101-000000.sql.gz'])
            ->assertSee('2 KB')
            ->assertDontSee('notas.txt');
    }

    #[TestDox('Sin copias, la pantalla lo dice')]
    public function test_empty_state(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('records.backups'))
            ->assertSee('Todavía no hay ninguna copia');
    }

    #[TestDox('«Crear copia» encola el trabajo y lo deja anotado en la auditoría')]
    public function test_store_queues_and_audits(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('records.backups.store'))->assertRedirect(route('records.backups'));

        Queue::assertPushed(CreateBackup::class);
        $this->assertSame($admin->id, AuditLog::query()->where('event', 'backup_requested')->firstOrFail()->actor_id);
    }

    #[TestDox('El trabajo ejecuta scripts/db-backup.sh')]
    public function test_job_runs_script(): void
    {
        Process::fake(['*' => Process::result('Copia creada: x')]);

        (new CreateBackup)->handle();

        Process::assertRan(fn ($p) => $p->command === ['bash', 'scripts/db-backup.sh']);
    }

    #[TestDox('Si el script falla, el trabajo lanza una excepción (la cola lo marca como fallido, no se traga el error)')]
    public function test_job_failure_throws(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'mysqldump: acceso denegado', exitCode: 2)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('acceso denegado');

        (new CreateBackup)->handle();
    }

    #[TestDox('Si la carpeta de copias no se puede escribir, la pantalla avisa de que no se guarda nada')]
    public function test_unusable_folder_warns(): void
    {
        config(['access.records.backups_path' => '/proc/no-existe/backups']);

        $this->actingAs(User::factory()->admin()->create())->get(route('records.backups'))
            ->assertSee('No se puede escribir en la carpeta de copias');
    }
}
