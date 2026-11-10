{{--
    «Copias de seguridad» (Registros, solo el administrador). Cuándo tocarla: al cambiar la pantalla.
    Diseño de referencia: la de asesoria (aviso si no se guarda nada, «Hacer una copia ahora», lista de copias),
    con la paleta de tickets. Sin mockup a petición de la usuaria (2026-11-10).
    Trampas: no hay descarga desde la web a propósito (la copia lleva datos de todos los clientes); y NO hay copia
    automática programada todavía (pendiente de decidir: horario y dónde se guarda fuera del servidor).
    Variables: $copies, $usable, $queued.
--}}
@extends('layouts.panel')
@section('titulo', 'Copias de seguridad')

@section('contenido')
    <p class="migas">Registros › Copias de seguridad</p>
    <div class="titulo-fila">
        <h1>Copias de seguridad</h1>
    </div>

    @if (! $usable)
        <div class="alerta error" role="alert">
            <strong>No se puede escribir en la carpeta de copias.</strong>
            Mientras tanto no se está guardando ninguna: si la base de datos se perdiera, no habría nada que restaurar.
            Casi siempre es un problema de permisos de la carpeta de copias.
        </div>
    @endif

    @if ($queued > 0)
        <div class="alerta ok" role="status">
            Hay {{ $queued }} {{ $queued === 1 ? 'trabajo esperando' : 'trabajos esperando' }} en la cola. La copia a mano no se hará
            si el proceso de la cola no está en marcha (<code>composer dev</code> lo levanta).
        </div>
    @endif

    <section class="tarjeta">
        <h2>Hacer una copia ahora</h2>
        <form action="{{ route('records.backups.store') }}" method="post" class="fila-form">
            @csrf
            <button type="submit" class="boton principal">Crear copia</button>
        </form>
        <p class="ayuda">Copia la base de datos. Puede tardar varios minutos y puedes seguir usando la aplicación mientras se hace.</p>
    </section>

    <div class="tabla-caja separada">
        <table>
            <caption>{{ count($copies) }} {{ count($copies) === 1 ? 'copia guardada' : 'copias guardadas' }}</caption>
            <thead>
                <tr>
                    <th scope="col">Fichero</th>
                    <th scope="col">Fecha</th>
                    <th scope="col">Tamaño</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($copies as $copy)
                    <tr>
                        <td class="asunto-celda" data-etiqueta="Fichero"><strong>{{ $copy['name'] }}</strong></td>
                        <td class="num" data-etiqueta="Fecha">{{ $copy['date']->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}</td>
                        <td class="num" data-etiqueta="Tamaño">{{ $copy['size'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Todavía no hay ninguna copia guardada. Puedes hacer una ahora.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
