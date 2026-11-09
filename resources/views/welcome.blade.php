{{--
    Portada provisional. Sustituye a la página de demostración de Laravel.
    Cuándo tocarla: al implementar la portada real, tras validar su mockup en docs/plan/mockups/.
    El contenido está en HTML plano: se lee aunque falle el CSS o el JavaScript.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    </head>
    <body>
        <h1>{{ config('app.name') }}</h1>
        <p>Aplicación en construcción.</p>
    </body>
</html>
