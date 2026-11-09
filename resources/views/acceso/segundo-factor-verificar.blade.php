{{-- Verificación en dos pasos al entrar (administrador). Mockup validado: docs/plan/mockups/acceso-h-segundo-factor-verificar.html --}}
@extends('layouts.acceso')
@section('titulo', 'Verificación en dos pasos')
@section('pie')
    Esta verificación protege la cuenta de administrador.
@endsection
@section('contenido')
    <h1>Verificación en dos pasos</h1>
    <p class="sub">Escribe el código de 6 dígitos de tu app de autenticación.</p>
    @if ($errors->any())
        <div class="alerta error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form action="{{ route('two-factor.verify') }}" method="post">
        @csrf
        @include('acceso._codigo', ['etiqueta' => 'Código de 6 dígitos'])
        <button type="submit" class="boton principal ancho">Verificar</button>
    </form>
    <p class="enlace-olvido centro"><a href="{{ route('two-factor.recovery') }}">Usar un código de recuperación</a></p>
    <p class="enlace-olvido centro" style="margin-top:.4rem"><a href="{{ route('login') }}">Volver a iniciar sesión</a></p>
@endsection
