{{-- Pantalla «Acceder». Mockup validado: docs/plan/mockups/acceso-a-entrar.html (y -b- con el error). --}}
@extends('layouts.acceso')
@section('titulo', 'Acceder')
@section('contenido')
    <div class="bienvenida"><small>Bienvenido a</small><strong>Ling Marco Tickets</strong></div>
    <p class="sub">Inicia sesión para acceder a tu panel de incidencias.</p>

    @if (session('status'))
        <div class="alerta ok" role="status">{{ session('status') }}</div>
    @endif
    {{-- R17: un único mensaje, sea cual sea el motivo. --}}
    @if ($errors->any())
        <div class="alerta error" role="alert"><strong>No hemos podido acceder.</strong> {{ $errors->first() }}</div>
    @endif

    <form action="{{ route('login.store') }}" method="post">
        @csrf
        <div class="campo">
            <label for="email">Correo electrónico <span class="oblig" aria-hidden="true">*</span></label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="username" required>
        </div>
        @include('acceso._contrasena', ['id' => 'password', 'etiqueta' => 'Contraseña', 'autocompletar' => 'current-password'])
        <button type="submit" class="boton principal ancho">Iniciar sesión</button>
    </form>
    <p class="enlace-olvido centro"><a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a></p>
@endsection
@push('scripts') <script src="{{ asset('js/acceso.js') }}"></script> @endpush
