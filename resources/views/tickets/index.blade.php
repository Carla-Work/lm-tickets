{{--
    «Mis tickets» (usuario de empresa) y «Tickets de mi empresa» (administrador de empresa).
    Mockups validados: mis-tickets-a-lista-tabla.html, mis-tickets-b-administrador-empresa.html y mis-tickets-c-filtros-avanzados.html
    (filtros: App\Support\TicketFilters).
    Cuándo tocarlo: al cambiar la lista. «Respuesta nueva» y la fila en negrita salen de los avisos sin leer de esta persona ($unread).
    Variables: $tickets (paginado), $total, $summary, $filters, $products, $requesters (vacío salvo administrador de empresa),
    $active (filtros aplicados), $quickViews.
--}}
@extends('layouts.panel')
@section('titulo', $user->role === \App\Enums\UserRole::CompanyAdmin ? 'Tickets de mi empresa' : 'Mis tickets')

@section('contenido')
    @php $isCompanyAdmin = $user->role === \App\Enums\UserRole::CompanyAdmin; @endphp
    <div class="titulo-fila">
        <h1>{{ $isCompanyAdmin ? 'Tickets de mi empresa' : 'Mis tickets' }}</h1>
        <a class="boton principal" href="{{ route('tickets.create') }}">+ Nuevo ticket</a>
    </div>

    <section class="resumen" aria-label="Resumen por estado">
        <a class="{{ $summary['pending'] > 0 ? 'atencion' : '' }}" href="{{ route('tickets.index', ['estado' => 'pending_customer']) }}"><strong>{{ $summary['pending'] }}</strong>Pendiente de tu respuesta</a>
        <a href="{{ route('tickets.index', ['estado' => 'open']) }}"><strong>{{ $summary['open'] }}</strong>Abiertos</a>
        <a href="{{ route('tickets.index', ['estado' => 'in_progress']) }}"><strong>{{ $summary['in_progress'] }}</strong>En curso</a>
        <a href="{{ route('tickets.index', ['estado' => 'resolved']) }}"><strong>{{ $summary['finished'] }}</strong>Resueltos o cerrados</a>
    </section>

    @include('partials.vistas-rapidas')

    <form class="filtros" action="{{ route('tickets.index') }}" method="get" aria-label="Filtrar tickets">
        <div class="buscar">
            <label for="q">Buscar</label>
            <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Asunto o número de ticket">
        </div>
        <div>
            <label for="f-estado">Estado</label>
            <select id="f-estado" name="estado">
                <option value="">Todos</option>
                @foreach (\App\Enums\TicketStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(($filters['estado'] ?? '') === $status->value)>{{ $status->customerLabel() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-dep">Departamento</label>
            <select id="f-dep" name="departamento">
                <option value="">Todos</option>
                @foreach (\App\Enums\Department::cases() as $department)
                    <option value="{{ $department->value }}" @selected(($filters['departamento'] ?? '') === $department->value)>{{ $department->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="casilla-mensajes">
            <label for="f-msg"><input type="checkbox" id="f-msg" name="en_mensajes" value="1" @checked(! empty($filters['en_mensajes']))> Buscar también en el texto de las respuestas</label>
        </div>
        <details class="mas-filtros" @if (collect($filters)->only(['producto', 'usuario', 'desde', 'hasta', 'orden'])->filter()->isNotEmpty()) open @endif>
            <summary>Más filtros</summary>
            <div class="rejilla">
                @if ($products->isNotEmpty())
                    <div>
                        <label for="f-prod">Producto</label>
                        <select id="f-prod" name="producto">
                            <option value="">Todos</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}" @selected((string) ($filters['producto'] ?? '') === (string) $product->id)>{{ $product->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if ($isCompanyAdmin)
                    <div>
                        <label for="f-quien">Abierto por</label>
                        <select id="f-quien" name="usuario">
                            <option value="">Todos</option>
                            @foreach ($requesters as $requester)
                                <option value="{{ $requester->id }}" @selected((string) ($filters['usuario'] ?? '') === (string) $requester->id)>{{ $requester->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label for="f-desde">Abierto desde</label>
                    <input type="date" id="f-desde" name="desde" value="{{ $filters['desde'] ?? '' }}">
                </div>
                <div>
                    <label for="f-hasta">Abierto hasta</label>
                    <input type="date" id="f-hasta" name="hasta" value="{{ $filters['hasta'] ?? '' }}">
                </div>
                <div>
                    <label for="f-orden">Ordenar por</label>
                    <select id="f-orden" name="orden">
                        @foreach (\App\Support\TicketFilters::ORDERS as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['orden'] ?? \App\Support\TicketFilters::DEFAULT_ORDER) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </details>
        <button type="submit" class="boton secundario">Filtrar</button>
    </form>

    @include('partials.filtros-aplicados', ['clearUrl' => route('tickets.index')])

    <div class="tabla-caja">
        <table>
            <caption>{{ $tickets->total() }} de {{ $total }} tickets{{ $isCompanyAdmin ? ' de '.$user->company?->name : '' }}</caption>
            <thead>
                <tr>
                    <th scope="col">Nº</th>
                    <th scope="col">Asunto</th>
                    @if ($isCompanyAdmin)<th scope="col">Abierto por</th>@else<th scope="col">Producto</th>@endif
                    <th scope="col">Departamento</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Última actividad</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tickets as $ticket)
                    <tr @class(['sin-leer' => isset($unread[$ticket->id])])>
                        <td class="num" data-etiqueta="Nº">#{{ $ticket->number }}</td>
                        <td class="asunto-celda" data-etiqueta="Asunto">
                            <a class="asunto" href="{{ route('tickets.show', $ticket) }}">{{ $ticket->subject }}</a>
                            @if (collect($unread[$ticket->id] ?? [])->intersect(['replied', 'status_changed', 'department_changed'])->isNotEmpty())<span class="nuevo">Respuesta nueva</span>@endif
                            @if ($isCompanyAdmin && $ticket->product)<span class="secundario">Producto: {{ $ticket->product->name }}</span>@endif
                        </td>
                        @if ($isCompanyAdmin)
                            <td data-etiqueta="Abierto por"><strong>{{ $ticket->requester->name }}</strong></td>
                        @else
                            <td data-etiqueta="Producto">{{ $ticket->product?->name ?? '—' }}</td>
                        @endif
                        <td data-etiqueta="Departamento"><span class="dep {{ $ticket->department->cssClass() }}">{{ $ticket->department->label() }}</span></td>
                        <td data-etiqueta="Estado"><span class="estado {{ $ticket->status->cssClass() }}">{{ $ticket->status->customerLabel() }}</span></td>
                        <td data-etiqueta="Última actividad">{{ $ticket->last_activity_at?->timezone(config('app.timezone'))->translatedFormat('j M, H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">No hay tickets que mostrar.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($tickets->hasPages())
        <div class="paginacion">
            <span>Página {{ $tickets->currentPage() }} de {{ $tickets->lastPage() }}</span>
            <nav aria-label="Páginas">
                @if ($tickets->previousPageUrl())<a href="{{ $tickets->previousPageUrl() }}" rel="prev">‹ Anterior</a>@endif
                @if ($tickets->nextPageUrl())<a href="{{ $tickets->nextPageUrl() }}" rel="next">Siguiente ›</a>@endif
            </nav>
        </div>
    @endif
@endsection
