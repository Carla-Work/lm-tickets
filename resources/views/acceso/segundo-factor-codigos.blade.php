{{--
    Códigos de recuperación (administrador), paso 2. Mockup validado: docs/plan/mockups/acceso-g-segundo-factor-codigos.html
    Se ven UNA vez: en la base de datos solo quedan con hash. No se puede recargar para volver a verlos.
--}}
@extends('layouts.acceso')
@section('titulo', 'Códigos de recuperación')
@section('pie')
    Cuenta: {{ $email }} · Administrador
@endsection
@section('contenido')
    <h1>Guarda tus códigos de recuperación</h1>
    <div class="alerta ok" role="status"><strong>Verificación en dos pasos activada.</strong></div>
    <p class="sub">Si pierdes el móvil, cada uno de estos códigos te deja entrar <strong>una sola vez</strong>. Guárdalos en un lugar seguro: <strong>no se volverán a mostrar</strong>.</p>
    <div class="codigos-2fa">
        @foreach ($codigos as $codigo)
            <span>{{ $codigo }}</span>
        @endforeach
    </div>
    @if ($errors->any())
        <div class="alerta error" role="alert">Marca la casilla para confirmar que has guardado los códigos.</div>
    @endif
    <form action="{{ route('two-factor.finish') }}" method="post">
        @csrf
        <label class="confirmar"><input type="checkbox" name="guardados" value="1" required> He guardado estos códigos</label>
        <button type="submit" class="boton principal ancho">Continuar</button>
    </form>
@endsection
