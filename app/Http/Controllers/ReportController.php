<?php

/*
 * «Informes» del equipo (agentes y administrador): cifras de volumen, tiempos, valoración y vivos. Cuándo tocarlo: al cambiar la
 * pantalla; las cifras y sus definiciones están en App\Support\TicketReport. Plan: docs/plan/p1-informes.md.
 *
 * Trampas:
 *  - Solo el equipo (habilidad `view-reports`); un cliente recibe 403. Cada cifra parte de `Ticket::visibleTo` (R13).
 *  - Los filtros del periodo se validan en servidor (fechas, orden y un máximo de días): sin eso, un periodo enorme sería una
 *    consulta enorme.
 */

namespace App\Http\Controllers;

use App\Support\TicketReport;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    /** Atajos de periodo: días hacia atrás desde hoy. */
    public const PRESETS = [30 => 'Últimos 30 días', 90 => 'Últimos 90 días', 365 => 'Últimos 12 meses'];

    public function index(Request $request): View
    {
        Gate::authorize('view-reports');
        $user = $request->user();
        abort_unless($user !== null, 403);

        $filters = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);

        $tz = (string) config('app.timezone');
        $to = isset($filters['hasta']) ? Carbon::parse($filters['hasta'], $tz) : Carbon::now($tz);
        $from = isset($filters['desde']) ? Carbon::parse($filters['desde'], $tz) : $to->copy()->subDays(29);
        // (El formato Y-m-d ya está validado arriba: `parse` no puede fallar con estas cadenas.)

        // Un periodo de más de 3 años no se calcula: se recorta al máximo y la pantalla lo avisa.
        $trimmed = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) > TicketReport::MAX_DAYS;
        if ($trimmed) {
            $from = $to->copy()->subDays(TicketReport::MAX_DAYS);
        }

        $report = new TicketReport($user, $from, $to);

        return view('reports.index', [
            'user' => $user,
            'from' => $from,
            'to' => $to,
            'trimmed' => $trimmed,
            'presets' => self::PRESETS,
            'totals' => $report->totals(),
            'byDepartment' => $report->byDepartment(),
            'byCompany' => $report->byCompany(),
            'priorities' => $report->livePriorities(),
            'byAgent' => $report->byAgent(),
        ]);
    }
}
