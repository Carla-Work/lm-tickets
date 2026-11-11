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
     - El menú del equipo solo enlaza lo que existe (Bandeja, Administración › Empresas / Productos y categorías / Usuarios, Registros).

    La campana de «Avisos» cuenta los avisos sin leer de tickets que esta persona puede ver (User::visibleNotices).
    «Mis datos» se abre desde el menú de la cuenta (cabecera). Pendiente en esa pantalla: el 2FA del administrador (activar, desactivar, códigos nuevos), a la espera de mockup validado.
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
                <a href="{{ route('reports.index') }}" @if (request()->routeIs('reports.*')) aria-current="page" @endif>Informes</a>

                {{-- «Administración»: solo el administrador (policies). Cada entrada sale cuando existe su pantalla. --}}
                @can('viewAny', \App\Models\Company::class)
                    <details class="seccion-menu" @if (request()->routeIs('admin.*')) open @endif>
                        <summary>Administración</summary>
                        <a href="{{ route('admin.companies.index') }}" @if (request()->routeIs('admin.companies.*')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V5l8-2v18"/><path d="M12 9h8v12"/><path d="M8 8v.01M8 12v.01M8 16v.01M16 13v.01M16 17v.01"/></svg>Empresas</a>
                        <a href="{{ route('admin.catalog.index') }}" @if (request()->routeIs('admin.catalog.*')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="m3 8 0 8 9 5 9-5V8"/><path d="M12 13v8"/></svg>Productos y categorías</a>
                        <a href="{{ route('admin.tags.index') }}" @if (request()->routeIs('admin.tags.*')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><path d="M7.5 7.5v.01"/></svg>Etiquetas</a>
                        <a href="{{ route('admin.staff.index') }}" @if (request()->routeIs('admin.staff.*')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14c2 .6 3.5 2.4 3.5 6"/></svg>Usuarios</a>
                    </details>
                @endcan

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
                <a href="{{ route('company.show') }}" @if (request()->routeIs('company.*')) aria-current="page" @endif>Mi empresa</a>
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
                    <a class="salir" href="{{ route('profile.show') }}" @if (request()->routeIs('profile.*')) aria-current="page" @endif>Mis datos</a>
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
{{-- «Ojo» de las contraseñas (js/acceso.js): mejora progresiva, sin él el contenido funciona igual. --}}
<script src="{{ asset('js/acceso.js') }}"></script>
</body>
</html>
