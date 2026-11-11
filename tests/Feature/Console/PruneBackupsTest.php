<?php

/*
 * Rotación de copias de seguridad (`backup:prune`). Cuándo tocarlo: al cambiar los plazos o el criterio. Trampas que vigila:
 *  - la fecha de una copia sale de su NOMBRE, no de la del fichero (todos se crean «ahora» en el test);
 *  - se conserva todo lo de los últimos días, UNA por mes (la más antigua) de los últimos meses, y la más reciente SIEMPRE;
 *  - solo se tocan los `*.sql.gz` con el nombre del script; `--dry-run` no borra nada.
 * Si falla, se arregla el comando o se discute el plazo: NO se adapta el test.
 */

namespace Tests\Feature\Console;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class PruneBackupsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 12:00:00');
        $this->dir = sys_get_temp_dir().'/lm-backups-'.uniqid();
        File::makeDirectory($this->dir);
        config(['access.records.backups_path' => $this->dir, 'access.records.backups_keep_days' => 14, 'access.records.backups_keep_months' => 3]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function copia(string $fecha): string
    {
        $nombre = 'lm_tickets_'.str_replace(['-', ':', ' '], ['', '', '-'], $fecha).'.sql.gz';
        file_put_contents($this->dir.'/'.$nombre, 'x');

        return $nombre;
    }

    /** @return list<string> */
    private function quedan(): array
    {
        $nombres = array_map('basename', glob($this->dir.'/*') ?: []);
        sort($nombres);

        return $nombres;
    }

    #[TestDox('Conserva todo lo de los últimos 14 días, la más antigua de cada uno de los últimos 3 meses y la más reciente; borra el resto')]
    public function test_rotation(): void
    {
        $quedan = [
            $this->copia('2026-11-11 03:00:00'), // hoy
            $this->copia('2026-11-01 03:00:00'), // hace 10 días
            $this->copia('2026-10-29 03:00:00'), // hace 13 días: dentro de los 14
            $this->copia('2026-10-03 03:00:00'), // la más antigua de octubre (fuera de 14 días): se conserva como la «mensual»
            $this->copia('2026-09-01 03:00:00'), // la más antigua de septiembre
            $this->copia('2026-08-15 03:00:00'), // la única de agosto (dentro de los 3 meses)
        ];
        $se_van = [
            $this->copia('2026-10-22 03:00:00'), // octubre, pero no la más antigua del mes y ya fuera de 14 días
            $this->copia('2026-09-20 03:00:00'), // septiembre, ídem
            $this->copia('2026-07-10 03:00:00'), // más viejo que 3 meses
            $this->copia('2025-01-01 03:00:00'), // muy viejo
        ];

        $this->artisan('backup:prune')->assertSuccessful();

        $esperadas = $quedan;
        sort($esperadas);
        $this->assertSame($esperadas, $this->quedan());
        foreach ($se_van as $borrada) {
            $this->assertFileDoesNotExist($this->dir.'/'.$borrada);
        }
    }

    #[TestDox('La copia más reciente no se borra nunca, aunque sea más vieja que todos los plazos')]
    public function test_latest_is_always_kept(): void
    {
        $unica = $this->copia('2025-01-01 03:00:00');
        $otra = $this->copia('2024-06-01 03:00:00');

        $this->artisan('backup:prune')->assertSuccessful();

        $this->assertSame([$unica], $this->quedan(), 'se conserva la más reciente y se va la más vieja');
        $this->assertFileDoesNotExist($this->dir.'/'.$otra);
    }

    #[TestDox('La fecha sale del NOMBRE de la copia, no del fichero: una copia vieja «tocada» hoy se borra igual')]
    public function test_date_comes_from_the_name(): void
    {
        $this->copia('2026-11-11 03:00:00');
        $vieja = $this->copia('2025-03-01 03:00:00');
        touch($this->dir.'/'.$vieja, time()); // fecha de hoy en el fichero

        $this->artisan('backup:prune')->assertSuccessful();

        $this->assertFileDoesNotExist($this->dir.'/'.$vieja);
    }

    #[TestDox('Solo toca las copias con el nombre del script: cualquier otro fichero de la carpeta se deja intacto')]
    public function test_other_files_are_untouched(): void
    {
        $this->copia('2026-11-11 03:00:00');
        $this->copia('2025-03-01 03:00:00');
        file_put_contents($this->dir.'/notas.txt', 'a');
        file_put_contents($this->dir.'/otra_cosa.sql.gz', 'a'); // sin fecha en el nombre
        file_put_contents($this->dir.'/lm_tickets_20250301-030000.sql', 'a'); // sin .gz

        $this->artisan('backup:prune')->assertSuccessful();

        $this->assertFileExists($this->dir.'/notas.txt');
        $this->assertFileExists($this->dir.'/otra_cosa.sql.gz');
        $this->assertFileExists($this->dir.'/lm_tickets_20250301-030000.sql');
    }

    #[TestDox('--dry-run enseña qué se borraría y no borra nada')]
    public function test_dry_run(): void
    {
        $this->copia('2026-11-11 03:00:00');
        $vieja = $this->copia('2025-03-01 03:00:00');

        $this->artisan('backup:prune', ['--dry-run' => true])->expectsOutputToContain('SIMULACIÓN')->assertSuccessful();

        $this->assertFileExists($this->dir.'/'.$vieja);
    }

    #[TestDox('Sin copias o sin carpeta no falla')]
    public function test_empty_folder(): void
    {
        $this->artisan('backup:prune')->assertSuccessful();
        File::deleteDirectory($this->dir);
        $this->artisan('backup:prune')->assertSuccessful();
    }
}
