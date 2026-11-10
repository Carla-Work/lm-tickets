<?php

/*
 * «Auditoría» (sección «Registros», solo el administrador): quién hizo qué fuera de los tickets.
 * Cuándo tocarlo: al cambiar filtros o detalle. Los hechos nuevos se añaden con App\Support\Audit.
 * Es de SOLO LECTURA (la tabla solo se añade). El historial de cada ticket está en su propio detalle (R5).
 */

namespace App\Http\Controllers\Records;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'evento' => ['nullable', 'string', 'in:'.implode(',', array_keys(AuditLog::EVENTS))],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'ver' => ['nullable', 'integer'],
        ]);

        $query = AuditLog::query()->with(['actor', 'subject'])
            ->when($filters['evento'] ?? null, fn (Builder $q, $v) => $q->where('event', $v))
            ->when($filters['desde'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['hasta'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['q'] ?? null, function (Builder $q, $v) {
                $like = '%'.addcslashes($v, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('description', 'like', $like)
                    ->orWhere('ip', 'like', $like)
                    ->orWhereHas('actor', fn (Builder $a) => $a->where('name', 'like', $like)->orWhere('email', 'like', $like))
                    ->orWhereHas('subject', fn (Builder $a) => $a->where('name', 'like', $like)->orWhere('email', 'like', $like)));
            })
            ->orderByDesc('created_at')->orderByDesc('id');

        return view('records.audit', [
            'user' => $request->user(),
            'logs' => $query->paginate((int) config('access.records.per_page'))->withQueryString(),
            'filters' => $filters,
            'events' => AuditLog::EVENTS,
            'open' => isset($filters['ver']) ? AuditLog::query()->with(['actor', 'subject'])->find($filters['ver']) : null,
        ]);
    }
}
