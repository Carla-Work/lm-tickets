{{--
    Marco del panel (barra lateral, cabecera y contenido): el del CLIENTE y el del EQUIPO de Ling Marco.
    Cuándo tocarlo: al cambiar la estructura del panel. La estructura (barra lateral fija con el logo arriba,
    cabecera fina con el menú de la cuenta, contenido con tope de ancho) sigue la de las aplicaciones
    asesoria y onesigw por petición de la usuaria (2026-11-10, sin mockup a propósito); los colores no cambian.
    Cambia solo el menú y la cabecera según `isInternal()`.
    Hijas: tickets/index, tickets/create, tickets/show, tickets/show-internal e inbox/index.
    Trampas:
     - El menú de la cuenta es un <details>: se abre y cierra sin JavaScript (requisito: el contenido y
       «Salir» deben funcionar sin JS). No lo sustituyas por un desplegable con JS.
     - «Salir» es un formulario POST (un enlace GET no debe cerrar sesión; LoginTest lo vigila).
     - El menú del equipo solo enlaza lo que existe (Bandeja). «Empresas», «Productos y categorías»,
       «Usuarios» y «Mis datos» salen en el mockup pero NO se enlazan hasta implementarlos (sin enlaces rotos).
    Pendiente (paso 5): la campana de «Avisos»; y «Mi empresa» / «Mis datos», pantallas aún sin implementar.
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
@php
    $internal = $user->isInternal();
    // Iniciales para el círculo de la cuenta: primera letra de las dos primeras palabras del nombre.
    $initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
@endphp
<body class="panel @if ($internal) interno @endif">
<div class="app">
    <aside class="barra">
        {{-- Mismo alto y mismo borde inferior que la cabecera, para que la línea no se corte al llegar a la barra. --}}
        <a class="logo" href="{{ route('panel') }}" aria-label="Ir al inicio">
            <img src="{{ asset('img/logo-LM.svg') }}" alt="Ling Marco &amp; Asociados">
        </a>

        <nav class="menu" aria-label="Menú del panel">
            @if ($internal)
                <a href="{{ route('inbox') }}" @if (request()->routeIs('inbox', 'tickets.show')) aria-current="page" @endif>Bandeja</a>
            @else
                <a href="{{ route('tickets.index') }}" @if (request()->routeIs('tickets.index')) aria-current="page" @endif>
                    {{ $user->role === \App\Enums\UserRole::CompanyAdmin ? 'Tickets de mi empresa' : 'Mis tickets' }}
                </a>
                <a href="{{ route('tickets.create') }}" @if (request()->routeIs('tickets.create')) aria-current="page" @endif>Nuevo ticket</a>
            @endif
        </nav>

        {{-- Pie de la barra: a quién pertenece esta sesión (panel interno o empresa) y con qué rol. --}}
        <div class="pie-barra">
            @if ($internal)
                <span class="etiqueta-panel">Panel interno</span>
                <p class="empresa">Ling Marco &amp; Asociados<br>Rol: {{ mb_strtolower($user->role->label()) }}</p>
            @elseif ($user->company)
                <p class="empresa">{{ $user->company->name }}<br>Rol: {{ mb_strtolower($user->role->label()) }}</p>
            @endif
        </div>
    </aside>

    <div class="columna">
        <header class="cabecera">
            <span class="contexto">
                @if ($internal)
                    Panel interno
                @elseif ($user->company)
                    {{ $user->company->name }}
                @endif
            </span>

            <details class="cuenta">
                <summary>
                    <span class="iniciales" aria-hidden="true">{{ $initials }}</span>
                    <span class="nombre">{{ $user->name }}</span>
                    <span class="sr-only">Mi cuenta</span>
                </summary>
                <div class="cuenta-menu">
                    <p class="cuenta-datos">
                        <strong>{{ $user->name }}</strong>
                        <small>
                            @if ($internal)
                                {{ $user->role->label() }}@if ($user->team) ({{ $user->team->label() }})@endif
                            @elseif ($user->company)
                                {{ $user->company->name }}
                            @endif
                        </small>
                    </p>
                    <form action="{{ route('logout') }}" method="post">
                        @csrf
                        <button type="submit" class="salir">Salir</button>
                    </form>
                </div>
            </details>
        </header>

        <main>
            @if (session('status'))
                <div class="alerta ok" role="status">{{ session('status') }}</div>
            @endif
            @yield('contenido')
        </main>
    </div>
</div>
</body>
</html>
