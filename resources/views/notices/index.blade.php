{{--
    «Avisos» (la campana). Mockups validados: avisos-a-cliente.html y avisos-b-interno.html.
    Cuándo tocarla: al cambiar la lista de avisos.
    Trampa: $notices ya viene filtrado en el controlador (solo tickets que la persona puede ver). Los textos de cada
    aviso los redactó TicketNotifier y se escapan con {{ }}: contienen el asunto, que escribió un cliente.
    Variables: $notices (paginado), $unread.
--}}
@extends('layouts.panel')
@section('titulo', 'Avisos')

@section('contenido')
    <div class="titulo-fila">
        <div>
            <h1>Avisos</h1>
            <p class="sub">{{ $unread === 0 ? 'Nada sin leer' : ($unread === 1 ? '1 sin leer' : "{$unread} sin leer") }}</p>
        </div>
        @if ($unread > 0)
            <form action="{{ route('notices.read-all') }}" method="post">
                @csrf
                <button class="boton secundario" type="submit">Marcar todos como leídos</button>
            </form>
        @endif
    </div>

    <div class="tabla-caja">
        <ul class="avisos">
            @forelse ($notices as $notice)
                <li @class(['nuevo-aviso' => $notice->read_at === null])>
                    <span class="punto" aria-hidden="true"></span>
                    <div class="texto">
                        <a href="{{ route('notices.open', $notice->id) }}">{{ $notice->data['title'] ?? 'Aviso' }}</a>
                        <p>{{ $notice->data['detail'] ?? '' }}</p>
                    </div>
                    <time datetime="{{ $notice->created_at?->toIso8601String() }}">{{ $notice->created_at?->timezone(config('app.timezone'))->translatedFormat('j M, H:i') }}</time>
                </li>
            @empty
                <li><div class="texto"><p>No tienes avisos todavía.</p></div></li>
            @endforelse
        </ul>
    </div>

    @if ($notices->hasPages())
        <div class="paginacion">
            <span>Página {{ $notices->currentPage() }} de {{ $notices->lastPage() }}</span>
            <nav aria-label="Paginación">
                @if ($notices->previousPageUrl())<a href="{{ $notices->previousPageUrl() }}" rel="prev">‹ Anterior</a>@endif
                @if ($notices->nextPageUrl())<a href="{{ $notices->nextPageUrl() }}" rel="next">Siguiente ›</a>@endif
            </nav>
        </div>
    @endif
@endsection
