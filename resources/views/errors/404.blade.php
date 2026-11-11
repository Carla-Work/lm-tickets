{{-- Error 404. Ver errors/layout (sin sesión ni base de datos; texto fijo, nunca el mensaje de la excepción). --}}
@extends('errors.layout')
@section('codigo', '404')
@section('titulo', 'No encontramos esa página')
@section('texto', 'Puede que el enlace esté mal escrito o que el contenido ya no exista.')
