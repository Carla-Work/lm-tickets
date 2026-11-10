{{--
    «Mis datos» (todos los roles). Mockups validados: mis-datos-a-cliente, mis-datos-b-interno, mis-datos-c-administrador.
    Cuándo tocarla: al cambiar la pantalla. Variables: $user.
    Trampas: la tarjeta del 2FA del administrador NO está (mockup pendiente de validar); el teléfono solo se pide al
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
                <div class="campo"><label for="nom">Nombre y apellidos</label><input type="text" id="nom" name="name" value="{{ old('name', $user->name) }}" required maxlength="255">@error('name')<p class="error-campo" role="alert">{{ $message }}</p>@enderror</div>
                @unless ($user->isInternal())
                    <div class="campo"><label for="tel">Teléfono</label><input type="tel" id="tel" name="phone" value="{{ old('phone', $user->phone) }}" maxlength="30">@error('phone')<p class="error-campo" role="alert">{{ $message }}</p>@enderror</div>
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

        <form class="tarjeta" action="{{ route('profile.password') }}" method="post" aria-label="Cambiar contraseña">
            @csrf
            <h2>Cambiar contraseña</h2>
            <div class="campo"><label for="actual">Contraseña actual</label><input type="password" id="actual" name="current_password" autocomplete="current-password" required>@error('current_password')<p class="error-campo" role="alert">{{ $message }}</p>@enderror</div>
            <div class="cols2">
                <div class="campo"><label for="nueva">Contraseña nueva</label><input type="password" id="nueva" name="password" autocomplete="new-password" required><p class="ayuda">Al menos {{ config('access.password.min_length') }} caracteres.</p>@error('password')<p class="error-campo" role="alert">{{ $message }}</p>@enderror</div>
                <div class="campo"><label for="repite">Repite la nueva</label><input type="password" id="repite" name="password_confirmation" autocomplete="new-password" required></div>
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
