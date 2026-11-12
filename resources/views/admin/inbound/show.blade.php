{{--
    Detalle de un correo en cuarentena. Mockup validado: correo-cuarentena-b-detalle.html.
    Cuándo tocarla: al cambiar el detalle. Variables: $email (con adjuntos), $department, $suggestedCompany, $knownUser, $customers (por empresa),
    $suggestedCustomerId, $categories, $products, $suggestedProductId, $agents (por equipo), $companies.
    Trampas:
     · El texto del correo se pinta como texto plano escapado, en un <div> con white-space: pre-wrap. NUNCA {!! !!}: lo escribe un desconocido.
     · Sin JavaScript el producto no se filtra por la persona elegida: se ofrecen los de la empresa SUGERIDA; el servidor valida que sea de la empresa de la persona (R6).
     · El administrador no responde al remitente (R10): invita, crea el ticket o marca spam.
--}}
@extends('layouts.panel')
@section('titulo', $email->subject !== '' ? $email->subject : '(sin asunto)')

@section('contenido')
    @php
        $tz = config('app.timezone');
        $quarantined = in_array($email->status, [\App\Models\InboundEmail::PENDING, \App\Models\InboundEmail::SPAM], true);
        $purgeDays = $email->status === \App\Models\InboundEmail::SPAM ? (int) config('inbound.spam_days') : (int) config('inbound.pending_days');
    @endphp
    <p class="migas"><a href="{{ route('admin.inbound.index') }}">Correo entrante</a> › {{ \Illuminate\Support\Str::limit($email->subject !== '' ? $email->subject : '(sin asunto)', 60) }}</p>
    <div class="titulo-fila"><div>
        <h1>{{ $email->subject !== '' ? $email->subject : '(sin asunto)' }}</h1>
        <p class="sub">Recibido el {{ $email->received_at->timezone($tz)->translatedFormat('j M, H:i') }} en {{ $email->mailboxAddress() }}@if ($quarantined) · se guarda hasta el {{ $email->received_at->copy()->addDays($purgeDays)->timezone($tz)->translatedFormat('j M Y') }}@endif</p>
    </div></div>

    @if ($errors->any())
        <div class="alerta error" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="dos">
        <div class="apilado">
            <section class="tarjeta" aria-label="Correo">
                <h2>El correo</h2>
                <dl class="detalles">
                    <dt>De</dt><dd>{{ $email->from_name }} &lt;{{ $email->from_email }}&gt;</dd>
                    <dt>Para</dt><dd>{{ implode(', ', (array) $email->to_addresses) }}</dd>
                    <dt>Comprobación</dt><dd>@if ($email->auth_ok)<span class="pista ok">SPF/DKIM correctos</span>@else<span class="pista alerta">Sin SPF/DKIM correctos</span>@endif</dd>
                    <dt>Adjuntos</dt>
                    <dd>@forelse ($email->attachments as $attachment)<span class="adjunto-ico">{{ $attachment->original_name }} · {{ number_format($attachment->size / 1024, 0, ',', '.') }} KB</span>@empty Ninguno @endforelse</dd>
                </dl>
                <h3 style="margin:1.1rem 0 .5rem;font-size:1rem">Mensaje <span class="rotulo">texto plano</span></h3>
                <div class="cuerpo-correo-plano">{{ $email->body }}</div>
            </section>
        </div>

        <aside>
            <section class="tarjeta lateral" aria-label="Qué hacer">
                <h2>Por qué está aquí</h2>
                <p>@foreach ((array) $email->reasons as $reason)<span class="pista {{ str_contains($reason, 'suplantación') || str_contains($reason, 'SPF') ? 'alerta' : '' }}">{{ $reason }}</span>@endforeach</p>

                @if ($quarantined)
                    @if ($knownUser === null)
                        <div class="grupo-accion">
                            <h3>Invitar al remitente</h3>
                            @if ($suggestedCompany)<p class="aviso">Su dominio es de <strong>{{ $suggestedCompany->name }}</strong>.</p>@endif
                            <form action="{{ route('admin.inbound.invite', $email) }}" method="post">@csrf
                                <div class="campo"><label for="inv-name">Nombre</label><input type="text" id="inv-name" name="name" required maxlength="255" value="{{ old('name', $email->from_name) }}"></div>
                                <div class="campo"><label for="inv-company">Empresa</label>
                                    <select id="inv-company" name="company_id" required>
                                        @unless ($suggestedCompany)<option value="" disabled selected>Elige una empresa</option>@endunless
                                        @foreach ($companies as $company)<option value="{{ $company->id }}" @selected($suggestedCompany?->id === $company->id)>{{ $company->name }}</option>@endforeach
                                    </select></div>
                                <div class="campo"><label for="inv-role">Rol</label><select id="inv-role" name="role"><option value="company_user">Usuario</option><option value="company_admin">Administrador de empresa</option></select></div>
                                <button type="submit" class="boton principal">Enviar invitación</button>
                                <p class="ayuda">Cuando acepte, el correo no crea ticket solo: se crea desde aquí.</p>
                            </form>
                        </div>
                    @endif

                    <div class="grupo-accion">
                        <h3>Crear ticket para una persona</h3>
                        <form action="{{ route('admin.inbound.ticket', $email) }}" method="post">@csrf
                            <div class="campo"><label for="t-persona">Persona</label>
                                <select id="t-persona" name="solicitante" required>
                                    <option value="" disabled @selected(! old('solicitante', $suggestedCustomerId))>Elige una persona</option>
                                    @foreach ($customers as $company => $people)
                                        <optgroup label="{{ $company }}">@foreach ($people as $person)<option value="{{ $person->id }}" @selected((int) old('solicitante', $suggestedCustomerId) === $person->id)>{{ $person->name }}</option>@endforeach</optgroup>
                                    @endforeach
                                </select></div>
                            <div class="campo"><label for="t-cat">Tipo de incidencia</label>
                                <select id="t-cat" name="category_id" required>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected((int) old('category_id') === $category->id || $category->name === config('inbound.default_category') && ! old('category_id'))>{{ $category->name }}</option>@endforeach</select></div>
                            @if ($products->isNotEmpty())
                                <div class="campo"><label for="t-prod">Producto</label>
                                    <select id="t-prod" name="product_id" required>@foreach ($products as $product)<option value="{{ $product->id }}" @selected((int) old('product_id', $suggestedProductId) === $product->id)>{{ $product->name }}{{ $suggestedProductId === $product->id ? ' (por palabra clave)' : '' }}</option>@endforeach</select>
                                    <p class="ayuda">Productos de la empresa sugerida; si eliges a alguien de otra, el servidor rechazará un producto que no sea suyo.</p></div>
                            @endif
                            @if ($agents->isEmpty())
                                <p class="aviso interna" role="alert">No hay ningún agente activo que atienda {{ $department?->label() }}: no se puede crear el ticket hasta que lo haya (Administración › Usuarios).</p>
                            @endif
                            <div class="campo"><label for="t-asig">Asignar a</label>
                                <select id="t-asig" name="asignado" required>@foreach ($agents as $team => $people)<optgroup label="{{ $team }}">@foreach ($people as $person)<option value="{{ $person->id }}" @selected((int) old('asignado') === $person->id)>{{ $person->name }}</option>@endforeach</optgroup>@endforeach</select></div>
                            <div class="casilla-aviso"><input type="checkbox" id="t-notif" name="notificar" value="1" @checked(! session()->hasOldInput() || old('notificar'))><div><label for="t-notif">Avisar a la persona de que su correo es ya un ticket</label></div></div>
                            <button type="submit" class="boton secundario" style="margin-top:.8rem" @disabled($agents->isEmpty())>Crear ticket</button>
                            <p class="ayuda">Departamento: {{ $department?->label() }} (sale del buzón). Canal: Correo. Los adjuntos pasan al ticket.</p>
                        </form>
                    </div>

                    <div class="grupo-accion">
                        @if ($email->status === \App\Models\InboundEmail::SPAM)
                            <form action="{{ route('admin.inbound.not-spam', $email) }}" method="post">@csrf<button type="submit" class="boton secundario">No es spam</button></form>
                        @else
                            <form action="{{ route('admin.inbound.spam', $email) }}" method="post">@csrf<button type="submit" class="boton secundario">Marcar como spam</button></form>
                        @endif
                    </div>
                @else
                    <p class="aviso">Este correo ya se gestionó (@if ($email->ticket_id)<a href="{{ route('tickets.show', $email->ticket_id) }}">ver el ticket</a>@else{{ $email->status }}@endif).</p>
                @endif
            </section>
        </aside>
    </div>
@endsection
