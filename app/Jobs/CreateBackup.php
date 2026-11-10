<?php

/*
 * Hace una copia de seguridad de la base de datos ejecutando scripts/db-backup.sh (el mismo script que se usa
 * a mano y que se probó restaurando). Cuándo tocarlo: al cambiar cómo se hace la copia (cambia el script, no esto).
 *
 * Por qué va a la COLA: un volcado puede tardar minutos y no debe tener la petición web bloqueada. Sin
 * worker (`composer dev` lo levanta) la copia se queda esperando; la pantalla lo avisa.
 *
 * Trampas: si el script falla se lanza una excepción para que la cola lo marque como fallido (queda en
 * `failed_jobs`); no se traga el error. Una sola vez (`tries = 1`): una copia repetida no arregla un fallo.
 */

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class CreateBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function handle(): void
    {
        $result = Process::path(base_path())->timeout($this->timeout)->run(['bash', 'scripts/db-backup.sh']);

        if ($result->failed()) {
            throw new RuntimeException('La copia de seguridad falló: '.trim($result->errorOutput() ?: $result->output()));
        }
    }
}
