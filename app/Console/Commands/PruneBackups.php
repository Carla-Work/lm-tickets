<?php

/*
 * Rota las copias de seguridad de la base de datos: `php artisan backup:prune [--dry-run]`. Se programa a diario
 * (routes/console.php). Cuándo tocarlo: al cambiar cuánto tiempo se conservan las copias. Plazos: config/access.php.
 *
 * Qué conserva (decidido por Claude por delegación de la usuaria, 2026-11-11; provisional hasta validar con la asesoría):
 *  - TODAS las copias de los últimos `backups_keep_days` días (14), y
 *  - UNA por cada mes (la más antigua de ese mes) de los últimos `backups_keep_months` meses (3).
 *  - La copia MÁS RECIENTE no se borra nunca, pase lo que pase con los plazos.
 *
 * Por qué importa (docs/plan/conservacion-datos.md): una copia conserva datos que `retention:run` ya habría borrado. El plazo
 * real en que un dato «borrado» deja de existir es el de la copia más antigua que lo lleve: con estos plazos, unos 3 meses.
 *
 * Trampas:
 *  - La fecha de una copia sale de su NOMBRE (`BASE_AAAAMMDD-HHMMSS.sql.gz`, lo que escribe scripts/db-backup.sh), no de la
 *    fecha del fichero: un `touch` o una copia de la carpeta no deben hacer que una copia vieja parezca nueva.
 *  - Solo toca ficheros `*.sql.gz` con ese nombre; cualquier otra cosa de la carpeta se deja intacta.
 *  - Borra de verdad (es una rotación): `--dry-run` enseña qué borraría sin tocar nada.
 */

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PruneBackups extends Command
{
    protected $signature = 'backup:prune {--dry-run : Solo muestra qué se borraría, sin borrar nada}';

    protected $description = 'Rota las copias de seguridad: conserva las de los últimos días y una por mes de los últimos meses';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $dir = (string) config('access.records.backups_path');
        $days = (int) config('access.records.backups_keep_days');
        $months = (int) config('access.records.backups_keep_months');

        /** @var array<string, CarbonImmutable> $copies ruta → fecha de la copia (la de su nombre) */
        $copies = [];
        foreach (glob($dir.'/*.sql.gz') ?: [] as $file) {
            if (preg_match('/_(\d{8})-(\d{6})\.sql\.gz$/', $file, $m) === 1) {
                // El nombre ya está validado (8 y 6 dígitos): `createFromFormat` no puede fallar con estas cadenas.
                $copies[$file] = CarbonImmutable::createFromFormat('Ymd-His', $m[1].'-'.$m[2]);
            }
        }
        asort($copies);

        $keep = $this->toKeep($copies, $days, $months);
        $delete = array_diff_key($copies, $keep);

        foreach ($delete as $file => $date) {
            $this->line(($dry ? '[SIMULACIÓN] borraría ' : 'Borrada ').basename($file).' ('.$date->format('Y-m-d').')');
            if (! $dry) {
                @unlink($file);
            }
        }
        $this->info(count($keep).' copias conservadas, '.count($delete).($dry ? ' se borrarían.' : ' borradas.'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, CarbonImmutable>  $copies  ordenadas de la más antigua a la más reciente
     * @return array<string, true> las que se conservan (por ruta)
     */
    private function toKeep(array $copies, int $days, int $months): array
    {
        $now = CarbonImmutable::now();
        $recent = $now->subDays($days);
        $monthly = $now->startOfMonth()->subMonths($months);
        $keep = [];
        $monthsSeen = [];

        foreach ($copies as $file => $date) {
            if ($date >= $recent) {
                $keep[$file] = true;

                continue;
            }
            $month = $date->format('Y-m');
            if ($date >= $monthly && ! isset($monthsSeen[$month])) {
                $monthsSeen[$month] = true; // la más antigua de ese mes (están ordenadas)
                $keep[$file] = true;
            }
        }

        // La más reciente se conserva siempre.
        if ($copies !== []) {
            $keep[array_key_last($copies)] = true;
        }

        return $keep;
    }
}
