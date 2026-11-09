{{--
    Página de llegada PROVISIONAL tras entrar (paso 2). En el paso 4 se sustituye por «Mis tickets» o
    «Bandeja» según el mockup validado. Solo demuestra que el acceso funciona.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body style="padding:2rem">
    <h1>{{ config('app.name') }}</h1>
    @if (session('status'))
        <div class="alerta ok" role="status">{{ session('status') }}</div>
    @endif
    <p>Has entrado como <strong>{{ $usuario->name }}</strong> ({{ $usuario->role->label() }}).</p>
    <p>El panel está en construcción.</p>
    <form action="{{ route('logout') }}" method="post">
        @csrf
        <button type="submit" class="boton secundario">Salir</button>
    </form>
</body>
</html>
