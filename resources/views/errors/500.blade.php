{{-- Error 500. Ver errors/layout (sin sesión ni base de datos; texto fijo, nunca el mensaje de la excepción). --}}
@extends('errors.layout')
@section('codigo', '500')
@section('titulo', 'Algo ha ido mal por nuestra parte')
@section('texto', 'Ya queda anotado. Inténtalo de nuevo en unos minutos.')
