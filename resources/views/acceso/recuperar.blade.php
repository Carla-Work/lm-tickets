{{-- Pantalla «Recuperar contraseña», paso 1. Mockup validado: docs/plan/mockups/acceso-c-recuperar.html --}}
@extends('layouts.acceso')
@section('titulo', 'Recuperar contraseña')
@section('contenido')
    <h1>Recupera tu contraseña</h1>
    <p class="sub">Escribe el correo de tu cuenta y te enviaremos un enlace para crear una contraseña nueva.</p>
    @if ($errors->any())
        <div class="alerta error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form action="{{ route('password.email') }}" method="post">
        @csrf
        <div class="campo">
            <label for="email">Correo electrónico <span class="oblig" aria-hidden="true">*</span></label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="username" required>
        </div>
        <button type="submit" class="boton principal ancho">Enviar enlace</button>
    </form>
    <p class="enlace-olvido centro"><a href="{{ route('login') }}">Volver a iniciar sesión</a></p>
@endsection
