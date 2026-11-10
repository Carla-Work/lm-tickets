{{--
    Frase del historial para el cliente. Variable: $event (TicketEvent).
    Cuándo tocarlo: al añadir un tipo de evento VISIBLE al cliente (TicketEventType). Un tipo sin frase aquí
    se muestra como «Cambio en el ticket» para no esconder ni romper nada.
    Los textos de `data` son valores de enumeraciones guardadas por el código, no texto libre del usuario.
--}}
@php
    $data = $event->data ?? [];
    $dep = fn (?string $v) => $v === null ? '' : (\App\Enums\Department::tryFrom($v)?->label() ?? '');
    $st = fn (?string $v) => $v === null ? '' : (\App\Enums\TicketStatus::tryFrom($v)?->customerLabel() ?? '');
@endphp
@switch($event->type)
    @case(\App\Enums\TicketEventType::Created)
        Ticket creado en el departamento <strong>{{ $dep($data['department'] ?? null) }}</strong>
        @break
    @case(\App\Enums\TicketEventType::DepartmentChanged)
        El equipo ha cambiado el departamento de <strong>{{ $dep($data['from'] ?? null) }}</strong> a <strong>{{ $dep($data['to'] ?? null) }}</strong>
        @break
    @case(\App\Enums\TicketEventType::StatusChanged)
        Estado cambiado a <strong>{{ $st($data['to'] ?? null) }}</strong>
        @break
    @case(\App\Enums\TicketEventType::Resolved)
        Ticket marcado como <strong>resuelto</strong>
        @break
    @case(\App\Enums\TicketEventType::Closed)
        Ticket <strong>cerrado</strong>
        @break
    @default
        Cambio en el ticket
@endswitch
