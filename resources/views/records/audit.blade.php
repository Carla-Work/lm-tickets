{{--
    «Auditoría» (Registros, solo el administrador): quién hizo qué fuera de los tickets. Cuándo tocarla: al cambiar la pantalla.
    Diseño de referencia: la auditoría de asesoria (filtros por hecho y fechas, tabla y detalle), con la paleta de
    tickets. Sin mockup a petición de la usuaria (2026-11-10).
    Variables: $logs (paginado), $filters, $events, $open.
--}}
@extends('layouts.panel')
@section('titulo', 'Auditoría')

@section('contenido')
    <p class="migas">Registros › Auditoría</p>
    <div class="titulo-fila">
        <h1>Auditoría</h1>
    </div>

    <form class="filtros" action="{{ route('records.audit') }}" method="get" aria-label="Filtrar la auditoría">
        <div class="buscar">
            <label for="q">Buscar</label>
            <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Descripción, persona, correo o IP">
        </div>
        <div>
            <label for="f-evento">Hecho</label>
            <select id="f-evento" name="evento">
                <option value="">Todos</option>
                @foreach ($events as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['evento'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-desde">Desde</label>
            <input type="date" id="f-desde" name="desde" value="{{ $filters['desde'] ?? '' }}">
        </div>
        <div>
            <label for="f-hasta">Hasta</label>
            <input type="date" id="f-hasta" name="hasta" value="{{ $filters['hasta'] ?? '' }}">
        </div>
        <button type="submit" class="boton secundario">Filtrar</button>
        <a href="{{ route('records.audit') }}" class="limpiar">Limpiar</a>
    </form>

    <div class="tabla-caja">
        <table>
            <caption>{{ $logs->total() }} {{ $logs->total() === 1 ? 'registro' : 'registros' }}</caption>
            <thead>
                <tr>
                    <th scope="col">Fecha</th>
                    <th scope="col">Hecho</th>
                    <th scope="col">Quién</th>
                    <th scope="col">Descripción</th>
                    <th scope="col">IP</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr @class(['fila-abierta' => $open?->is($log)])>
                        <td class="num" data-etiqueta="Fecha">{{ $log->created_at->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}</td>
                        <td data-etiqueta="Hecho"><span class="estado curso">{{ $events[$log->event] ?? $log->event }}</span></td>
                        <td class="solicitante" data-etiqueta="Quién">
                            @if ($log->actor)
                                <strong>{{ $log->actor->name }}</strong><small>{{ $log->actor->email }}</small>
                            @elseif ($log->subject)
                                <strong>{{ $log->subject->name }}</strong><small>{{ $log->subject->email }}</small>
                            @else
                                <strong>{{ $log->properties['email'] ?? 'Sistema' }}</strong>
                            @endif
                        </td>
                        <td class="asunto-celda" data-etiqueta="Descripción">
                            <a class="asunto" href="{{ route('records.audit', array_merge(request()->query(), ['ver' => $log->id])) }}#detalle">{{ $log->description }}</a>
                        </td>
                        <td class="num" data-etiqueta="IP">{{ $log->ip ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No hay registros que mostrar.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('records._pages', ['paginator' => $logs])

    @if ($open)
        <section class="tarjeta detalle-registro" id="detalle" aria-label="Detalle del registro">
            <div class="titulo-fila">
                <h2>{{ $events[$open->event] ?? $open->event }}</h2>
                <a href="{{ route('records.audit', request()->except('ver')) }}">Cerrar detalle</a>
            </div>
            <dl class="detalles">
                <dt>Descripción</dt><dd>{{ $open->description }}</dd>
                <dt>Cuándo</dt><dd>{{ $open->created_at->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i:s') }}</dd>
                <dt>Hecho por</dt><dd>{{ $open->actor ? $open->actor->name.' ('.$open->actor->email.')' : 'Nadie con sesión iniciada' }}</dd>
                @if ($open->subject)<dt>Persona afectada</dt><dd>{{ $open->subject->name }} ({{ $open->subject->email }})</dd>@endif
                <dt>IP</dt><dd>{{ $open->ip ?? '—' }}</dd>
            </dl>
            @if ($open->properties)
                <h3>Datos</h3>
                <pre class="json">{{ json_encode($open->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @endif
        </section>
    @endif
@endsection
