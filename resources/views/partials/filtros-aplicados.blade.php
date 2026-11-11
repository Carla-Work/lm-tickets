{{--
    Barra «Filtros aplicados» de las listas de tickets, con × para quitar uno a uno (mockups bandeja-b y mis-tickets-c).
    Cuándo tocarla: al cambiar el aspecto; los textos y enlaces salen de TicketFilters::active (no los rehagas aquí:
    sus nombres vienen de listas ya limitadas a lo que esa persona puede ver).
    Variables: $active (lista de label y remove), $clearUrl, $errors (errores de validación de los filtros).
--}}
@if ($errors->any())
    <div class="aviso" role="alert"><strong>Revisa los filtros:</strong> {{ $errors->first() }}</div>
@endif
@if (count($active) > 0)
    <p class="activos" aria-label="Filtros aplicados">
        <strong>Filtros aplicados:</strong>
        @foreach ($active as $filter)
            <span class="etiqueta-filtro">{{ $filter['label'] }} <a href="{{ $filter['remove'] }}" aria-label="Quitar el filtro {{ $filter['label'] }}">×</a></span>
        @endforeach
        <a class="limpiar-todo" href="{{ $clearUrl }}">Limpiar todo</a>
    </p>
@endif
