{{--
    Paginación de las pantallas de «Registros». Cuándo tocarla: al cambiar el pie de las tablas.
    Variable: $paginator (ya con withQueryString para no perder los filtros). Mismo marcado que la bandeja.
--}}
@if ($paginator->hasPages())
    <div class="paginacion">
        <span>Mostrando {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}</span>
        <nav aria-label="Paginación">
            @if ($paginator->previousPageUrl())<a href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Anterior</a>@endif
            <span aria-current="page">{{ $paginator->currentPage() }}</span>
            @if ($paginator->nextPageUrl())<a href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente ›</a>@endif
        </nav>
    </div>
@endif
