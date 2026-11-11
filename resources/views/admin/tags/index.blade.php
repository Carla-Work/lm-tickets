{{--
    «Etiquetas» (Administración). Mockup validado: admin-catalogo-b-etiquetas.html.
    Cuándo tocarla: al cambiar el catálogo de etiquetas en pantalla. Variables: $tags (con tickets_count).
    Trampas: no hay botón de borrar (una etiqueta en uso solo se desactiva); son INTERNAS, el cliente nunca las ve.
    «Renombrar» es un <details> con su formulario: funciona sin JavaScript.
--}}
@extends('layouts.panel')
@section('titulo', 'Etiquetas')

@section('contenido')
    <p class="migas">Administración › Etiquetas</p>
    <div class="titulo-fila"><div><h1>Etiquetas</h1><p class="sub">Marcas internas para organizar los tickets. Solo las ve el equipo de Ling Marco, nunca el cliente.</p></div></div>

    @if ($errors->any())
        <div class="alerta error" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="dos">
        <section aria-label="Etiquetas">
            <div class="tabla-caja">
                <table class="fichas">
                    <caption>Etiquetas</caption>
                    <thead><tr><th scope="col">Etiqueta</th><th scope="col">Tickets</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
                    <tbody>
                        @forelse ($tags as $tag)
                            <tr>
                                <td class="asunto-celda" data-etiqueta="Etiqueta">
                                    <span class="etq">{{ $tag->name }}</span>
                                    @unless ($tag->active)<span class="secundario">Ya no se ofrece; los {{ $tag->tickets_count }} tickets que la llevan la conservan</span>@endunless
                                </td>
                                <td data-etiqueta="Tickets">{{ $tag->tickets_count }}</td>
                                <td data-etiqueta="Estado"><span class="estado-cuenta">{{ $tag->active ? '✓ Activa' : '○ Desactivada' }}</span></td>
                                <td data-etiqueta="Acciones">
                                    <div class="acc">
                                        <details>
                                            <summary class="boton secundario pequeno">Renombrar</summary>
                                            <form action="{{ route('admin.tags.update', $tag) }}" method="post" style="display:flex;gap:.4rem;margin-top:.4rem">@csrf
                                                <input type="text" name="name" value="{{ $tag->name }}" maxlength="{{ \App\Models\Tag::NAME_MAX }}" required aria-label="Nuevo nombre de la etiqueta {{ $tag->name }}">
                                                <button type="submit" class="boton principal pequeno">Guardar</button>
                                            </form>
                                        </details>
                                        <form action="{{ route('admin.tags.status', $tag) }}" method="post">@csrf
                                            <input type="hidden" name="activo" value="{{ $tag->active ? 0 : 1 }}">
                                            <button type="submit" class="boton secundario pequeno">{{ $tag->active ? 'Desactivar' : 'Reactivar' }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td>Todavía no hay etiquetas.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <aside>
            <form class="tarjeta" action="{{ route('admin.tags.store') }}" method="post" aria-label="Nueva etiqueta">@csrf
                <h2>Nueva etiqueta</h2>
                <div class="campo"><label for="ne-nombre">Nombre <span class="oblig" aria-hidden="true">*</span></label><input type="text" id="ne-nombre" name="name" maxlength="{{ \App\Models\Tag::NAME_MAX }}" required><p class="ayuda">Corto y claro (hasta {{ \App\Models\Tag::NAME_MAX }} caracteres). No puede repetirse.</p></div>
                <button type="submit" class="boton principal">Crear etiqueta</button>
            </form>
            <div class="tarjeta"><h2>Prioridades</h2>
                <p class="ayuda" style="margin:0 0 .5rem">Son fijas, no se editan: @foreach (\App\Enums\Priority::cases() as $priority)<span class="prio {{ $priority->cssClass() }}">{{ $priority->badge() }}</span> @endforeach</p>
                <p class="ayuda" style="margin:0">Todo ticket nuevo entra como «Normal». Las cambia el equipo; el cliente no las elige ni las ve.</p>
            </div>
        </aside>
    </div>
@endsection
