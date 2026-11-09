{{--
    Pantalla «Recuperar contraseña», paso 2. Mockup validado: docs/plan/mockups/acceso-d-recuperar-enviado.html
    R17: el mensaje es el mismo exista o no el correo. NO se muestra el correo escrito.
--}}
@extends('layouts.acceso')
@section('titulo', 'Revisa tu correo')
@section('contenido')
    <h1>Revisa tu correo</h1>
    <div class="alerta ok" role="status">Si el correo está registrado, te hemos enviado un enlace para crear una contraseña nueva. <strong>Caduca en {{ config('auth.passwords.users.expire') }} minutos y solo se puede usar una vez.</strong></div>
    <p class="sub">¿No llega? Mira también la carpeta de correo no deseado. Si sigue sin llegar, escríbenos y te ayudamos.</p>
    <a class="boton secundario ancho" href="{{ route('login') }}">Volver a iniciar sesión</a>
@endsection
