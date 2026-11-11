{{--
    «Bandeja de tickets» del equipo (agentes y administrador). Mockups validados: bandeja-a-lista-tabla.html y bandeja-b-filtros-avanzados.html (filtros: App\Support\TicketFilters).
    Cuándo tocarla: al cambiar la bandeja. La fila en negrita y «Respuesta del cliente» salen de los avisos sin leer
    de ESTA persona ($unread: ticket_id → tipos de aviso); ver un ticket los marca leídos.
    Trampa (R13): $departments, $companies y $summary ya vienen limitados por Ticket::visibleTo en el
    controlador; esta plantilla no decide qué se ve, solo cómo se pinta.
    Variables: $tickets (paginado), $total, $summary, $filters, $unread, $departments, $companies, $products, $tags, $categories, $staff,
    $active (filtros aplicados), $quickViews.
--}}
@extends('layouts.panel')
@section('titulo', 'Bandeja de tickets')

@section('contenido')
    <div class="titulo-fila">
        <h1>Bandeja de tickets</h1>
    </div>

    <section class="resumen" aria-label="Resumen por estado">
        <a class="{{ $summary['unassigned'] > 0 ? 'atencion' : '' }}" href="{{ route('inbox', ['asignado' => 'sin']) }}"><strong>{{ $summary['unassigned'] }}</strong>Sin asignar</a>
        <a href="{{ route('inbox', ['estado' => 'open']) }}"><strong>{{ $summary['open'] }}</strong>Abiertos</a>
        <a href="{{ route('inbox', ['estado' => 'in_progress']) }}"><strong>{{ $summary['in_progress'] }}</strong>En curso</a>
        <a href="{{ route('inbox', ['estado' => 'pending_customer']) }}"><strong>{{ $summary['pending'] }}</strong>Pendiente de cliente</a>
        <a href="{{ route('inbox', ['estado' => 'resolved']) }}"><strong>{{ $summary['finished'] }}</strong>Resueltos o cerrados</a>
    </section>

    @include('partials.vistas-rapidas')

    <form class="filtros" action="{{ route('inbox') }}" method="get" aria-label="Filtrar tickets">
        <div class="buscar">
            <label for="q">Buscar</label>
            <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Asunto, número, persona o empresa">
        </div>
        <div>
            <label for="f-estado">Estado</label>
            <select id="f-estado" name="estado">
                <option value="">Todos</option>
                @foreach (\App\Enums\TicketStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(($filters['estado'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-dep">Departamento</label>
            <select id="f-dep" name="departamento">
                <option value="">Todos</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->value }}" @selected(($filters['departamento'] ?? '') === $department->value)>{{ $department->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-emp">Empresa</label>
            <select id="f-emp" name="empresa">
                <option value="">Todas</option>
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}" @selected((string) ($filters['empresa'] ?? '') === (string) $company->id)>{{ $company->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-asig">Asignado a</label>
            <select id="f-asig" name="asignado">
                <option value="">Todos</option>
                <option value="sin" @selected(($filters['asignado'] ?? '') === 'sin')>Sin asignar</option>
                @foreach ($staff->groupBy(fn ($p) => $p->team?->label() ?? 'Administración') as $group => $people)
                    <optgroup label="{{ $group }}">
                        @foreach ($people as $person)
                            <option value="{{ $person->id }}" @selected((string) ($filters['asignado'] ?? '') === (string) $person->id)>{{ $person->name }}@if ($person->is($user)) (tú)@endif</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div class="casilla-mensajes">
            <label for="f-msg"><input type="checkbox" id="f-msg" name="en_mensajes" value="1" @checked(! empty($filters['en_mensajes']))> Buscar también en el texto de los mensajes y las notas internas</label>
        </div>
        <details class="mas-filtros" @if (collect($filters)->only(['producto', 'categoria', 'prioridad', 'etiqueta', 'desde', 'hasta', 'quieta', 'orden'])->filter()->isNotEmpty()) open @endif>
            <summary>Más filtros</summary>
            <div class="rejilla">
                <div>
                    <label for="f-prio">Prioridad</label>
                    <select id="f-prio" name="prioridad">
                        <option value="">Todas</option>
                        @foreach (\App\Enums\Priority::cases() as $priority)
                            <option value="{{ $priority->value }}" @selected(($filters['prioridad'] ?? '') === $priority->value)>{{ $priority->label() }}</option>
                        @endforeach
                        <option value="{{ \App\Support\TicketFilters::HIGH_OR_URGENT }}" @selected(($filters['prioridad'] ?? '') === \App\Support\TicketFilters::HIGH_OR_URGENT)>Alta o urgente</option>
                    </select>
                </div>
                <div>
                    <label for="f-etq">Etiqueta</label>
                    <select id="f-etq" name="etiqueta">
                        <option value="">Todas</option>
                        @foreach ($tags as $tag)
                            <option value="{{ $tag->id }}" @selected((string) ($filters['etiqueta'] ?? '') === (string) $tag->id)>{{ $tag->name }}@unless ($tag->active) (desactivada)@endunless</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-prod">Producto</label>
                    <select id="f-prod" name="producto">
                        <option value="">Todos</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((string) ($filters['producto'] ?? '') === (string) $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-cat">Categoría</label>
                    <select id="f-cat" name="categoria">
                        <option value="">Todas</option>
                        @foreach ($categories as $department => $list)
                            <optgroup label="{{ \App\Enums\Department::from($department)->label() }}">
                                @foreach ($list as $category)
                                    <option value="{{ $category->id }}" @selected((string) ($filters['categoria'] ?? '') === (string) $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-desde">Abierto desde</label>
                    <input type="date" id="f-desde" name="desde" value="{{ $filters['desde'] ?? '' }}">
                </div>
                <div>
                    <label for="f-hasta">Abierto hasta</label>
                    <input type="date" id="f-hasta" name="hasta" value="{{ $filters['hasta'] ?? '' }}">
                </div>
                <div>
                    <label for="f-quieta">Sin actividad desde hace</label>
                    <select id="f-quieta" name="quieta">
                        <option value="">—</option>
                        @foreach (\App\Support\TicketFilters::QUIET_DAYS as $days)
                            <option value="{{ $days }}" @selected((string) ($filters['quieta'] ?? '') === (string) $days)>{{ $days }} días</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-orden">Ordenar por</label>
                    <select id="f-orden" name="orden">
                        @foreach (\App\Support\TicketFilters::orders(true) as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['orden'] ?? \App\Support\TicketFilters::DEFAULT_ORDER) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </details>
        <button type="submit" class="boton secundario">Filtrar</button>
    </form>

    @include('partials.filtros-aplicados', ['clearUrl' => route('inbox')])

    <div class="tabla-caja">
        <table>
            <caption>{{ $tickets->total() }} de {{ $total }} tickets</caption>
            <thead>
                <tr>
                    <th scope="col">Nº</th>
                    <th scope="col">Asunto</th>
                    <th scope="col">Abierto por</th>
                    <th scope="col">Departamento</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Asignado a</th>
                    <th scope="col">Última actividad</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tickets as $ticket)
                    <tr @class(['sin-leer' => isset($unread[$ticket->id])])>
                        <td class="num" data-etiqueta="Nº">#{{ $ticket->number }}</td>
                        <td class="asunto-celda" data-etiqueta="Asunto">
                            <a class="asunto" href="{{ route('tickets.show', $ticket) }}">{{ $ticket->subject }}</a>
                            @if ($ticket->priority !== \App\Enums\Priority::Normal)<span class="prio {{ $ticket->priority->cssClass() }}">{{ $ticket->priority->badge() }}</span>@endif
                            @if (in_array('client_replied', $unread[$ticket->id] ?? [], true))<span class="aviso-cliente">Respuesta del cliente</span>@endif
                            @if ($ticket->product)<span class="secundario">Producto: {{ $ticket->product->name }}</span>@endif
                            @if ($ticket->tags->isNotEmpty())<span>@foreach ($ticket->tags as $tag)<span class="etq">{{ $tag->name }}</span>@endforeach</span>@endif
                        </td>
                        <td class="solicitante" data-etiqueta="Abierto por"><strong>{{ $ticket->requester->name }}</strong><small>{{ $ticket->requester->company?->name }}</small></td>
                        <td data-etiqueta="Departamento"><span class="dep {{ $ticket->department->cssClass() }}">{{ $ticket->department->label() }}</span></td>
                        <td data-etiqueta="Estado"><span class="estado {{ $ticket->status->cssClass() }}">{{ $ticket->status->label() }}</span></td>
                        <td data-etiqueta="Asignado a">
                            @if ($ticket->assignee)
                                <span>{{ $ticket->assignee->name }}</span><small class="secundario">{{ $ticket->assignee->team?->label() ?? $ticket->assignee->role->label() }}</small>
                            @else
                                <span class="sin-asignar">Sin asignar</span>
                            @endif
                        </td>
                        <td data-etiqueta="Última actividad">{{ $ticket->last_activity_at?->timezone(config('app.timezone'))->translatedFormat('j M, H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">No hay tickets que mostrar.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($tickets->hasPages())
        <div class="paginacion">
            <span>Mostrando {{ $tickets->firstItem() }}–{{ $tickets->lastItem() }} de {{ $tickets->total() }}</span>
            <nav aria-label="Paginación">
                @if ($tickets->previousPageUrl())<a href="{{ $tickets->previousPageUrl() }}" rel="prev">‹ Anterior</a>@endif
                <span aria-current="page">{{ $tickets->currentPage() }}</span>
                @if ($tickets->nextPageUrl())<a href="{{ $tickets->nextPageUrl() }}" rel="next">Siguiente ›</a>@endif
            </nav>
        </div>
    @endif
@endsection
