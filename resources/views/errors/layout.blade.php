{{--
    Marco común de las páginas de error (403, 404, 419, 429, 500, 503), en español y con la marca. Defecto D-11 de
    docs/plan/defectos-conocidos.md. Sin mockup propio: se construye con el estilo ya validado (la usuaria lo dispensó el 2026-11-11).
    Cuándo tocarlo: al cambiar el aspecto o los textos de los errores.
    Trampas:
     - NO usa sesión, usuario ni base de datos: un error 500 puede venir justo de ahí, y la página de error no puede fallar a su vez.
       Por eso el enlace es `url('/')` (la raíz ya reparte según quien seas) y no `route('panel')`.
     - NO escribe nunca `$exception->getMessage()`: puede llevar rutas, consultas o nombres internos (p. ej. «This action is
       unauthorized.» o un mensaje de SQL). Los textos son fijos, en cada vista.
    Secciones: titulo, codigo, texto.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('titulo') · Ling Marco Tickets</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('img/favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="pagina-error">
    <main>
        <img src="{{ asset('img/logo-LM.svg') }}" alt="Ling Marco &amp; Asociados" class="logo-error">
        <p class="codigo-error">Error @yield('codigo')</p>
        <h1>@yield('titulo')</h1>
        <p class="texto-error">@yield('texto')</p>
        <p><a class="boton principal" href="{{ url('/') }}">Volver al inicio</a></p>
    </main>
</body>
</html>
