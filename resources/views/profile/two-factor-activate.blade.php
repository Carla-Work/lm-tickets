{{--
    Activar la verificación en dos pasos desde «Mis datos». Mockup validado: mis-datos-f-administrador-2fa-activar.html.
    Cuándo tocarla: al cambiar la activación (y entonces también TwoFactorSettingsController).
    Variables: $clave (agrupada de 4 en 4, para escribirla a mano), $enlace (otpauth). Sin QR a propósito (exigiría una librería).
--}}
@extends('layouts.panel')
@section('titulo', 'Activar la verificación en dos pasos')

@section('contenido')
    <h1>Mis datos</h1>
    <section class="tarjeta" aria-label="Activar la verificación en dos pasos" style="max-width:560px;margin-top:1rem">
        <p class="migas" style="margin:0 0 .6rem"><a href="{{ route('profile.show') }}">Mis datos</a> › Verificación en dos pasos</p>
        <h2>Activa la verificación en dos pasos</h2>
        <ol style="font-size:.95rem;color:var(--texto);padding-left:1.2rem;margin:0 0 1rem">
            <li style="margin-bottom:.5rem">Instala en tu móvil una app de autenticación (Google Authenticator, Microsoft Authenticator, Authy…).</li>
            <li style="margin-bottom:.5rem">Añade una cuenta nueva con esta clave:<div style="margin:.5rem 0;padding:.7rem;background:#f3f3f5;border:1px solid #d6d6dd;border-radius:8px;text-align:center;font-family:ui-monospace,Menlo,monospace;font-size:1.15rem;letter-spacing:.12em;user-select:all">{{ $clave }}</div><a href="{{ $enlace }}" style="font-size:.9rem">Abrir en mi app (enlace otpauth)</a></li>
            <li>Escribe aquí el código de 6 dígitos que te muestra.</li>
        </ol>
        <form action="{{ route('profile.two-factor.confirm') }}" method="post">
            @csrf
            <div class="campo" style="max-width:340px">
                <label for="codigo">Código de 6 dígitos <span class="oblig" aria-hidden="true">*</span></label>
                <input type="text" id="codigo" name="codigo" @errorAttrs('codigo') inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required placeholder="123 456" style="font-size:1.35rem;letter-spacing:.25em;text-align:center">
                @error('codigo')<p class="error-campo" id="err-codigo" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="acciones">
                <button type="submit" class="boton principal">Activar</button>
                <a class="boton secundario" href="{{ route('profile.show') }}">Cancelar</a>
            </div>
        </form>
        <p class="ayuda" style="margin:1rem 0 0;font-size:.85rem;color:var(--texto-suave)">La clave solo vale para esta activación. Si cancelas, no se guarda nada.</p>
    </section>
@endsection
