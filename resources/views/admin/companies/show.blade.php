{{--
    Detalle de una empresa (Administración). Mockup validado: admin-empresa-b-detalle.html.
    Cuándo tocarla: al cambiar el detalle. Variables: $company, $users, $products, $assignedIds, $stats.
    Trampas:
     - «Último acceso» del mockup NO existe (no se guarda ese dato).
     - La baja pide escribir el nombre de la empresa; reactivar no pide nada.
     - Los usuarios los gestiona el administrador de empresa (pantalla «Mi empresa»); aquí solo se reenvía la
       invitación pendiente (UserPolicy::resendInvitation, vía @can).
--}}
@extends('layouts.panel')
@section('titulo', $company->name)

@section('contenido')
    @php
        $activated = $users->contains(fn ($u) => $u->activated_at !== null);
        $state = ! $company->isActive() ? '○ De baja' : ($activated ? '✓ Activa' : '✉ Invitación pendiente');
        $tz = config('app.timezone');
    @endphp
    <p class="migas"><a href="{{ route('admin.companies.index') }}">Empresas</a> › {{ $company->name }}</p>

    <section class="cabecera-ticket" style="border-left-color:var(--principal)" aria-label="Resumen">
        <h1 style="margin-top:0">{{ $company->name }}</h1>
        <div class="chips"><span class="estado-cuenta">{{ $state }}</span><span>CIF {{ $company->tax_id }}</span><span>Cliente desde {{ $company->created_at?->timezone($tz)->translatedFormat('j M Y') }}</span></div>
    </section>

    <div class="dos">
        <div class="apilado">
            <form class="tarjeta" action="{{ route('admin.companies.update', $company) }}" method="post" aria-label="Datos de la empresa">
                @csrf
                <h2>Datos de la empresa</h2>
                <div class="cols2">
                    <div class="campo"><label for="name">Nombre o razón social <span class="oblig" aria-hidden="true">*</span></label><input type="text" id="name" name="name" @errorAttrs('name') value="{{ old('name', $company->name) }}" required maxlength="255">@error('name')<p class="error-campo" id="err-name" role="alert">{{ $message }}</p>@enderror</div>
                    <div class="campo"><label for="tax_id">CIF <span class="oblig" aria-hidden="true">*</span></label><input type="text" id="tax_id" name="tax_id" @errorAttrs('tax_id') value="{{ old('tax_id', $company->tax_id) }}" required maxlength="20">@error('tax_id')<p class="error-campo" id="err-tax_id" role="alert">{{ $message }}</p>@enderror</div>
                    <div class="campo"><label for="phone">Teléfono</label><input type="tel" id="phone" name="phone" @errorAttrs('phone') value="{{ old('phone', $company->phone) }}" maxlength="30">@error('phone')<p class="error-campo" id="err-phone" role="alert">{{ $message }}</p>@enderror</div>
                    <div class="campo"><label for="contact_email">Correo de contacto</label><input type="email" id="contact_email" name="contact_email" @errorAttrs('contact_email') value="{{ old('contact_email', $company->contact_email) }}" maxlength="255">@error('contact_email')<p class="error-campo" id="err-contact_email" role="alert">{{ $message }}</p>@enderror</div>
                </div>
                <button type="submit" class="boton secundario">Guardar datos</button>
            </form>

            <form class="tarjeta" action="{{ route('admin.companies.products', $company) }}" method="post" aria-label="Productos contratados">
                @csrf
                <h2>Productos contratados</h2>
                <p class="sub">Solo aparecen estos al abrir un ticket. Los tickets ya abiertos conservan su producto aunque lo quites aquí.</p>
                @include('admin.companies._products', ['products' => $products, 'checked' => array_map('intval', (array) old('productos', $assignedIds))])
                @error('productos.*')<p class="error-campo" role="alert">{{ $message }}</p>@enderror
                <button type="submit" class="boton principal">Guardar productos</button>
            </form>

            {{-- Dominios de correo (P1, correo entrante): solo una PISTA para reconocer a la empresa; no crea cuentas ni tickets. Sin mockup propio: estilo ya validado (decisión anotada en la bitácora). --}}
            <section class="tarjeta" aria-label="Dominios de correo">
                <h2>Dominios de correo</h2>
                <p class="sub">Si llega un correo de una persona sin cuenta con uno de estos dominios, va a «Correo entrante» con esta empresa sugerida. No crea cuentas ni tickets por sí solo.</p>
                @error('dominio')<div class="alerta error" role="alert">{{ $message }}</div>@enderror
                <div class="palabras">
                    @forelse ($company->domains()->orderBy('domain')->get() as $domain)
                        <span class="palabra">{{ $domain->domain }}<form action="{{ route('admin.inbound.domains.destroy', [$company, $domain]) }}" method="post">@csrf<button type="submit" aria-label="Quitar el dominio {{ $domain->domain }}">×</button></form></span>
                    @empty
                        <span class="secundario">Ninguno todavía.</span>
                    @endforelse
                </div>
                <form class="fila-add" action="{{ route('admin.inbound.domains.store', $company) }}" method="post">@csrf
                    <label for="dominio" class="sr-only">Nuevo dominio</label>
                    <input type="text" id="dominio" name="dominio" placeholder="hermanossantana.es" maxlength="190" required>
                    <button class="boton secundario pequeno" type="submit">Añadir</button>
                </form>
                <p class="ayuda">No valen los dominios de correo gratuito (gmail, hotmail…).</p>
            </section>

            <section class="tarjeta" aria-label="Usuarios de la empresa">
                <h2>Usuarios de la empresa</h2>
                <p class="sub">Los usuarios de esta empresa los gestiona su administrador de empresa. Tú puedes reenviar invitaciones.</p>
                <div class="tabla-caja" style="border:0">
                    <table class="fichas">
                        <thead><tr><th scope="col">Nombre</th><th scope="col">Rol</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
                        <tbody>
                            @forelse ($users as $member)
                                <tr>
                                    <td class="asunto-celda" data-etiqueta="Nombre"><strong>{{ $member->name }}</strong><span class="secundario">{{ $member->email }}</span></td>
                                    <td data-etiqueta="Rol"><span class="rolchip {{ $member->role === \App\Enums\UserRole::CompanyAdmin ? 'admin' : '' }}">{{ $member->role->label() }}</span></td>
                                    <td data-etiqueta="Estado">
                                        <span class="estado-cuenta">{{ $member->isDeactivated() ? '○ De baja' : ($member->isPendingInvitation() ? '✉ Invitación pendiente' : '✓ Activo') }}</span>
                                    </td>
                                    <td data-etiqueta="Acciones">
                                        @can('resendInvitation', $member)
                                            <div class="acc"><form action="{{ route('users.invitation.resend', $member) }}" method="post">@csrf<button type="submit" class="boton secundario pequeno">Reenviar invitación</button></form></div>
                                        @else
                                            <span class="secundario">—</span>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td>Esta empresa no tiene usuarios.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside class="apilado" aria-label="Actividad y baja">
            <div class="tarjeta">
                <h2>Actividad</h2>
                <dl class="detalles">
                    <dt>Tickets abiertos</dt><dd>{{ $stats['open'] }}</dd>
                    <dt>Tickets en total</dt><dd>{{ $stats['total'] }}</dd>
                    <dt>Último ticket</dt><dd>{{ $stats['last'] ? \Illuminate\Support\Carbon::parse($stats['last'], 'UTC')->timezone($tz)->translatedFormat('j M, H:i') : '—' }}</dd>
                </dl>
                <p style="margin:1rem 0 0"><a href="{{ route('inbox', ['empresa' => $company->id]) }}">Ver los tickets de esta empresa</a></p>
            </div>

            @if ($company->isActive())
                <form class="tarjeta peligro" action="{{ route('admin.companies.deactivate', $company) }}" method="post" aria-label="Dar de baja">
                    @csrf
                    <h2>Dar de baja la empresa</h2>
                    <p class="sub" style="color:var(--texto)">Sus usuarios ya no podrán acceder. Los tickets se conservan según el plan de conservación de datos (provisional: 2 años) y luego se borran o anonimizan.</p>
                    <div class="campo">
                        <label for="confirmacion">Para confirmar, escribe «{{ $company->name }}»</label>
                        <input type="text" id="confirmacion" name="confirmacion" @errorAttrs('confirmacion') required autocomplete="off">
                        @error('confirmacion')<p class="error-campo" id="err-confirmacion" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="boton peligro-b">Dar de baja</button>
                </form>
            @else
                <form class="tarjeta" action="{{ route('admin.companies.reactivate', $company) }}" method="post" aria-label="Reactivar">
                    @csrf
                    <h2>Empresa de baja</h2>
                    <p class="sub">Está de baja desde el {{ $company->deactivated_at?->timezone($tz)->translatedFormat('j M Y') }}. Si la reactivas, sus usuarios activos vuelven a poder entrar.</p>
                    <button type="submit" class="boton secundario">Reactivar empresa</button>
                </form>
            @endif
        </aside>
    </div>
@endsection
