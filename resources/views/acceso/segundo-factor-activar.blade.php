{{-- Activar la verificación en dos pasos (administrador). Mockup validado: docs/plan/mockups/acceso-f-segundo-factor-activar.html --}}
@extends('layouts.acceso')
@section('titulo', 'Activar verificación en dos pasos')
@section('pie')
    Cuenta: {{ $email }} · Administrador
@endsection
@section('contenido')
    <h1>Activa la verificación en dos pasos</h1>
    <p class="sub">Como administrador, tu cuenta necesita un segundo paso al entrar. Hazlo una sola vez.</p>
    <ol class="pasos-2fa">
        <li>Instala en tu móvil una app de autenticación (Google Authenticator, Microsoft Authenticator, Authy…).</li>
        <li>Añade una cuenta nueva con esta clave:
            <div class="clave-2fa">{{ $clave }}</div>
            <a href="{{ $enlace }}" style="font-size:.9rem">Abrir en mi app (enlace otpauth)</a>
        </li>
        <li>Escribe aquí el código de 6 dígitos que te muestra.</li>
    </ol>
    @if ($errors->any())
        <div class="alerta error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form action="{{ route('two-factor.setup.confirm') }}" method="post">
        @csrf
        @include('acceso._codigo', ['etiqueta' => 'Código de 6 dígitos'])
        <button type="submit" class="boton principal ancho">Activar</button>
    </form>
@endsection
