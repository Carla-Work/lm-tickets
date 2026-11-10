<?php

/*
 * «Copias de seguridad» (sección «Registros», solo el administrador): lista de copias y botón «Crear copia».
 * Cuándo tocarlo: al cambiar la pantalla; cómo se hace la copia vive en scripts/db-backup.sh y App\Jobs\CreateBackup.
 * Trampa: crear una copia deja constancia en la auditoría (quién la pidió y cuándo).
 */

namespace App\Http\Controllers\Records;

use App\Http\Controllers\Controller;
use App\Jobs\CreateBackup;
use App\Support\Audit;
use App\Support\BackupStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BackupController extends Controller
{
    public function index(Request $request, BackupStore $store): View
    {
        return view('records.backups', [
            'user' => $request->user(),
            'copies' => $store->all(),
            'usable' => $store->isUsable(),
            'queued' => DB::table('jobs')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        CreateBackup::dispatch();
        Audit::record('backup_requested', 'Ha pedido una copia de seguridad de la base de datos.', $request->user(), ip: $request->ip());

        return redirect()->route('records.backups')
            ->with('status', 'La copia se ha puesto en cola. Tardará unos minutos en aparecer en la lista.');
    }
}
