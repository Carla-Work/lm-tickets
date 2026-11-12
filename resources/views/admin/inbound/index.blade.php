{{--
    «Correo entrante» (Administración): cuarentena. Mockup validado: correo-cuarentena-a-lista.html.
    Cuándo tocarla: al cambiar la lista. Variables: $pending, $spam (InboundEmail), $ticketsThisWeek, $enabled.
    Trampas: todo lo del correo (asunto, remitente, motivos) lo escribe un remitente desconocido: se escapa con {{ }} SIEMPRE. Sin JavaScript (el «Posible spam»
    es un <details>). Los plazos salen de config/inbound.php.
--}}
@extends('layouts.panel')
@section('titulo', 'Correo entrante')

@section('contenido')
    @php
        $tz = config('app.timezone');
        $pendingDays = (int) config('inbound.pending_days');
        $spamDays = (int) config('inbound.spam_days');
    @endphp
    <p class="migas">Administración › Correo entrante</p>
    <div class="titulo-fila"><div><h1>Correo entrante</h1><p class="sub">Correos de aplicaciones@, soporte@ y comercial@ que no han creado un ticket solos.</p></div></div>

    @unless ($enabled)
        <div class="aviso interna" role="status">La lectura de los buzones está <strong>desactivada</strong> (INBOUND_ENABLED). Esta lista no se actualizará hasta que se active.</div>
    @endunless

    <section class="resumen" aria-label="Resumen">
        <a class="{{ $pending->isNotEmpty() ? 'atencion' : '' }}" href="#pendientes"><strong>{{ $pending->count() }}</strong>Pendientes de revisar</a>
        <a href="#spam"><strong>{{ $spam->count() }}</strong>Posible spam</a>
        <span class="tarjeta-mini"><strong>{{ $ticketsThisWeek }}</strong>Tickets creados por correo esta semana</span>
    </section>

    <div class="tabla-caja" id="pendientes">
        <table class="fichas">
            <caption>Pendientes de revisar <span class="rotulo">se guardan {{ $pendingDays }} días</span></caption>
            <thead><tr><th scope="col">Recibido</th><th scope="col">De</th><th scope="col">Buzón</th><th scope="col">Asunto</th><th scope="col">Por qué está aquí</th><th scope="col">Acciones</th></tr></thead>
            <tbody>
                @forelse ($pending as $email)
                    <tr>
                        <td data-etiqueta="Recibido">{{ $email->received_at->timezone($tz)->translatedFormat('j M, H:i') }}</td>
                        <td class="asunto-celda" data-etiqueta="De"><strong>{{ $email->from_name ?: $email->from_email }}</strong><span class="secundario">{{ $email->from_email }}</span></td>
                        <td data-etiqueta="Buzón">{{ $email->mailboxAddress() }}<span class="secundario">→ {{ $email->department()?->label() }}</span></td>
                        <td data-etiqueta="Asunto"><a href="{{ route('admin.inbound.show', $email) }}">{{ $email->subject !== '' ? $email->subject : '(sin asunto)' }}</a></td>
                        <td data-etiqueta="Por qué está aquí">@foreach ((array) $email->reasons as $reason)<span class="pista {{ str_contains($reason, 'suplantación') || str_contains($reason, 'SPF') ? 'alerta' : '' }}">{{ $reason }}</span>@endforeach</td>
                        <td data-etiqueta="Acciones">
                            <div class="acc">
                                <a class="boton principal pequeno" href="{{ route('admin.inbound.show', $email) }}">Revisar</a>
                                <form action="{{ route('admin.inbound.spam', $email) }}" method="post">@csrf<button type="submit" class="boton secundario pequeno">Marcar spam</button></form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">No hay nada pendiente.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <details class="plegable" id="spam">
        <summary>Posible spam ({{ $spam->count() }}) <span class="rotulo">se guardan {{ $spamDays }} días</span></summary>
        <div class="tabla-caja" style="margin-top:.5rem">
            <table class="fichas">
                <thead><tr><th scope="col">Recibido</th><th scope="col">De</th><th scope="col">Buzón</th><th scope="col">Asunto</th><th scope="col">Acciones</th></tr></thead>
                <tbody>
                    @forelse ($spam as $email)
                        <tr>
                            <td data-etiqueta="Recibido">{{ $email->received_at->timezone($tz)->translatedFormat('j M, H:i') }}</td>
                            <td class="asunto-celda" data-etiqueta="De"><strong>{{ $email->from_name ?: $email->from_email }}</strong><span class="secundario">{{ $email->from_email }}</span></td>
                            <td data-etiqueta="Buzón">{{ $email->mailboxAddress() }}</td>
                            <td data-etiqueta="Asunto"><a href="{{ route('admin.inbound.show', $email) }}">{{ $email->subject !== '' ? $email->subject : '(sin asunto)' }}</a></td>
                            <td data-etiqueta="Acciones">
                                <div class="acc">
                                    <a class="boton secundario pequeno" href="{{ route('admin.inbound.show', $email) }}">Ver</a>
                                    <form action="{{ route('admin.inbound.not-spam', $email) }}" method="post">@csrf<button type="submit" class="boton secundario pequeno">No es spam</button></form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No hay posible spam.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </details>
@endsection
