{{-- Error 403. Ver errors/layout (sin sesión ni base de datos; texto fijo, nunca el mensaje de la excepción). --}}
@extends('errors.layout')
@section('codigo', '403')
@section('titulo', 'No tienes acceso a esta página')
@section('texto', 'Si crees que deberías tenerlo, avisa a quien administra tu cuenta.')
