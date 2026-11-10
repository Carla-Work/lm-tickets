<?php

/*
 * Lee las copias de seguridad de la base de datos que hay en disco (storage/backups/).
 * Cuándo tocarlo: al cambiar dónde o cómo se guardan (scripts/db-backup.sh las crea; esto solo las lista).
 *
 * Trampas:
 *  - Solo LISTA nombre, fecha y tamaño. No hay descarga desde la web a propósito: una copia lleva datos de
 *    todos los clientes y sacarla del servidor es decisión de quien administra la máquina.
 *  - Solo se ven los ficheros `*.sql.gz`: es lo que escribe el script.
 */

namespace App\Support;

use Carbon\CarbonImmutable;

class BackupStore
{
    public function path(): string
    {
        return (string) config('access.records.backups_path');
    }

    /** ¿Existe (o se puede crear) la carpeta de copias? Si no, la pantalla avisa de que no se está guardando nada. */
    public function isUsable(): bool
    {
        $dir = $this->path();

        return is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir));
    }

    /**
     * Copias de la más reciente a la más antigua.
     *
     * @return list<array{name: string, date: CarbonImmutable, size: string}>
     */
    public function all(): array
    {
        $files = glob($this->path().'/*.sql.gz') ?: [];

        $copies = array_map(fn (string $file) => [
            'name' => basename($file),
            'date' => CarbonImmutable::createFromTimestamp((int) filemtime($file)),
            'size' => $this->humanSize((int) filesize($file)),
        ], $files);

        usort($copies, fn ($a, $b) => $b['date'] <=> $a['date'] ?: strcmp($b['name'], $a['name']));

        return $copies;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $pos = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $pos), $pos === 0 ? 0 : 1).' '.$units[$pos];
    }
}
