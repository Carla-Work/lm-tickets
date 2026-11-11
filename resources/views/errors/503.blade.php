{{-- Error 503. Ver errors/layout (sin sesión ni base de datos; texto fijo, nunca el mensaje de la excepción). --}}
@extends('errors.layout')
@section('codigo', '503')
@section('titulo', 'Estamos en mantenimiento')
@section('texto', 'Volvemos enseguida. Gracias por la paciencia.')
