{{--
    «Productos y categorías» (Administración). Mockup validado: admin-catalogo-a-productos-categorias.html.
    Cuándo tocarla: al cambiar el catálogo en pantalla. Variables: $products (con companies_count), $categories (por departamento).
    Trampa: no hay botón de borrar (R19); «Desactivar» sustituye al borrado.
--}}
@extends('layouts.panel')
@section('titulo', 'Productos y categorías')

@section('contenido')
    <div class="titulo-fila"><div><h1>Productos y categorías</h1><p class="sub">Lo que el cliente elige al abrir un ticket. Lo editas tú.</p></div></div>

    @if ($errors->any())
        <div class="alerta error" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="dos">
        <section aria-label="Productos">
            <div class="tabla-caja">
                <table class="fichas">
                    <caption>Productos y servicios</caption>
                    <thead><tr><th scope="col">Producto</th><th scope="col">Empresas</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
                    <tbody>
                        @forelse ($products as $product)
                            <tr>
                                <td class="asunto-celda" data-etiqueta="Producto"><strong>{{ $product->name }}</strong><span class="secundario">{{ $product->description }}</span></td>
                                <td data-etiqueta="Empresas">{{ $product->companies_count }}</td>
                                <td data-etiqueta="Estado"><span class="estado-cuenta">{{ $product->active ? '✓ Activo' : '○ Desactivado' }}</span></td>
                                <td data-etiqueta="Acciones">
                                    <div class="acc">
                                        <a class="boton secundario pequeno" href="{{ route('admin.catalog.products.edit', $product) }}">Editar</a>
                                        <form action="{{ route('admin.catalog.products.status', $product) }}" method="post">@csrf
                                            <input type="hidden" name="activo" value="{{ $product->active ? 0 : 1 }}">
                                            <button type="submit" class="boton secundario pequeno">{{ $product->active ? 'Desactivar' : 'Activar' }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td>Todavía no hay productos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <aside>
            <form class="tarjeta" action="{{ route('admin.catalog.products.store') }}" method="post" aria-label="Nuevo producto">
                @csrf
                <h2>Nuevo producto</h2>
                <div class="campo"><label for="pn">Nombre <span class="oblig" aria-hidden="true">*</span></label><input type="text" id="pn" name="name" value="{{ old('name') }}" required maxlength="255"></div>
                <div class="campo"><label for="pd">Descripción corta</label><input type="text" id="pd" name="description" value="{{ old('description') }}" maxlength="200"></div>
                <button type="submit" class="boton principal">Añadir al catálogo</button>
                <p class="ayuda" style="font-size:.85rem;color:var(--texto-suave);margin:.8rem 0 0">Un producto asignado a empresas no se puede borrar, solo desactivar (R19).</p>
            </form>
        </aside>
    </div>

    <h2 style="margin:2rem 0 .3rem">Categorías por departamento</h2>
    <p class="sub" style="color:var(--texto-suave);margin:0 0 1rem">Los cuatro departamentos son fijos. Lo que editas son las categorías de cada uno.</p>
    <div class="cols2" style="gap:1.25rem">
        @foreach (\App\Enums\Department::cases() as $department)
            <div class="tarjeta">
                <h2><span class="dep {{ $department->cssClass() }}">{{ $department->label() }}</span></h2>
                <ul style="list-style:none;margin:0 0 .8rem;padding:0">
                    @forelse ($categories->get($department->value, collect()) as $category)
                        <li style="display:flex;justify-content:space-between;gap:.5rem;padding:.35rem 0;border-bottom:1px solid var(--gris)">
                            <span>{{ $category->name }}@unless ($category->active) <small>(desactivada)</small>@endunless</span>
                            <a href="{{ route('admin.catalog.categories.edit', $category) }}">Editar</a>
                        </li>
                    @empty
                        <li style="padding:.35rem 0;color:var(--texto-suave)">Sin categorías.</li>
                    @endforelse
                </ul>
                <form action="{{ route('admin.catalog.categories.store') }}" method="post" class="campo" style="margin:0">
                    @csrf
                    <input type="hidden" name="department" value="{{ $department->value }}">
                    <label for="nc-{{ $department->value }}" style="font-weight:400;font-size:.85rem">Añadir categoría</label>
                    <div style="display:flex;gap:.5rem">
                        <input type="text" id="nc-{{ $department->value }}" name="name" maxlength="100" required>
                        <button class="boton secundario pequeno" type="submit">Añadir</button>
                    </div>
                </form>
            </div>
        @endforeach
    </div>
@endsection
