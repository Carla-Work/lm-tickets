{{--
    «Mis datos» (todos los roles). Mockups validados: mis-datos-a-cliente, mis-datos-b-interno, mis-datos-c-administrador.
    Cuándo tocarla: al cambiar la pantalla. Variables: $user.
    «Verificación en dos pasos» (mockups validados mis-datos-c/d/e/f/g/h): solo el administrador (R21, opcional).
    Los campos de contraseña llevan el «ojo» (partials/ojo-contrasena + js/acceso.js, mejora progresiva).
    Trampas: el teléfono solo se pide al
    cliente; correo, empresa, equipo y rol son de solo lectura (los cambia otra persona).
--}}
@extends('layouts.panel')
@section('titulo', 'Mis datos')

@section('contenido')
    <h1>Mis datos</h1>
    <div class="apilado" style="max-width:760px;margin-top:1rem">
        <form class="tarjeta" action="{{ route('profile.update') }}" method="post" aria-label="Datos personales">
            @csrf
            <h2>Datos personales</h2>
            <div class="cols2">
                <div class="campo"><label for="nom">Nombre y apellidos</label><input type="text" id="nom" name="name" @errorAttrs('name') value="{{ old('name', $user->name) }}" required maxlength="255">@error('name')<p class="error-campo" id="err-name" role="alert">{{ $message }}</p>@enderror</div>
                @unless ($user->isInternal())
                    <div class="campo"><label for="tel">Teléfono</label><input type="tel" id="tel" name="phone" @errorAttrs('phone') value="{{ old('phone', $user->phone) }}" maxlength="30">@error('phone')<p class="error-campo" id="err-phone" role="alert">{{ $message }}</p>@enderror</div>
                @endunless
                <div class="campo"><label for="em">Correo electrónico</label><input type="email" id="em" value="{{ $user->email }}" readonly>@unless ($user->isInternal())<p class="ayuda">Para cambiarlo, habla con el administrador de tu empresa.</p>@endunless</div>
                @if ($user->isInternal())
                    <div class="campo"><label for="eq">Equipo</label><input type="text" id="eq" value="{{ $user->team?->label() ?? '—' }}" readonly><p class="ayuda">{{ $user->team ? 'Lo cambia el administrador.' : 'El administrador no pertenece a ningún equipo.' }}</p></div>
                    <div class="campo"><label for="rol">Rol</label><input type="text" id="rol" value="{{ $user->role->label() }}" readonly></div>
                @else
                    <div class="campo"><label for="emp">Empresa</label><input type="text" id="emp" value="{{ $user->company?->name }}" readonly></div>
                @endif
            </div>
            <button type="submit" class="boton secundario">Guardar datos</button>
        </form>

        @if ($user->role === \App\Enums\UserRole::Admin)
            @if (! $user->hasTwoFactorEnabled())
                <section class="tarjeta" aria-label="Verificación en dos pasos">
                    <h2>Verificación en dos pasos</h2>
                    <p style="margin:0 0 .8rem"><span class="estado-cuenta">○ Desactivada</span></p>
                    <p style="margin:0 0 1rem">Añade un segundo paso al entrar: además de tu contraseña, un código de 6 dígitos de una app de autenticación en tu móvil. <strong>Es opcional, pero muy recomendable</strong> en la cuenta del administrador, que lo ve todo.</p>
                    <a class="boton principal" href="{{ route('profile.two-factor.setup') }}">Activar la verificación en dos pasos</a>
                </section>
            @else
                <form class="tarjeta" action="{{ route('profile.two-factor.regenerate') }}" method="post" aria-label="Verificación en dos pasos">
                    @csrf
                    <h2>Verificación en dos pasos</h2>
                    <p style="margin:0 0 .8rem"><span class="estado-cuenta">✓ Activada</span> · App de autenticación</p>
                    <p style="margin:0 0 1rem">Te quedan <strong>{{ $user->remainingRecoveryCodes() }}</strong> códigos de recuperación sin usar. Cada uno sirve una sola vez.</p>
                    <div class="aviso" style="margin-bottom:1rem"><strong>Si generas códigos nuevos, los anteriores dejan de valer</strong> (los {{ $user->remainingRecoveryCodes() }} que te quedan también). Los nuevos se te mostrarán una sola vez.</div>
                    <div class="campo" style="max-width:340px"><label for="clave-conf">Tu contraseña actual <span class="oblig" aria-hidden="true">*</span></label><div class="pw"><input type="password" id="clave-conf" name="password" autocomplete="current-password" required>@include('partials.ojo-contrasena', ['para' => 'clave-conf'])</div><p class="ayuda">Te la pedimos para confirmar que eres tú.</p>@error('password')<p class="error-campo" role="alert">{{ $message }}</p>@enderror</div>
                    <button type="submit" class="boton secundario">Generar códigos nuevos</button>
                </form>

                <form class="tarjeta peligro" action="{{ route('profile.two-factor.disable') }}" method="post" aria-label="Desactivar la verificación en dos pasos">
                    @csrf
                    <h2>Desactivar la verificación en dos pasos</h2>
                    <p class="sub" style="color:var(--texto)">Volverás a entrar solo con tu contraseña y se borrarán la clave y los códigos de recuperación. Podrás activarla de nuevo cuando quieras.</p>
                    <div class="campo" style="max-width:340px"><label for="clave-des">Tu contraseña actual <span class="oblig" aria-hidden="true">*</span></label><div class="pw"><input type="password" id="clave-des" name="password" autocomplete="current-password" required>@include('partials.ojo-contrasena', ['para' => 'clave-des'])</div><p class="ayuda">Te la pedimos para confirmar que eres tú.</p></div>
                    <button type="submit" class="boton peligro-b">Desactivar</button>
                </form>
            @endif
        @endif

        <form class="tarjeta" action="{{ route('profile.password') }}" method="post" aria-label="Cambiar contraseña">
            @csrf
            <h2>Cambiar contraseña</h2>
            <div class="campo"><label for="actual">Contraseña actual</label><div class="pw"><input type="password" id="actual" name="current_password" @errorAttrs('current_password') autocomplete="current-password" required>@include('partials.ojo-contrasena', ['para' => 'actual'])</div>@error('current_password')<p class="error-campo" id="err-current_password" role="alert">{{ $message }}</p>@enderror</div>
            <div class="cols2">
                <div class="campo"><label for="nueva">Contraseña nueva</label><div class="pw"><input type="password" id="nueva" name="password" autocomplete="new-password" required>@include('partials.ojo-contrasena', ['para' => 'nueva'])</div><p class="ayuda">Al menos {{ config('access.password.min_length') }} caracteres.</p>@error('password')<p class="error-campo" role="alert">{{ $message }}</p>@enderror</div>
                <div class="campo"><label for="repite">Repite la nueva</label><div class="pw"><input type="password" id="repite" name="password_confirmation" autocomplete="new-password" required>@include('partials.ojo-contrasena', ['para' => 'repite'])</div></div>
            </div>
            <button type="submit" class="boton principal">Cambiar contraseña</button>
        </form>

        <section class="tarjeta" aria-label="Avisos">
            <h2>Avisos</h2>
            <p style="margin:0">Cuando haya novedades te avisamos <strong>siempre por correo y en el panel</strong>. No se pueden desactivar.</p>
        </section>

        @unless ($user->isInternal())
            <section class="tarjeta" aria-label="Privacidad">
                <h2>Privacidad</h2>
                <p style="margin:0">Para consultar, corregir o pedir que borremos tus datos personales, escríbenos a <a href="mailto:info@lingmarco.es">info@lingmarco.es</a>. Conservamos los tickets el tiempo que fija nuestra política de conservación de datos.</p>
            </section>
        @endunless
    </div>
@endsection
