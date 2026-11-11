{{--
    Códigos de recuperación, mostrados UNA vez (tras activar o regenerar). Mockups validados:
    mis-datos-g-administrador-2fa-activada-codigos.html y mis-datos-d-administrador-codigos-nuevos.html.
    Cuándo tocarla: al cambiar cómo se entregan. Variables: $codes (en claro, solo en esta página), $regenerated.
    Trampa: no se guardan ni se pueden volver a ver; al confirmar se borran de la sesión.
--}}
@extends('layouts.panel')
@section('titulo', $regenerated ? 'Códigos de recuperación nuevos' : 'Códigos de recuperación')

@section('contenido')
    <h1>Mis datos</h1>
    <section class="tarjeta" aria-label="Códigos de recuperación" style="max-width:560px;margin-top:1rem">
        <h2>{{ $regenerated ? 'Tus códigos de recuperación nuevos' : 'Guarda tus códigos de recuperación' }}</h2>
        <div class="aviso" style="margin-bottom:1rem">
            @if ($regenerated)
                <strong>Códigos nuevos generados.</strong> Los anteriores ya no valen.
            @else
                <strong>Verificación en dos pasos activada.</strong> Desde tu próximo acceso te pediremos el código.
            @endif
        </div>
        <p style="margin:0 0 1rem">Cada uno te deja entrar <strong>una sola vez</strong> si pierdes el móvil. Guárdalos en un lugar seguro: <strong>no se volverán a mostrar</strong>.</p>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.5rem;font-family:ui-monospace,Menlo,monospace;font-size:1rem;margin:0 0 1.1rem;max-width:420px">
            @foreach ($codes as $code)<span style="padding:.45rem .6rem;background:#f3f3f5;border:1px solid #d6d6dd;border-radius:6px;text-align:center">{{ $code }}</span>@endforeach
        </div>
        <form action="{{ route('profile.two-factor.finish') }}" method="post">
            @csrf
            <label style="display:flex;gap:.55rem;align-items:center;margin:0 0 1rem;font-size:.95rem"><input type="checkbox" name="guardados" value="1" required style="width:1.1rem;height:1.1rem"> He guardado estos códigos</label>
            @error('guardados')<p class="error-campo" role="alert">{{ $message }}</p>@enderror
            <button type="submit" class="boton principal">Continuar</button>
        </form>
    </section>
@endsection
