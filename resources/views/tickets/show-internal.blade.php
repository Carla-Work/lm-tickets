{{--
    Detalle de un ticket para el EQUIPO (agentes y administrador). Mockups validados:
    ticket-detalle-interno-a-hilo.html (b: ajeno, c: resuelto).
    Cuándo tocarlo: al cambiar el detalle interno. Pendiente (4d): adjuntos.
    Trampas:
     - Qué controles se ofrecen lo decide la POLICY (@can reply / addNote / updateStatus / resolve / close /
       assign / changeDepartment), no un `if` sobre el estado: si cambia la regla, cambia aquí sola.
     - R2/R13: $thread ya viene filtrado por visibleTo en el controlador; esta plantilla solo decide cómo se
       pinta. La nota interna lleva candado y rayado para que NADIE la confunda con un mensaje al cliente.
     - «Mover a otro departamento» es UN solo selector de categoría agrupado por departamento (mockup E,
       ticket-detalle-interno-e-departamento-un-selector.html): el departamento se deduce de la categoría en
       el servidor, así que no puede llegar una combinación incoherente (R6).
    Variables: $ticket (con product, requester.company, assignee), $thread, $agents, $categories.
--}}
@extends('layouts.panel')
@section('titulo', "Ticket #{$ticket->number}")

@php
    $tz = config('app.timezone');
    $when = fn ($date) => $date?->timezone($tz)->translatedFormat('j M, H:i');
@endphp

@section('contenido')
    <p class="migas"><a href="{{ route('inbox') }}">Bandeja</a> › #{{ $ticket->number }}</p>

    <section class="cabecera-ticket {{ $ticket->department->cssClass() }}" aria-label="Resumen del ticket">
        <span class="num">Ticket #{{ $ticket->number }}</span>
        <h1>{{ $ticket->subject }}</h1>
        <div class="chips">
            <span class="dep {{ $ticket->department->cssClass() }}">{{ $ticket->department->label() }}</span>
            <span class="estado {{ $ticket->status->cssClass() }}">{{ $ticket->status->label() }}</span>
            {{-- Prioridad y etiquetas: INTERNAS, solo esta vista (la del cliente es tickets/show). Normal no se pinta. --}}
            @if ($ticket->priority !== \App\Enums\Priority::Normal)<span class="prio {{ $ticket->priority->cssClass() }}" title="Prioridad (solo la ve el equipo)">{{ $ticket->priority->badge() }}</span>@endif
            @if ($ticket->rating)<span class="est-mini {{ $ticket->rating->score <= 2 ? 'mala' : '' }}" title="Valoración del cliente">{{ \App\Models\TicketRating::stars($ticket->rating->score) }} {{ $ticket->rating->score }}/5</span>@endif
            @if ($ticket->tags->isNotEmpty())<span>@foreach ($ticket->tags as $tag)<span class="etq">{{ $tag->name }}</span>@endforeach</span>@endif
        </div>
        <div class="meta">
            <span>Abierto por <strong>{{ $ticket->requester->name }}</strong> · {{ $ticket->requester->company?->name }}</span>
            @if ($ticket->product)<span>Producto: <strong>{{ $ticket->product->name }}</strong></span>@endif
            @if ($related)<span>Relacionado con <a href="{{ route('tickets.show', $related) }}"><strong>#{{ $related->number }}</strong></a></span>@endif
            <span>Asignado a: @if ($ticket->assignee)<strong>{{ $ticket->assignee->name }}</strong> ({{ $ticket->assignee->team?->label() ?? $ticket->assignee->role->label() }})@else<strong>Sin asignar</strong>@endif</span>
        </div>
    </section>

    <div class="dos">
        <div>
        <section aria-label="Conversación" class="tarjeta" style="margin-bottom:1.25rem">
            <h2>Conversación e historial</h2>
            <ol class="hilo">
                @foreach ($thread as $item)
                    @if ($item instanceof \App\Models\TicketMessage)
                        @php
                            $isNote = $item->kind === \App\Enums\MessageKind::Internal;
                            $isStaff = $item->author?->isInternal();
                        @endphp
                        <li>
                            <article class="mensaje {{ $isNote ? 'nota' : ($isStaff ? 'equipo' : '') }}">
                                <header>
                                    <span><strong>{{ $item->author?->name }}</strong>
                                        @if ($isNote)<span class="rol">Nota interna</span>
                                        @elseif ($isStaff)<span class="rol">Ling Marco</span>
                                        @else · cliente @endif
                                    </span>
                                    <time datetime="{{ $item->created_at?->toIso8601String() }}">{{ $when($item->created_at) }}@if ($item->via_email) · por correo @endif</time>
                                </header>
                                @if ($isNote)<span class="solo-equipo">🔒 Solo equipo de Ling Marco</span>@endif
                                {{-- nl2br(e()): conserva los saltos de línea y escapa el HTML (el texto del usuario nunca se pinta crudo). --}}
                                <p>{!! nl2br(e($item->body)) !!}</p>
                                @include('tickets._adjuntos', ['attachments' => $item->attachments])
                                {{-- INTERNO (mockup ticket-detalle-interno-h): se ve que la respuesta salió por correo. El cliente no ve estas marcas. --}}
                                @if ($item->emailed_at)<p class="ayuda" style="margin:.4rem 0 0">✉ Enviado también por correo a {{ $ticket->requester->email }}</p>@endif
                            </article>
                        </li>
                    @else
                        <li class="evento {{ $item->visible_to_customer ? 'visible-cliente' : 'interno' }}"><span>@include('tickets._evento-interno', ['event' => $item]) · {{ $when($item->created_at) }}</span></li>
                    @endif
                @endforeach
            </ol>
            <p class="ayuda-leyenda">🔒 = solo lo ve el equipo de Ling Marco &nbsp;·&nbsp; 👁 = también lo ve el cliente</p>
        </section>

        @php
            $canReply = $user->can('reply', $ticket);
            $canNote = $user->can('addNote', $ticket);
            $canAssign = $user->can('assign', $ticket);
        @endphp
        @if ($canReply || $canNote)
            <section class="pestanas" aria-label="Escribir">
                @if ($canReply && $canNote)
                    <input type="radio" name="tipo" id="t-resp" checked>
                    <label class="pest" for="t-resp">Responder al cliente</label>
                    <input type="radio" name="tipo" id="t-nota">
                    <label class="pest" for="t-nota">Nota interna</label>
                @endif
                @if ($canReply)
                    <div class="panel resp responder">
                        <form action="{{ route('tickets.messages.store', $ticket) }}" method="post" enctype="multipart/form-data">
                            @csrf
                            <label for="respuesta">Mensaje para {{ $ticket->requester->name }}</label>
                            <textarea id="respuesta" name="mensaje" required maxlength="10000">{{ old('mensaje') }}</textarea>
                            @error('mensaje')<p class="error-campo" id="err-mensaje">{{ $message }}</p>@enderror
                            <input type="file" id="adjuntos" name="adjuntos[]" multiple aria-label="Adjuntar archivos">
                            <p class="ayuda" style="margin-top:.3rem">Capturas, PDF, hojas de cálculo o ZIP. Hasta {{ config('access.attachments.max_files') }} archivos de {{ intdiv(config('access.attachments.max_kb'), 1024) }} MB cada uno.</p>
                            @error('adjuntos')<p class="error-campo" id="err-adjuntos" role="alert">{{ $message }}</p>@enderror
                            @foreach ($errors->get('adjuntos.*') as $messages)@foreach ($messages as $message)<p class="error-campo" role="alert">{{ $message }}</p>@endforeach @endforeach
                            {{-- Mockup ticket-detalle-interno-h: viene MARCADA (el cliente no entra al panel). Sin marcar, el cliente solo lo ve en su panel y no recibe correo. --}}
                            <div class="casilla-aviso" style="margin-top:.8rem">
                                <input type="checkbox" id="enviar_correo" name="enviar_correo" value="1" @checked(! session()->hasOldInput() || old('enviar_correo'))>
                                <div>
                                    <label for="enviar_correo">Enviar esta respuesta por correo a {{ $ticket->requester->name }}</label>
                                    <p class="ayuda" style="margin:0">El texto y los adjuntos salen por correo a {{ $ticket->requester->email }} (hasta {{ config('inbound.max_outgoing_mb') }} MB de adjuntos). Si lo desmarcas, se guarda en el ticket y el cliente solo lo ve en su panel.</p>
                                </div>
                            </div>
                            @error('enviar_correo')<p class="error-campo" role="alert">{{ $message }}</p>@enderror
                            <p class="ayuda" style="margin:.5rem 0 0;font-size:.9rem;color:var(--texto-suave)">El estado no cambia solo: cámbialo en «Gestión» si hace falta (por ejemplo, a «Pendiente de cliente»).</p>
                            <div class="acciones"><button type="submit" class="boton principal">Enviar al cliente</button></div>
                        </form>
                    </div>
                @elseif ($canNote && $ticket->status !== \App\Enums\TicketStatus::Closed)
                    {{-- Ticket ajeno (mockup -b): no puede responder; se le explica y se le ofrece asignárselo. --}}
                    <div class="panel resp responder">
                        @if ($ticket->assignee)
                            <p class="aviso-nota" style="color:var(--titulo)">Este ticket lo lleva {{ $ticket->assignee->name }} ({{ $ticket->assignee->team?->label() }}).</p>
                        @else
                            <p class="aviso-nota" style="color:var(--titulo)">Este ticket no tiene a nadie asignado.</p>
                        @endif
                        @if ($canAssign && $user->hasRole(\App\Enums\UserRole::Agent))
                            <p class="ayuda" style="margin:0 0 .9rem;color:var(--texto-suave)">Para responder al cliente, asígnatelo primero. Quedará registrado. Mientras tanto puedes dejar una nota interna.</p>
                            <form action="{{ route('tickets.assignment.update', $ticket) }}" method="post">
                                @csrf
                                <input type="hidden" name="asignado" value="{{ $user->id }}">
                                <button type="submit" class="boton principal">Asignármelo</button>
                            </form>
                        @endif
                    </div>
                @endif
                @if ($canNote)
                    <div class="panel nota responder">
                        <p class="aviso-nota">🔒 Solo la verá el equipo de Ling Marco. El cliente nunca la ve.</p>
                        <form action="{{ route('tickets.notes.store', $ticket) }}" method="post">
                            @csrf
                            <label for="nota" style="display:block;font-weight:600;margin-bottom:.3rem">Nota</label>
                            <textarea id="nota" name="mensaje" required maxlength="10000">{{ old('mensaje') }}</textarea>
                            <div class="acciones"><button type="submit" class="boton secundario">Guardar nota</button></div>
                        </form>
                    </div>
                @endif
            </section>
        @endif
        </div>

        <div class="lateral-col">
            @if ($ticket->rating)
                {{-- Valoración del cliente (P1, mockup interno-g): la ve todo el equipo que ve el ticket; nadie del equipo puede ponerla. --}}
                @include('tickets._valoracion', ['rating' => $ticket->rating, 'mine' => false])
            @endif
            @php
                $canStatus = $user->can('updateStatus', $ticket) || $user->can('resolve', $ticket) || $user->can('close', $ticket);
                $canDepartment = $user->can('changeDepartment', $ticket);
                $canPriority = $user->can('changePriority', $ticket);
                $canTags = $user->can('changeTags', $ticket);
                $targets = $ticket->status->allowedTargets();
            @endphp
            @if ($canStatus || $canAssign || $canDepartment || $canPriority || $canTags)
                <aside class="tarjeta gestion" aria-label="Gestión del ticket">
                    <h2>Gestión</h2>
                    @if ($canStatus && $targets !== [])
                        <form class="grupo" action="{{ route('tickets.status.update', $ticket) }}" method="post">
                            @csrf
                            <label for="g-estado">Estado</label>
                            <select id="g-estado" name="estado" @errorAttrs('estado')>
                                @foreach ($targets as $target)
                                    <option value="{{ $target->value }}" @selected(old('estado') === $target->value)>{{ $target->label() }}</option>
                                @endforeach
                            </select>
                            @if (in_array(\App\Enums\TicketStatus::Resolved, $targets, true))
                                <label for="g-solucion" style="margin-top:.4rem">Cómo se ha resuelto <span style="font-weight:400">(obligatorio al pasar a «Resuelto»)</span></label>
                                <textarea id="g-solucion" name="solucion" @errorAttrs('solucion') maxlength="5000" placeholder="Qué se ha hecho para solucionarlo">{{ old('solucion') }}</textarea>
                            @endif
                            @error('estado')<p class="error-campo" id="err-estado">{{ $message }}</p>@enderror
                            @error('solucion')<p class="error-campo" id="err-solucion">{{ $message }}</p>@enderror
                            <button type="submit" class="boton secundario">Cambiar estado</button>
                        </form>
                    @elseif (! $canStatus && $ticket->status !== \App\Enums\TicketStatus::Closed)
                        <div class="grupo"><p class="nota-ayuda">Para cambiar el estado, asígnate primero el ticket.</p></div>
                    @endif

                    @if ($canPriority)
                        <form class="grupo" action="{{ route('tickets.priority.update', $ticket) }}" method="post">
                            @csrf
                            <label for="g-prio">Prioridad <span style="font-weight:400">(solo la ve el equipo)</span></label>
                            <select id="g-prio" name="prioridad" @errorAttrs('prioridad')>
                                @foreach (\App\Enums\Priority::cases() as $priority)
                                    <option value="{{ $priority->value }}" @selected($ticket->priority === $priority)>{{ $priority->label() }}</option>
                                @endforeach
                            </select>
                            @error('prioridad')<p class="error-campo" id="err-prioridad">{{ $message }}</p>@enderror
                            <button type="submit" class="boton secundario">Cambiar prioridad</button>
                        </form>
                    @endif

                    @if ($canTags)
                        <form class="grupo" action="{{ route('tickets.tags.update', $ticket) }}" method="post">
                            @csrf
                            <label>Etiquetas <span style="font-weight:400">(hasta {{ \App\Models\Tag::MAX_PER_TICKET }}; solo las ve el equipo)</span></label>
                            <div class="etqs-lista">
                                @forelse ($tagChoices as $tag)
                                    <label><input type="checkbox" name="etiquetas[]" value="{{ $tag->id }}" @checked($ticket->tags->contains('id', $tag->id))> {{ $tag->name }}@unless ($tag->active) <small>(desactivada)</small>@endunless</label>
                                @empty
                                    <p class="nota-ayuda">Todavía no hay etiquetas. Las crea el administrador.</p>
                                @endforelse
                            </div>
                            @error('etiquetas')<p class="error-campo" id="err-etiquetas">{{ $message }}</p>@enderror
                            @if ($tagChoices->isNotEmpty())
                                <button type="submit" class="boton secundario">Guardar etiquetas</button>
                                <p class="nota-ayuda">Las etiquetas las define el administrador en «Etiquetas».</p>
                            @endif
                        </form>
                    @endif

                    @if ($canAssign)
                        <form class="grupo" action="{{ route('tickets.assignment.update', $ticket) }}" method="post">
                            @csrf
                            <label for="g-asig">Asignado a</label>
                            <select id="g-asig" name="asignado">
                                <option value="sin" @selected($ticket->assigned_to === null)>Sin asignar</option>
                                @foreach ($agents->groupBy(fn ($p) => $p->team?->label()) as $team => $people)
                                    <optgroup label="{{ $team }}">
                                        @foreach ($people as $person)
                                            <option value="{{ $person->id }}" @selected($ticket->assigned_to === $person->id)>{{ $person->name }}@if ($person->is($user)) (tú)@endif</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('asignado')<p class="error-campo" id="err-asignado">{{ $message }}</p>@enderror
                            <button type="submit" class="boton secundario">Reasignar</button>
                            <p class="nota-ayuda">Puedes asignarlo a cualquiera, también del otro equipo. Queda registrado.</p>
                        </form>
                    @endif

                    @if ($canDepartment)
                        <form class="grupo" action="{{ route('tickets.department.update', $ticket) }}" method="post">
                            @csrf
                            <label for="g-mover">Mover a otro departamento</label>
                            <select id="g-mover" name="categoria" @errorAttrs('categoria') required>
                                @foreach (\App\Enums\Department::cases() as $department)
                                    @continue($department === $ticket->department)
                                    <optgroup label="{{ $department === \App\Enums\Department::Other ? 'Contabilidad (solo la ve su equipo y el administrador)' : $department->label() }}">
                                        @foreach ($categories[$department->value] ?? [] as $category)
                                            <option value="{{ $category->id }}" @selected((string) old('categoria') === (string) $category->id)>{{ $category->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('categoria')<p class="error-campo" id="err-categoria">{{ $message }}</p>@enderror
                            <button type="submit" class="boton secundario">Mover ticket</button>
                            <p class="nota-ayuda fuerte">Se avisará al cliente por correo y en su panel.</p>
                            <p class="nota-ayuda">Elige el tipo de incidencia: el departamento es el del grupo donde está. No aparece el departamento actual.</p>
                            @if ($user->hasRole(\App\Enums\UserRole::Admin))
                                <p class="nota-ayuda">Si lo llevaba alguien que no puede atender el departamento nuevo, quedará sin asignar.</p>
                            @else
                                <p class="nota-ayuda">Si el departamento nuevo lo atiende otro equipo, dejarás de ver el ticket; y si lo llevaba alguien que no puede atenderlo, quedará sin asignar.</p>
                            @endif
                        </form>
                    @endif
                </aside>
            @endif

        <aside class="tarjeta" aria-label="Datos del solicitante">
            <h2>Solicitante</h2>
            <dl class="detalles">
                <dt>Persona</dt>
                <dd>{{ $ticket->requester->name }}<small>{{ $ticket->requester->company?->name }}</small></dd>
                <dt>Correo</dt>
                <dd>{{ $ticket->requester->email }}</dd>
                @if ($ticket->requester->phone)
                    <dt>Teléfono</dt>
                    <dd>{{ $ticket->requester->phone }}</dd>
                @endif
                <dt>Fecha de apertura</dt>
                <dd>{{ $ticket->created_at?->timezone($tz)->translatedFormat('j M Y, H:i') }}</dd>
                {{-- INTERNO: el cliente no ve el canal ni quién lo creó (esta vista es solo del equipo). --}}
                <dt>Canal</dt>
                <dd>{{ $ticket->channel->label() }}@if ($ticket->created_by_id !== $ticket->requester_id)<small>Creado por {{ $ticket->createdBy?->name }}</small>@endif</dd>
                <dt>Última actividad</dt>
                <dd>{{ $when($ticket->last_activity_at) }}</dd>
            </dl>
        </aside>
        </div>
    </div>
@endsection
