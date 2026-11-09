{{--
    Pantalla «Crea tu contraseña». Mockup validado: docs/plan/mockups/acceso-e-nueva-contrasena.html
    Sirve para recuperar la contraseña Y para activar una cuenta invitada (R18); cambia el destino.
--}}
@extends('layouts.acceso')
@section('titulo', 'Crear contraseña')
@section('pie')
    ¿Problemas con el enlace o con la invitación? Escríbenos a <a href="mailto:info@lingmarco.es">info@lingmarco.es</a> o llama al 928 234 434.
@endsection
@section('contenido')
    <h1>Crea tu contraseña</h1>
    @if ($email !== '')
        <p class="sub">Cuenta: <strong>{{ $email }}</strong></p>
    @endif
    {{-- R17: un solo mensaje para cualquier fallo del enlace o de la contraseña. --}}
    @if ($errors->any())
        <div class="alerta error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form action="{{ route($broker === 'invitations' ? 'invitation.store' : 'password.update') }}" method="post">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ $email }}">
        @include('acceso._contrasena', ['id' => 'password', 'etiqueta' => 'Contraseña nueva', 'autocompletar' => 'new-password', 'ayuda' => 'Al menos '.config('access.password.min_length').' caracteres.'])
        @include('acceso._contrasena', ['id' => 'password_confirmation', 'etiqueta' => 'Repite la contraseña', 'autocompletar' => 'new-password'])
        <button type="submit" class="boton principal ancho">Guardar contraseña</button>
    </form>
@endsection
@push('scripts') <script src="{{ asset('js/acceso.js') }}"></script> @endpush
