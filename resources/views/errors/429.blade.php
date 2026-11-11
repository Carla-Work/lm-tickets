{{-- Error 429. Ver errors/layout (sin sesión ni base de datos; texto fijo, nunca el mensaje de la excepción). --}}
@extends('errors.layout')
@section('codigo', '429')
@section('titulo', 'Demasiados intentos')
@section('texto', 'Espera un minuto y vuelve a probar.')
