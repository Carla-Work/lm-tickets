{{--
    Marco de las pantallas de acceso: panel de marca a la izquierda, formulario a la derecha.
    Cuándo tocarlo: solo con un cambio del diseño validado (docs/plan/mockups/acceso-a-entrar.html).
    Hijas: acceso/entrar, recuperar, recuperar-enviado y nueva-contrasena.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/acceso.css') }}">
</head>
<body>
<div class="acceso2">
    <section class="acceso-marca" aria-label="Ling Marco">
        <div>
            <span class="marca-logo"><img src="{{ asset('img/logo-LM-b.svg') }}" alt="Ling Marco &amp; Asociados"></span>
            <p class="marca-sub">Soporte y gestión de incidencias</p>
        </div>
        <div class="marca-texto">
            <p class="etiqueta">Panel de clientes · Ling Marco</p>
            <h2>Tus incidencias, siempre a la vista.</h2>
            <p>Abre un ticket, sigue su estado y habla con el equipo de Ling Marco desde un único sitio. Te avisamos por correo y en el panel en cuanto haya novedades.</p>
        </div>
    </section>
    <main class="acceso-form">
        <div class="acceso-form-int">
            @yield('contenido')
            <p class="pie-acceso">
                @hasSection('pie')
                    @yield('pie')
                @else
                    ¿No tienes acceso? Las cuentas las crea Ling Marco o el administrador de tu empresa: no hay registro libre. Escríbenos a <a href="mailto:info@lingmarco.es">info@lingmarco.es</a> o llama al 928 234 434.
                @endif
            </p>
            <p class="copy">© {{ date('Y') }} Ling Marco &amp; Asociados SL</p>
        </div>
    </main>
</div>
@stack('scripts')
</body>
</html>
