{{--
    Marco del panel (cabecera, menú lateral y contenido) de las pantallas de cliente.
    Cuándo tocarlo: solo con un cambio del diseño validado (docs/plan/mockups/mis-tickets-a-lista-tabla.html).
    Hijas: tickets/index, tickets/create y tickets/show.
    Pendiente (paso 5): la campana de «Avisos»; y «Mi empresa» / «Mis datos», pantallas aún sin implementar:
    no se enlazan para no ofrecer enlaces rotos.
    Variables: $user (quien ha entrado) y, en las hijas, @section('titulo'), @section('menu') y @section('contenido').
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/panel.css') }}">
</head>
<body>
<header class="cabecera">
    <img src="{{ asset('img/logo-LM.svg') }}" alt="Ling Marco &amp; Asociados">
    <div class="usuario">
        <span>{{ $user->name }}@if ($user->company) · {{ $user->company->name }}@endif</span>
        <form action="{{ route('logout') }}" method="post">
            @csrf
            <button type="submit" class="enlace">Salir</button>
        </form>
    </div>
</header>

<div class="marco">
    <nav class="menu" aria-label="Menú del panel">
        @if ($user->company)
            <p class="empresa">{{ $user->company->name }}<br>Rol: {{ mb_strtolower($user->role->label()) }}</p>
        @endif
        <a href="{{ route('tickets.index') }}" @if (request()->routeIs('tickets.index')) aria-current="page" @endif>
            {{ $user->role === \App\Enums\UserRole::CompanyAdmin ? 'Tickets de mi empresa' : 'Mis tickets' }}
        </a>
        <a href="{{ route('tickets.create') }}" @if (request()->routeIs('tickets.create')) aria-current="page" @endif>Nuevo ticket</a>
    </nav>

    <main>
        @if (session('status'))
            <div class="alerta ok" role="status">{{ session('status') }}</div>
        @endif
        @yield('contenido')
    </main>
</div>
</body>
</html>
