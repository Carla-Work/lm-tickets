<?php

/*
 * Búsqueda y filtros de las listas de tickets: la bandeja del equipo (InboxController) y «Mis tickets» del cliente
 * (TicketController). P1, mockups validados: bandeja-b-filtros-avanzados y mis-tickets-c-filtros-avanzados.
 * Cuándo tocarlo: al añadir o cambiar un filtro. Plan y criterio: docs/plan/p1-busqueda-y-filtros.md.
 *
 * Por qué es UN solo sitio: si equipo y cliente tuvieran cada uno su copia, un filtro nuevo se arreglaría en una y se
 * olvidaría en la otra, y las reglas de qué ve cada cual (R1, R2, R13) acabarían divergiendo.
 *
 * Trampas:
 *  - `apply()` SOLO restringe: recibe una consulta que ya parte de `Ticket::visibleTo($usuario)` y nunca la amplía
 *    (todo se añade con Y). Si algún día alguien le pasa `Ticket::query()` a pelo, entrega tickets ajenos.
 *  - La búsqueda en el texto de los mensajes usa `TicketMessage::visibleTo($usuario)`, el MISMO ámbito que el detalle:
 *    el cliente solo encuentra palabras de mensajes públicos y nunca se entera, ni por un acierto, de que existe una
 *    nota interna (R2). No sustituir por un `where` directo sobre `ticket_messages`.
 *  - Los campos solo del equipo (empresa, asignado, categoría, «sin actividad») se ignoran si quien pregunta no es del
 *    equipo, aunque la validación los dejara pasar; y «abierto por» solo vale para el administrador de empresa.
 *  - «Sin actividad desde hace N días» implica «sin resolver ni cerrar»: un ticket ya terminado está siempre quieto y
 *    llenaría la lista de ruido.
 *  - Las etiquetas de la barra de filtros aplicados salen SOLO de las listas que el controlador ya limitó a lo que
 *    esa persona puede ver; un id que no esté ahí no revela el nombre de una empresa o persona ajena.
 */

namespace App\Support;

use App\Enums\Department;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketFilters
{
    /** Valor del filtro «Asignado a» para los tickets sin persona asignada. */
    public const UNASSIGNED = 'sin';

    /** Orden por defecto y los demás ofrecidos. */
    public const DEFAULT_ORDER = 'actividad_desc';

    public const ORDERS = [
        'actividad_desc' => 'Última actividad (recientes primero)',
        'actividad_asc' => 'Última actividad (antiguos primero)',
        'apertura_desc' => 'Fecha de apertura (recientes primero)',
        'numero' => 'Número (más alto primero)',
    ];

    /** Órdenes SOLO del equipo: la prioridad es interna y el cliente no debe ni verla nombrada en un selector. */
    public const TEAM_ORDERS = [
        'prioridad' => 'Prioridad (urgentes primero)',
    ];

    /** Valores del filtro «Valoración» (solo equipo): el cliente ve su propia valoración, no filtra por ella. */
    public const RATINGS = [
        'low' => 'Mala (1-2)',
        'mid' => 'Normal (3)',
        'good' => 'Buena (4-5)',
        'rated' => 'Valorados',
        'unrated' => 'Sin valorar',
    ];

    /** Valor de «Prioridad» que junta Alta y Urgente (vista rápida «Urgentes y altas»). */
    public const HIGH_OR_URGENT = 'high_urgent';

    /** @return array<string, string> los órdenes que esta persona puede elegir */
    public static function orders(bool $team): array
    {
        return $team ? self::TEAM_ORDERS + self::ORDERS : self::ORDERS;
    }

    /** Plazos ofrecidos en «sin actividad desde hace». */
    public const QUIET_DAYS = [3, 7, 14, 30];

    /**
     * Reglas de validación de los filtros. Los campos del equipo solo se aceptan si quien pregunta es del equipo.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(bool $team): array
    {
        $common = [
            'q' => ['nullable', 'string', 'max:100'],
            'en_mensajes' => ['nullable', 'boolean'],
            'estado' => ['nullable', 'string', Rule::in(array_column(TicketStatus::cases(), 'value'))],
            'departamento' => ['nullable', 'string', Rule::in(array_column(Department::cases(), 'value'))],
            'producto' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'vivos' => ['nullable', 'boolean'],
            'orden' => ['nullable', 'string', Rule::in(array_keys(self::orders($team)))],
        ];

        return $team
            ? $common + [
                'empresa' => ['nullable', 'integer'],
                'asignado' => ['nullable', 'string', 'regex:/^('.self::UNASSIGNED.'|\d+)$/'],
                'categoria' => ['nullable', 'integer'],
                'quieta' => ['nullable', 'integer', Rule::in(self::QUIET_DAYS)],
                // Prioridad y etiquetas son INTERNAS: el cliente no las tiene ni como filtro.
                'prioridad' => ['nullable', 'string', Rule::in([...array_column(Priority::cases(), 'value'), self::HIGH_OR_URGENT])],
                'etiqueta' => ['nullable', 'integer'],
                'valoracion' => ['nullable', 'string', Rule::in(array_keys(self::RATINGS))],
            ]
            : $common + [
                'usuario' => ['nullable', 'integer'],
            ];
    }

    /**
     * Aplica los filtros (y el orden) a una lista que YA parte de `Ticket::visibleTo($user)`.
     *
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $f  filtros ya validados
     * @return Builder<Ticket>
     */
    public static function apply(Builder $query, array $f, User $user): Builder
    {
        $team = $user->isInternal();
        $finished = [TicketStatus::Resolved->value, TicketStatus::Closed->value];

        $query
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['departamento'] ?? null, fn ($q, $v) => $q->where('department', $v))
            ->when($f['producto'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', self::day($v)->startOfDay()))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', self::day($v)->endOfDay()))
            ->when(! empty($f['vivos']) || ($team && ! empty($f['quieta'])), fn ($q) => $q->whereNotIn('status', $finished));

        if ($team) {
            $query
                ->when($f['empresa'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
                ->when($f['categoria'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
                ->when($f['prioridad'] ?? null, fn ($q, $v) => $v === self::HIGH_OR_URGENT
                    ? $q->whereIn('priority', [Priority::High->value, Priority::Urgent->value])
                    : $q->where('priority', $v))
                ->when($f['valoracion'] ?? null, fn ($q, $v) => match ($v) {
                    'low' => $q->whereHas('rating', fn ($r) => $r->where('score', '<=', 2)),
                    'mid' => $q->whereHas('rating', fn ($r) => $r->where('score', 3)),
                    'good' => $q->whereHas('rating', fn ($r) => $r->where('score', '>=', 4)),
                    'rated' => $q->has('rating'),
                    default => $q->doesntHave('rating'),
                })
                ->when($f['etiqueta'] ?? null, fn ($q, $v) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $v)))
                ->when($f['asignado'] ?? null, fn ($q, $v) => $v === self::UNASSIGNED
                    ? $q->whereNull('assigned_to')
                    : $q->where('assigned_to', (int) $v))
                ->when($f['quieta'] ?? null, fn ($q, $v) => $q->where('last_activity_at', '<=', now()->subDays((int) $v)));
        } elseif ($user->hasRole(UserRole::CompanyAdmin)) {
            // «Abierto por» solo se aplica al administrador de empresa; para un usuario normal ya está limitado a los suyos.
            $query->when($f['usuario'] ?? null, fn ($q, $v) => $q->where('requester_id', $v));
        }

        $query->when($f['q'] ?? null, function ($q, $v) use ($f, $user, $team) {
            // `addcslashes`: que % y _ escritos por la persona no sean comodines.
            $like = '%'.addcslashes(ltrim($v, '#'), '%_\\').'%';
            $q->where(function ($w) use ($like, $f, $user, $team) {
                $w->where('subject', 'like', $like)
                    ->orWhereRaw("CONCAT(year, '-', LPAD(sequence, 4, '0')) like ?", [$like]);

                if ($team) {
                    $w->orWhereHas('requester', fn ($r) => $r->where('name', 'like', $like))
                        ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $like));
                }

                if (! empty($f['en_mensajes'])) {
                    // Mismo ámbito que el detalle (R2): el cliente solo busca en mensajes públicos.
                    $w->orWhereHas('messages', fn ($m) => $m->visibleTo($user)->where('body', 'like', $like));
                }
            });
        });

        // Un orden del equipo pedido por un cliente (no pasa la validación) cae al orden por defecto.
        $order = $f['orden'] ?? self::DEFAULT_ORDER;
        if (! $team && ! array_key_exists($order, self::ORDERS)) {
            $order = self::DEFAULT_ORDER;
        }

        return match ($order) {
            'prioridad' => $query->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")->orderByDesc('last_activity_at')->orderByDesc('id'),
            'actividad_asc' => $query->orderBy('last_activity_at')->orderBy('id'),
            'apertura_desc' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'numero' => $query->orderByDesc('year')->orderByDesc('sequence'),
            default => $query->orderByDesc('last_activity_at')->orderByDesc('id'),
        };
    }

    /**
     * Filtros puestos, para la barra «Filtros aplicados»: cada uno con su texto y el enlace que lo quita (y solo ese).
     *
     * @param  array<string, mixed>  $filters  filtros ya validados
     * @param  array<string, callable(string): ?string>  $names  nombre legible del valor de los filtros por id; devuelve null si el id no es de una lista que la persona pueda ver
     * @return list<array{label: string, remove: string}>
     */
    public static function active(Request $request, string $route, array $filters, array $names, bool $customer): array
    {
        $labels = [
            'estado' => 'Estado', 'departamento' => 'Departamento', 'empresa' => 'Empresa', 'asignado' => 'Asignado a',
            'producto' => 'Producto', 'categoria' => 'Categoría', 'usuario' => 'Abierto por', 'desde' => 'Abierto desde',
            'hasta' => 'Abierto hasta', 'quieta' => 'Sin actividad desde hace', 'orden' => 'Orden',
            'prioridad' => 'Prioridad', 'etiqueta' => 'Etiqueta', 'valoracion' => 'Valoración',
        ];
        $query = $request->except('page');
        $out = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || $value === false || $key === 'en_mensajes') {
                continue;
            }

            $without = $key === 'q' ? array_diff_key($query, ['q' => 1, 'en_mensajes' => 1]) : array_diff_key($query, [$key => 1]);

            if ($key === 'q') {
                $label = 'Texto: «'.$value.'»'.(! empty($filters['en_mensajes']) ? ' (también en '.($customer ? 'las respuestas' : 'los mensajes y las notas').')' : '');
            } elseif ($key === 'vivos') {
                $label = 'Sin resolver ni cerrar';
            } else {
                $shown = (string) $value;
                $shown = match ($key) {
                    'estado' => ($s = TicketStatus::tryFrom($shown)) === null ? $shown : ($customer ? $s->customerLabel() : $s->label()),
                    'departamento' => Department::tryFrom($shown)?->label() ?? $shown,
                    'desde', 'hasta' => Carbon::parse($shown)->format('d/m/Y'),
                    'quieta' => $shown.' días',
                    'orden' => self::orders(! $customer)[$shown] ?? $shown,
                    'valoracion' => isset(self::RATINGS[$shown]) ? mb_strtolower(self::RATINGS[$shown]) : $shown,
                    'prioridad' => $shown === self::HIGH_OR_URGENT ? 'alta o urgente' : (Priority::tryFrom($shown)?->label() ?? $shown),
                    'asignado' => $shown === self::UNASSIGNED ? 'Sin asignar' : ($names['asignado'] ?? fn () => null)($shown) ?? '—',
                    default => isset($names[$key]) ? ($names[$key]($shown) ?? '—') : $shown,
                };
                $label = ($labels[$key] ?? $key).': '.$shown;
            }

            $out[] = ['label' => $label, 'remove' => route($route, $without)];
        }

        return $out;
    }

    /**
     * Enlaces de «Vistas rápidas»: cada uno solo pone filtros. Se marca como actual la que coincide EXACTAMENTE con
     * lo que hay en la dirección (sin la página).
     *
     * @param  list<array{label: string, params: array<string, mixed>}>  $views
     * @return list<array{label: string, url: string, current: bool}>
     */
    public static function quickViews(Request $request, string $route, array $views): array
    {
        $current = array_filter($request->except('page'), fn ($v) => $v !== null && $v !== '');

        return array_map(function (array $view) use ($route, $current) {
            $a = array_map('strval', $view['params']);
            $b = array_map('strval', $current);
            ksort($a);
            ksort($b);

            return ['label' => $view['label'], 'url' => route($route, $view['params']), 'current' => $a === $b];
        }, $views);
    }

    /** Un día elegido en un filtro, interpretado en la zona horaria de la aplicación. */
    private static function day(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $date, (string) config('app.timezone'))->startOfDay();
    }
}
