{{--
    Detalle de un ticket para el CLIENTE. Mockups validados: ticket-detalle-cliente-a-hilo.html (y -b, resuelto).
    Cuándo tocarlo: al cambiar el detalle. Pendiente: adjuntos y «abrir un ticket nuevo relacionado» (4d).
    Trampa (R2): $thread ya viene filtrado por TicketMessage/TicketEvent::visibleTo en el controlador; esta
    plantilla NO decide qué se enseña, solo cómo se pinta. No añadir aquí consultas a mensajes.
    Variables: $ticket (con product, requester, assignee), $thread (mensajes y eventos ordenados).
--}}
@extends('layouts.panel')
@section('titulo', "Ticket #{$ticket->number}")

@php
    $tz = config('app.timezone');
    $when = fn ($date) => $date?->timezone($tz)->translatedFormat('j M, H:i');
@endphp

@section('contenido')
    <p class="migas"><a href="{{ route('tickets.index') }}">Mis tickets</a> › #{{ $ticket->number }}</p>

    <section class="cabecera-ticket {{ $ticket->department->cssClass() }}" aria-label="Resumen del ticket">
        <span class="num">Ticket #{{ $ticket->number }}</span>
        <h1>{{ $ticket->subject }}</h1>
        <div class="chips">
            <span class="dep {{ $ticket->department->cssClass() }}">{{ $ticket->department->label() }}</span>
            <span class="estado {{ $ticket->status->cssClass() }}">{{ $ticket->status->customerLabel() }}</span>
        </div>
    </section>

    <div class="dos">
        <div>
        <section aria-label="Conversación" class="tarjeta" style="margin-bottom:1.25rem">
            <h2>Conversación</h2>
            <ol class="hilo">
                @foreach ($thread as $item)
                    @if ($item instanceof \App\Models\TicketMessage)
                        @php $mine = $item->author_id === $user->id; $isStaff = $item->author?->isInternal(); @endphp
                        <li>
                            <article class="mensaje {{ $isStaff ? 'equipo' : '' }} {{ $mine ? 'mio' : '' }}">
                                <header>
                                    <span><strong>{{ $item->author?->name }}</strong>@if ($mine) (tú)@endif @if ($isStaff)<span class="rol">Ling Marco</span>@endif</span>
                                    <time datetime="{{ $item->created_at?->toIso8601String() }}">{{ $when($item->created_at) }}</time>
                                </header>
                                {{-- nl2br(e()): conserva los saltos de línea y escapa el HTML (el texto del usuario nunca se pinta crudo). --}}
                                <p>{!! nl2br(e($item->body)) !!}</p>
                            </article>
                        </li>
                    @else
                        <li class="evento"><span>@include('tickets._evento', ['event' => $item]) · {{ $when($item->created_at) }}</span></li>
                    @endif
                @endforeach
            </ol>
        </section>

        {{-- Las dos cajas dependen de la policy, no de un `if` sobre el estado: si cambia la regla (R4), cambia aquí sin tocar la plantilla. --}}
        @can('reply', $ticket)
            <section class="tarjeta responder" aria-label="Responder">
                <h2>Tu respuesta</h2>
                <p class="ayuda">
                    @if ($ticket->status === \App\Enums\TicketStatus::PendingCustomer)
                        El equipo está esperando tu contestación. Te avisaremos por correo y en el panel cuando responda.
                    @else
                        Te avisaremos por correo y en el panel cuando responda el equipo.
                    @endif
                </p>
                <form action="{{ route('tickets.messages.store', $ticket) }}" method="post">
                    @csrf
                    <label for="mensaje">Mensaje</label>
                    <textarea id="mensaje" name="mensaje" required maxlength="10000">{{ old('mensaje') }}</textarea>
                    @error('mensaje')<p class="error-campo">{{ $message }}</p>@enderror
                    <div class="acciones">
                        <button type="submit" class="boton principal">Enviar respuesta</button>
                    </div>
                </form>
                @can('markResolved', $ticket)
                    <div class="cierre">
                        <p><strong>¿Ya está solucionado?</strong> Puedes marcarlo como resuelto. Después ya no podrás añadir mensajes ni reabrirlo: si el problema vuelve, tendrás que abrir un ticket nuevo.</p>
                        <form action="{{ route('tickets.resolve', $ticket) }}" method="post">
                            @csrf
                            <label for="solucion">¿Cómo se ha resuelto? <span aria-hidden="true">*</span></label>
                            <textarea id="solucion" name="solucion" required maxlength="5000" style="min-height:80px" placeholder="Por ejemplo: «Ya funciona después de actualizar el fichero»">{{ old('solucion') }}</textarea>
                            @error('solucion')<p class="error-campo">{{ $message }}</p>@enderror
                            @error('estado')<p class="error-campo">{{ $message }}</p>@enderror
                            <div class="acciones"><button type="submit" class="boton secundario">Marcar como resuelto</button></div>
                        </form>
                    </div>
                @endcan
            </section>
        @elseif ($ticket->status->isFinal())
            <section class="tarjeta responder" aria-label="Ticket {{ mb_strtolower($ticket->status->customerLabel()) }}">
                <h2>Este ticket está {{ mb_strtolower($ticket->status->customerLabel()) }}</h2>
                <p class="ayuda">Ya no se pueden añadir mensajes ni reabrirlo. Si el problema vuelve, abre un ticket nuevo desde «Nuevo ticket».</p>
            </section>
        @endcan
        </div>

        <aside class="tarjeta" aria-label="Detalles del ticket">
            <h2>Detalles</h2>
            <dl class="detalles">
                <dt>Abierto por</dt>
                <dd>{{ $ticket->requester->name }}<small>{{ $ticket->requester->company?->name }}</small></dd>
                <dt>Fecha de apertura</dt>
                <dd>{{ $ticket->created_at?->timezone($tz)->translatedFormat('j M Y, H:i') }}</dd>
                <dt>Departamento</dt>
                <dd><span class="dep {{ $ticket->department->cssClass() }}">{{ $ticket->department->label() }}</span></dd>
                @if ($ticket->product)
                    <dt>Producto</dt>
                    <dd>{{ $ticket->product->name }}</dd>
                @endif
                <dt>Atendido por</dt>
                <dd>{{ $ticket->assignee ? $ticket->assignee->name : 'Pendiente de asignar' }}@if ($ticket->assignee)<small>Ling Marco</small>@endif</dd>
                <dt>Última actividad</dt>
                <dd>{{ $when($ticket->last_activity_at) }}</dd>
            </dl>
            <p class="aviso">
                @if ($ticket->requester_id === $user->id)
                    Estás viendo este ticket porque lo abriste tú. Los administradores de tu empresa también pueden verlo.
                @else
                    Estás viendo este ticket como administrador de tu empresa.
                @endif
            </p>
        </aside>
    </div>
@endsection
