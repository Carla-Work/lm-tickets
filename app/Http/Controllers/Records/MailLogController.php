<?php

/*
 * «Registro de correos» (sección «Registros», solo el administrador). Cuándo tocarlo: al cambiar los filtros o el detalle.
 * Referencia de diseño: la pantalla del mismo nombre de la aplicación asesoria, con la paleta de tickets.
 *
 * Trampas:
 *  - Es de SOLO LECTURA: no hay crear, editar ni borrar. Las filas las escribe App\Support\MailLogger.
 *  - El cuerpo del correo es HTML guardado y se pinta en la sesión del administrador, la más valiosa. Se
 *    muestra SOLO dentro de un iframe con `sandbox` vacío (no ejecuta nada) y con su propia
 *    Content-Security-Policy (sin red: una imagen remota avisaría a quien la puso de que se ha abierto).
 *    Son dos cercos y hacen falta los dos; no «simplificar» a un `{!! $body !!}`.
 *  - El acceso lo da la habilidad `view-records` (middleware `can:` de la ruta).
 */

namespace App\Http\Controllers\Records;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MailLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'string', 'in:'.implode(',', [EmailLog::PENDING, EmailLog::SENT, EmailLog::FAILED])],
            'tipo' => ['nullable', 'string', 'in:'.implode(',', array_keys(EmailLog::KINDS))],
            'ultimas24' => ['nullable', 'boolean'],
            'ver' => ['nullable', 'integer'],
        ]);

        $query = EmailLog::query()
            ->when($filters['estado'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['tipo'] ?? null, fn (Builder $q, $v) => $q->where('kind', $v))
            ->when($filters['ultimas24'] ?? false, fn (Builder $q) => $q->where('created_at', '>=', now()->subDay()))
            ->when($filters['q'] ?? null, function (Builder $q, $v) {
                // `addcslashes`: que % y _ no sean comodines.
                $like = '%'.addcslashes($v, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('subject', 'like', $like)
                    ->orWhere('to_emails', 'like', $like)
                    ->orWhere('from_email', 'like', $like));
            })
            ->orderByDesc('created_at')->orderByDesc('id');

        $open = isset($filters['ver']) ? EmailLog::query()->find($filters['ver']) : null;

        return view('records.mail', [
            'user' => $request->user(),
            'logs' => $query->paginate((int) config('access.records.per_page'))->withQueryString(),
            'filters' => $filters,
            'kinds' => EmailLog::KINDS,
            // Contadores sobre el MISMO filtro, para que cuadren con el total de al lado.
            'failed' => (clone $query)->reorder()->where('status', EmailLog::FAILED)->count(),
            'unconfirmed' => (clone $query)->reorder()->where('status', EmailLog::PENDING)->count(),
            'open' => $open,
            'preview' => $open === null ? null : $this->preview($open),
            'queued' => DB::table('jobs')->count(),
        ]);
    }

    /** El cuerpo montado como lo recibió quien lo recibió, listo para `srcdoc`. Ver las trampas de la cabecera. */
    private function preview(EmailLog $log): ?string
    {
        if ($log->body === null) {
            return null;
        }

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            .'<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:;">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'</head><body style="margin:0">'.$log->body.'</body></html>';
    }
}
