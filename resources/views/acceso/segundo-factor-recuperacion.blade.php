{{-- Entrar con un código de recuperación (administrador). Mockup validado: docs/plan/mockups/acceso-i-segundo-factor-recuperacion.html --}}
@extends('layouts.acceso')
@section('titulo', 'Código de recuperación')
@section('pie')
    Si también perdiste los códigos, contacta con quien administra el servidor.
@endsection
@section('contenido')
    <h1>Código de recuperación</h1>
    <p class="sub">Escribe uno de los códigos de recuperación que guardaste. Cada uno sirve una sola vez.</p>
    @if ($errors->any())
        <div class="alerta error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form action="{{ route('two-factor.recovery.use') }}" method="post">
        @csrf
        @include('acceso._codigo', ['etiqueta' => 'Código de recuperación', 'recuperacion' => true, 'ayuda' => 'Te quedan '.$quedan.' códigos sin usar.'])
        <button type="submit" class="boton principal ancho">Entrar</button>
    </form>
    <p class="enlace-olvido centro"><a href="{{ route('two-factor.challenge') }}">Volver a usar mi app</a></p>
@endsection
