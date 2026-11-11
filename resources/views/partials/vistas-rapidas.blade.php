{{--
    «Vistas rápidas» de las listas de tickets: enlaces que solo ponen filtros (mockups bandeja-b y mis-tickets-c).
    Cuándo tocarla: al cambiar el aspecto; QUÉ vistas hay lo decide cada controlador (TicketFilters::quickViews).
    Variables: $quickViews (lista de label, url, current).
--}}
<div class="vistas" aria-label="Vistas rápidas">
    <span>Vistas rápidas:</span>
    @foreach ($quickViews as $view)
        <a href="{{ $view['url'] }}" @if ($view['current']) aria-current="true" @endif>{{ $view['label'] }}</a>
    @endforeach
</div>
