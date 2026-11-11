{{-- Error 419. Ver errors/layout (sin sesión ni base de datos; texto fijo, nunca el mensaje de la excepción). --}}
@extends('errors.layout')
@section('codigo', '419')
@section('titulo', 'La página ha caducado')
@section('texto', 'Por seguridad, los formularios caducan si pasa mucho tiempo. Vuelve a cargar la página e inténtalo de nuevo.')
