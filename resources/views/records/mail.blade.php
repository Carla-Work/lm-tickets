{{--
    «Registro de correos» (Registros, solo el administrador). Cuándo tocarla: al cambiar la pantalla.
    Diseño de referencia: la pantalla del mismo nombre de asesoria (resumen, filtros, tabla y detalle), con la
    paleta de tickets. Sin mockup a petición de la usuaria (2026-11-10).
    Trampa: el cuerpo del correo va SOLO en un iframe con sandbox vacío y su propia CSP (ver MailLogController);
    no pintarlo con {!! !!} en la página. Un correo de invitación o recuperación no tiene cuerpo guardado.
    Variables: $logs (paginado), $filters, $kinds, $failed, $unconfirmed, $open, $preview, $queued.
--}}
@extends('layouts.panel')
@section('titulo', 'Registro de correos')

@php
    $statusLabels = ['pending' => 'Sin confirmar', 'sent' => 'Enviado', 'failed' => 'Fallido'];
    $statusClass = ['pending' => 'pendiente', 'sent' => 'curso', 'failed' => 'abierto'];
@endphp

@section('contenido')
    <p class="migas">Registros › Correos</p>
    <div class="titulo-fila">
        <h1>Registro de correos</h1>
    </div>

    @if ($queued > 0)
        <div class="alerta ok" role="status">
            Hay {{ $queued }} {{ $queued === 1 ? 'trabajo esperando' : 'trabajos esperando' }} en la cola. Si no se envía ninguno,
            comprueba que el proceso de la cola está en marcha (<code>composer dev</code> lo levanta).
        </div>
    @endif

    <section class="resumen resumen-3" aria-label="Resumen">
        <a href="{{ route('records.mail', ['estado' => 'failed']) }}" class="{{ $failed > 0 ? 'atencion' : '' }}"><strong>{{ $failed }}</strong>Fallidos</a>
        <a href="{{ route('records.mail', ['estado' => 'pending']) }}" class="{{ $unconfirmed > 0 ? 'atencion' : '' }}"><strong>{{ $unconfirmed }}</strong>Sin confirmar</a>
        <a href="{{ route('records.mail', ['estado' => 'sent']) }}"><strong>{{ $logs->total() }}</strong>En este filtro</a>
    </section>

    <form class="filtros" action="{{ route('records.mail') }}" method="get" aria-label="Filtrar correos">
        <div class="buscar">
            <label for="q">Buscar</label>
            <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Asunto o dirección">
        </div>
        <div>
            <label for="f-estado">Estado</label>
            <select id="f-estado" name="estado">
                <option value="">Todos</option>
                @foreach ($statusLabels as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['estado'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-tipo">Tipo</label>
            <select id="f-tipo" name="tipo">
                <option value="">Todos</option>
                @foreach ($kinds as $class => $label)
                    <option value="{{ $class }}" @selected(($filters['tipo'] ?? '') === $class)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="casilla">
            <label for="f-24"><input type="checkbox" id="f-24" name="ultimas24" value="1" @checked($filters['ultimas24'] ?? false)> Últimas 24 horas</label>
        </div>
        <button type="submit" class="boton secundario">Filtrar</button>
        <a href="{{ route('records.mail') }}" class="limpiar">Limpiar</a>
    </form>

    <div class="tabla-caja">
        <table>
            <caption>{{ $logs->total() }} {{ $logs->total() === 1 ? 'correo' : 'correos' }}</caption>
            <thead>
                <tr>
                    <th scope="col">Fecha</th>
                    <th scope="col">Asunto</th>
                    <th scope="col">Para</th>
                    <th scope="col">Tipo</th>
                    <th scope="col">Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr @class(['fila-abierta' => $open?->is($log)])>
                        <td class="num" data-etiqueta="Fecha">{{ $log->created_at->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}</td>
                        <td class="asunto-celda" data-etiqueta="Asunto">
                            <a class="asunto" href="{{ route('records.mail', array_merge(request()->query(), ['ver' => $log->id])) }}#detalle">{{ $log->subject ?? '(sin asunto)' }}</a>
                        </td>
                        <td data-etiqueta="Para">{{ $log->to_emails ?? '—' }}</td>
                        <td data-etiqueta="Tipo">{{ $log->kindLabel() }}</td>
                        <td data-etiqueta="Estado"><span class="estado {{ $statusClass[$log->status] ?? 'cerrado' }}">{{ $statusLabels[$log->status] ?? $log->status }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5">No hay correos que mostrar.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('records._pages', ['paginator' => $logs])

    @if ($open)
        <section class="tarjeta detalle-registro" id="detalle" aria-label="Detalle del correo">
            <div class="titulo-fila">
                <h2>{{ $open->subject ?? '(sin asunto)' }}</h2>
                <a href="{{ route('records.mail', request()->except('ver')) }}">Cerrar detalle</a>
            </div>
            <dl class="detalles">
                <dt>Estado</dt><dd><span class="estado {{ $statusClass[$open->status] ?? 'cerrado' }}">{{ $statusLabels[$open->status] ?? $open->status }}</span></dd>
                <dt>De</dt><dd>{{ $open->from_email ?? '—' }}</dd>
                <dt>Para</dt><dd>{{ $open->to_emails ?? '—' }}</dd>
                <dt>Tipo</dt><dd>{{ $open->kindLabel() }}</dd>
                <dt>Abierto el</dt><dd>{{ $open->created_at->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i:s') }}</dd>
                <dt>Confirmado el</dt><dd>{{ $open->sent_at?->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i:s') ?? '—' }}</dd>
                @if ($open->error)
                    <dt>Motivo del fallo</dt><dd class="error-texto">{{ $open->error }}</dd>
                @endif
            </dl>

            <h3>Contenido</h3>
            @if ($preview !== null)
                {{-- sandbox SIN permisos: no ejecuta nada. La CSP del documento corta las peticiones a fuera. --}}
                <iframe class="vista-correo" title="Contenido del correo" sandbox="" srcdoc="{{ $preview }}"></iframe>
            @else
                <p class="ayuda">El contenido de este correo no se guarda: lleva un enlace de un solo uso que da acceso a una cuenta.</p>
            @endif
        </section>
    @endif
@endsection
