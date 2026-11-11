{{--
    Frase del historial para el EQUIPO (agentes y administrador). Variable: $event (TicketEvent con `actor`).
    Cuándo tocarlo: al añadir un tipo de evento (TicketEventType).
    Distinto de _evento.blade.php (el del cliente): aquí se dice QUIÉN lo hizo, se usan los nombres INTERNOS de
    los estados («Pendiente de cliente») y se ven también los eventos que el cliente no ve.
    Los nombres de personas de `data` son una copia tomada al registrar el evento (el historial no cambia si
    luego renombran a alguien) y se escapan con {{ }} como cualquier texto.
--}}
@php
    $data = $event->data ?? [];
    $who = $event->actor?->name;
    $dep = fn (?string $v) => $v === null ? '' : (\App\Enums\Department::tryFrom($v)?->label() ?? '');
    $st = fn (?string $v) => $v === null ? '' : (\App\Enums\TicketStatus::tryFrom($v)?->label() ?? '');
@endphp
@if ($who)<strong>{{ $who }}</strong> @endif
@switch($event->type)
    @case(\App\Enums\TicketEventType::Created)
        creó el ticket en <strong>{{ $dep($data['department'] ?? null) }}</strong>
        @break
    @case(\App\Enums\TicketEventType::DepartmentChanged)
        cambió el departamento de <strong>{{ $dep($data['from'] ?? null) }}</strong> a <strong>{{ $dep($data['to'] ?? null) }}</strong> (aviso al cliente)
        @break
    @case(\App\Enums\TicketEventType::StatusChanged)
        cambió el estado de <strong>{{ $st($data['from'] ?? null) }}</strong> a <strong>{{ $st($data['to'] ?? null) }}</strong>@if (! empty($data['automatic'])) (automático: respondió el cliente)@endif
        @break
    @case(\App\Enums\TicketEventType::Assigned)
        @if (($data['reason'] ?? null) === 'moved_to_other')
            retiró la asignación de <strong>{{ $data['from_name'] ?? '' }}</strong>: el ticket pasó a «Otros»
        @elseif (($data['reason'] ?? null) === 'department_changed')
            retiró la asignación de <strong>{{ $data['from_name'] ?? '' }}</strong>: el departamento nuevo lo atiende otro equipo
        @elseif (($data['reason'] ?? null) === 'user_deactivated')
            retiró la asignación de <strong>{{ $data['from_name'] ?? '' }}</strong>: su cuenta se dio de baja
        @elseif (($data['reason'] ?? null) === 'team_changed')
            retiró la asignación de <strong>{{ $data['from_name'] ?? '' }}</strong>: cambió de equipo y ya no atiende este departamento
        @elseif (($data['reason'] ?? null) === 'role_transfer')
            retiró la asignación de <strong>{{ $data['from_name'] ?? '' }}</strong>: pasó a ser el administrador (no lleva tickets)
        @elseif (empty($data['to_name']))
            quitó la asignación (antes: <strong>{{ $data['from_name'] ?? '—' }}</strong>)
        @elseif (empty($data['from_name']))
            asignó el ticket a <strong>{{ $data['to_name'] }}</strong>
        @else
            reasignó el ticket de <strong>{{ $data['from_name'] }}</strong> a <strong>{{ $data['to_name'] }}</strong>
        @endif
        @break
    @case(\App\Enums\TicketEventType::PriorityChanged)
        cambió la prioridad de <strong>{{ \App\Enums\Priority::tryFrom($data['from'] ?? '')?->label() }}</strong> a <strong>{{ \App\Enums\Priority::tryFrom($data['to'] ?? '')?->label() }}</strong>
        @break
    @case(\App\Enums\TicketEventType::TagsChanged)
        @php
            $added = collect($data['added'] ?? [])->pluck('name')->values();
            $removed = collect($data['removed'] ?? [])->pluck('name')->values();
        @endphp
        {{-- Cada nombre se escapa por separado: son texto libre del administrador. --}}
        @if ($added->isNotEmpty())añadió {{ $added->count() > 1 ? 'las etiquetas' : 'la etiqueta' }} @foreach ($added as $name)<strong>{{ $name }}</strong>{{ $loop->last ? '' : ($loop->remaining === 1 ? ' y ' : ', ') }}@endforeach @endif
        @if ($added->isNotEmpty() && $removed->isNotEmpty())y @endif
        @if ($removed->isNotEmpty())quitó {{ $removed->count() > 1 ? 'las etiquetas' : 'la etiqueta' }} @foreach ($removed as $name)<strong>{{ $name }}</strong>{{ $loop->last ? '' : ($loop->remaining === 1 ? ' y ' : ', ') }}@endforeach @endif
        @break
    @case(\App\Enums\TicketEventType::Rated)
        valoró la atención: <strong>{{ (int) ($data['score'] ?? 0) }} de 5</strong>
        @break
    @case(\App\Enums\TicketEventType::Resolved)
        marcó el ticket como <strong>resuelto</strong>
        @if (! empty($data['resolution']))<br>Solución: <em>«{{ $data['resolution'] }}»</em>@endif
        @break
    @case(\App\Enums\TicketEventType::Closed)
        <strong>cerró</strong> el ticket
        @break
    @default
        cambió el ticket
@endswitch
