<?php

/*
 * Cifras de los informes del equipo (P1): volumen, tiempos, valoración y vivos, en total y por departamento, empresa,
 * prioridad y (solo el administrador) agente. Pantalla: ReportController / reports.index. Plan: docs/plan/p1-informes.md.
 * Cuándo tocarlo: al añadir o redefinir una cifra. Las DEFINICIONES de cada cifra están en este comentario y en la pantalla.
 *
 * Definiciones (si se cambia una, se cambia aquí, en la vista y en ReportTest):
 *  - Creados: tickets con `created_at` dentro del periodo. Resueltos: tickets con `resolved_at` dentro del periodo (aunque se
 *    abrieran antes). Vivos ahora: ni resueltos ni cerrados HOY (no depende del periodo).
 *  - Primera respuesta: minutos entre la creación del ticket y el primer MENSAJE PÚBLICO de un empleado (agente o administrador).
 *    Las notas internas no cuentan (el cliente no las ve). Solo los tickets creados en el periodo que ya tienen respuesta.
 *  - Resolución: horas entre creación y `resolved_at`, de los resueltos en el periodo.
 *  - Valoración media: de las valoraciones HECHAS en el periodo.
 *  - Por agente: los resueltos por quien lo lleva AHORA asignado. Si un ticket cambió de manos antes de resolverse se atribuye
 *    al último; es una aproximación (no se guarda quién lo resolvió) y la pantalla lo dice.
 *
 * Trampas:
 *  - TODO parte de `Ticket::visibleTo($usuario)` (R1, R11, R13): un agente de Soporte no cuenta ni ve Contabilidad en ninguna
 *    cifra, y el de Contabilidad solo la suya. Una consulta suelta sobre `tickets` mezclaría lo que cada persona no puede ver.
 *  - La tabla «por agente» y los nombres de las personas los ve SOLO el administrador (es evaluar a compañeros).
 *  - Sin datos no hay división por cero: las medias salen nulas y la pantalla pone «—».
 *  - Es de solo lectura: ninguna cifra modifica nada y no se exporta (decisión del plan P1: el CSV sería otra vía de sacar datos).
 */

namespace App\Support;

use App\Enums\Department;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TicketReport
{
    /** Máximo de empresas en la tabla (las que más tickets crearon). */
    public const TOP_COMPANIES = 10;

    /** Días máximos de un periodo (3 años): más haría consultas pesadas sin aportar nada. */
    public const MAX_DAYS = 1100;

    private Carbon $from;

    private Carbon $to;

    public function __construct(private readonly User $user, Carbon $from, Carbon $to)
    {
        $this->from = $from->copy()->startOfDay();
        $this->to = $to->copy()->endOfDay();
    }

    /** Tickets visibles para esta persona (R1, R11, R13). Cada cifra parte de aquí. @return Builder<Ticket> */
    private function base(): Builder
    {
        return Ticket::query()->visibleTo($this->user);
    }

    /** @return list<string> estados NO finales */
    private function liveStatuses(): array
    {
        return [TicketStatus::Open->value, TicketStatus::InProgress->value, TicketStatus::PendingCustomer->value];
    }

    /**
     * Cifras globales.
     *
     * @return array{created: int, resolved: int, live: int, unassigned: int, first_response_min: ?float, resolution_hours: ?float, rating_avg: ?float, rating_count: int}
     */
    public function totals(): array
    {
        return [
            'created' => $this->created()->count(),
            'resolved' => $this->resolved()->count(),
            'live' => $this->base()->whereIn('status', $this->liveStatuses())->count(),
            'unassigned' => $this->base()->whereIn('status', $this->liveStatuses())->whereNull('assigned_to')->count(),
            'first_response_min' => $this->firstResponseMinutes($this->created()),
            'resolution_hours' => $this->resolutionHours($this->resolved()),
            'rating_avg' => $this->ratingAverage($this->base()),
            'rating_count' => $this->ratingsQuery($this->base())->count(),
        ];
    }

    /**
     * Por departamento (solo los que esta persona atiende).
     *
     * @return list<array{department: Department, created: int, resolved: int, live: int, resolution_hours: ?float, rating_avg: ?float}>
     */
    public function byDepartment(): array
    {
        $rows = [];
        foreach (Department::cases() as $department) {
            if (! $this->user->canWorkOn($department)) {
                continue;
            }
            $in = fn (Builder $q): Builder => $q->where('department', $department->value);
            $rows[] = [
                'department' => $department,
                'created' => $in($this->created())->count(),
                'resolved' => $in($this->resolved())->count(),
                'live' => $in($this->base()->whereIn('status', $this->liveStatuses()))->count(),
                'resolution_hours' => $this->resolutionHours($in($this->resolved())),
                'rating_avg' => $this->ratingAverage($in($this->base())),
            ];
        }

        return $rows;
    }

    /**
     * Las empresas que más tickets crearon en el periodo.
     *
     * @return list<array{name: string, created: int, resolved: int, rating_avg: ?float}>
     */
    public function byCompany(): array
    {
        $created = $this->created()->select('company_id', DB::raw('count(*) as total'))->groupBy('company_id')->orderByDesc('total')->limit(self::TOP_COMPANIES)->pluck('total', 'company_id');
        $names = Company::query()->whereIn('id', $created->keys())->pluck('name', 'id');

        $rows = [];
        foreach ($created as $companyId => $total) {
            $in = fn (Builder $q): Builder => $q->where('company_id', $companyId);
            $rows[] = [
                'name' => (string) ($names[$companyId] ?? '—'),
                'created' => (int) $total,
                'resolved' => $in($this->resolved())->count(),
                'rating_avg' => $this->ratingAverage($in($this->base())),
            ];
        }

        return $rows;
    }

    /**
     * Tickets vivos ahora por prioridad (interna).
     *
     * @return array<string, int> valor de Priority → cuántos
     */
    public function livePriorities(): array
    {
        $counts = $this->base()->whereIn('status', $this->liveStatuses())->select('priority', DB::raw('count(*) as total'))->groupBy('priority')->pluck('total', 'priority');

        $out = [];
        foreach (Priority::cases() as $priority) {
            $out[$priority->value] = (int) ($counts[$priority->value] ?? 0);
        }

        return $out;
    }

    /**
     * Por agente: SOLO para el administrador. Otro rol recibe una lista vacía (la pantalla ni pinta la tabla).
     *
     * @return list<array{name: string, team: string, resolved: int, live: int, resolution_hours: ?float, rating_avg: ?float, rating_count: int}>
     */
    public function byAgent(): array
    {
        if (! $this->user->hasRole(UserRole::Admin)) {
            return [];
        }

        $rows = [];
        $agents = User::query()->where('role', UserRole::Agent->value)->whereNotNull('activated_at')->orderBy('name')->get();
        foreach ($agents as $agent) {
            $in = fn (Builder $q): Builder => $q->where('assigned_to', $agent->id);
            $rows[] = [
                'name' => $agent->name,
                'team' => $agent->team?->label() ?? '—',
                'resolved' => $in($this->resolved())->count(),
                'live' => $in($this->base()->whereIn('status', $this->liveStatuses()))->count(),
                'resolution_hours' => $this->resolutionHours($in($this->resolved())),
                'rating_avg' => $this->ratingAverage($in($this->base())),
                'rating_count' => $this->ratingsQuery($in($this->base()))->count(),
            ];
        }

        return $rows;
    }

    /** @return Builder<Ticket> */
    private function created(): Builder
    {
        return $this->base()->whereBetween('created_at', [$this->from, $this->to]);
    }

    /** @return Builder<Ticket> */
    private function resolved(): Builder
    {
        return $this->base()->whereBetween('resolved_at', [$this->from, $this->to]);
    }

    /** Media de horas creación → resolución. @param Builder<Ticket> $tickets */
    private function resolutionHours(Builder $tickets): ?float
    {
        $minutes = (clone $tickets)->whereNotNull('resolved_at')->avg(DB::raw('TIMESTAMPDIFF(MINUTE, tickets.created_at, tickets.resolved_at)'));

        return $minutes === null ? null : round(((float) $minutes) / 60, 1);
    }

    /** Media de minutos creación → primera respuesta pública del equipo. @param Builder<Ticket> $tickets */
    private function firstResponseMinutes(Builder $tickets): ?float
    {
        $first = DB::table('ticket_messages')
            ->join('users', 'users.id', '=', 'ticket_messages.author_id')
            ->where('ticket_messages.kind', 'public')
            ->whereIn('users.role', [UserRole::Agent->value, UserRole::Admin->value])
            ->select('ticket_messages.ticket_id', DB::raw('MIN(ticket_messages.created_at) as first_at'))
            ->groupBy('ticket_messages.ticket_id');

        $minutes = (clone $tickets)->joinSub($first, 'primera', 'primera.ticket_id', '=', 'tickets.id')
            ->avg(DB::raw('TIMESTAMPDIFF(MINUTE, tickets.created_at, primera.first_at)'));

        return $minutes === null ? null : round((float) $minutes, 1);
    }

    /** Valoraciones hechas en el periodo sobre estos tickets. @param Builder<Ticket> $tickets @return \Illuminate\Database\Query\Builder */
    private function ratingsQuery(Builder $tickets)
    {
        return DB::table('ticket_ratings')
            ->whereIn('ticket_id', (clone $tickets)->select('tickets.id'))
            ->whereBetween('created_at', [$this->from, $this->to]);
    }

    /** @param Builder<Ticket> $tickets */
    private function ratingAverage(Builder $tickets): ?float
    {
        $avg = $this->ratingsQuery($tickets)->avg('score');

        return $avg === null ? null : round((float) $avg, 2);
    }

    /** «2 h 15 min», «3 días»… Para pintar las medias de tiempo. */
    public static function humanMinutes(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        if ($minutes < 60) {
            return round($minutes).' min';
        }
        if ($minutes < 60 * 48) {
            $h = intdiv((int) round($minutes), 60);
            $m = (int) round($minutes) % 60;

            return $m === 0 ? "{$h} h" : "{$h} h {$m} min";
        }

        return number_format($minutes / 1440, 1, ',', '.').' días';
    }
}
