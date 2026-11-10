{{--
    Editar un producto o una categoría (Administración › Productos y categorías).
    El mockup dibuja el enlace «Editar» pero no esta pantalla: es una página mínima con la misma tarjeta y campos
    que el alta (pendiente de que la usuaria la confirme). Cuándo tocarla: al cambiar qué se edita.
    Variables: $kind ('product' | 'category'), $item.
--}}
@extends('layouts.panel')
@section('titulo', 'Editar '.($kind === 'product' ? 'producto' : 'categoría'))

@section('contenido')
    @php $isProduct = $kind === 'product'; @endphp
    <p class="migas"><a href="{{ route('admin.catalog.index') }}">Productos y categorías</a> › {{ $item->name }}</p>
    <h1>Editar {{ $isProduct ? 'producto' : 'categoría' }}</h1>

    <form class="tarjeta" style="max-width:560px;margin-top:1rem" method="post"
          action="{{ $isProduct ? route('admin.catalog.products.update', $item) : route('admin.catalog.categories.update', $item) }}">
        @csrf
        <div class="campo"><label for="name">Nombre <span class="oblig" aria-hidden="true">*</span></label><input type="text" id="name" name="name" value="{{ old('name', $item->name) }}" required maxlength="{{ $isProduct ? 255 : 100 }}">@error('name')<p class="error-campo" role="alert">{{ $message }}</p>@enderror</div>
        @if ($isProduct)
            <div class="campo"><label for="description">Descripción corta</label><input type="text" id="description" name="description" value="{{ old('description', $item->description) }}" maxlength="200">@error('description')<p class="error-campo" role="alert">{{ $message }}</p>@enderror</div>
        @else
            <p class="ayuda">Departamento: <span class="dep {{ $item->department->cssClass() }}">{{ $item->department->label() }}</span> (no se puede cambiar: los tickets que la usan quedarían incoherentes).</p>
        @endif
        <div class="acciones">
            <button type="submit" class="boton principal">Guardar</button>
            <a class="boton secundario" href="{{ route('admin.catalog.index') }}">Cancelar</a>
        </div>
    </form>
@endsection
