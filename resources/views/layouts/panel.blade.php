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
     - El menú del equipo solo enlaza lo que existe (Bandeja y Registros). «Empresas», «Productos y categorías»,
       «Usuarios» y «Mis datos» salen en el mockup pero NO se enlazan hasta implementarlos (sin enlaces rotos).
    La campana de «Avisos» cuenta los avisos sin leer de tickets que esta persona puede ver (User::visibleNotices).
    Pendiente: «Mi empresa» / «Mis datos», pantallas aún sin implementar.
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
    $unreadNotices = $user->visibleNotices()->whereNull('read_at')->count();
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

                {{-- «Registros»: solo el administrador (habilidad view-records). <details>: se pliega sin JavaScript;
                     abierto por defecto si estás dentro de una de sus pantallas. --}}
                @can('view-records')
                    <details class="seccion-menu" @if (request()->routeIs('records.*')) open @endif>
                        <summary>Registros</summary>
                        <a href="{{ route('records.mail') }}" @if (request()->routeIs('records.mail')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>Registro de correos</a>
                        <a href="{{ route('records.audit') }}" @if (request()->routeIs('records.audit')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 3 8 7 10 4-2 7-5.5 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg>Auditoría</a>
                        <a href="{{ route('records.backups') }}" @if (request()->routeIs('records.backups')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>Copias de seguridad</a>
                    </details>
                @endcan
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

            <div class="cabecera-derecha">
            {{-- Campana: icono SVG en línea (sin dependencias). El texto accesible va en aria-label; el número es solo visual. --}}
            <a class="campana" href="{{ route('notices.index') }}" @if (request()->routeIs('notices.*')) aria-current="page" @endif
               aria-label="Avisos{{ $unreadNotices > 0 ? ': '.$unreadNotices.' sin leer' : '' }}" title="Avisos">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                @if ($unreadNotices > 0)<b aria-hidden="true">{{ $unreadNotices > 9 ? '9+' : $unreadNotices }}</b>@endif
            </a>

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
            </div>
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
