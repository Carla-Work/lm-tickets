{{--
    «Empresas» (Administración, solo el administrador). Mockup validado: admin-empresas-a-lista.html.
    Cuándo tocarla: al cambiar la lista. Variables: $companies (paginado, con products y recuentos), $total, $filters, $products.
    Estado: «De baja» manda; si no, «Invitación pendiente» = nadie de la empresa ha aceptado aún su invitación.
--}}
@extends('layouts.panel')
@section('titulo', 'Empresas')

@section('contenido')
    <div class="titulo-fila">
        <div><h1>Empresas</h1><p class="sub">Empresas cliente, sus productos contratados y sus usuarios.</p></div>
        <a class="boton principal" href="{{ route('admin.companies.create') }}">+ Nueva empresa</a>
    </div>

    <form class="filtros" action="{{ route('admin.companies.index') }}" method="get" aria-label="Filtrar empresas">
        <div class="buscar"><label for="q">Buscar</label><input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Nombre o CIF"></div>
        <div>
            <label for="f-est">Estado</label>
            <select id="f-est" name="estado">
                <option value="">Todas</option>
                @foreach (['activa' => 'Activa', 'pendiente' => 'Invitación pendiente', 'baja' => 'De baja'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['estado'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-prod">Producto</label>
            <select id="f-prod" name="producto">
                <option value="">Todos</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}" @selected((string) ($filters['producto'] ?? '') === (string) $product->id)>{{ $product->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="boton secundario">Filtrar</button>
        <a href="{{ route('admin.companies.index') }}" class="limpiar">Limpiar</a>
    </form>

    <div class="tabla-caja">
        <table>
            <caption>{{ $companies->total() }} de {{ $total }} {{ $total === 1 ? 'empresa' : 'empresas' }}</caption>
            <thead>
                <tr>
                    <th scope="col">Empresa</th><th scope="col">Usuarios</th><th scope="col">Productos</th>
                    <th scope="col">Tickets abiertos</th><th scope="col">Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($companies as $company)
                    <tr>
                        <td class="asunto-celda" data-etiqueta="Empresa">
                            <a class="asunto" href="{{ route('admin.companies.show', $company) }}">{{ $company->name }}</a>
                            <span class="secundario">{{ $company->tax_id }}</span>
                        </td>
                        <td data-etiqueta="Usuarios">{{ $company->users_count }}</td>
                        <td data-etiqueta="Productos">
                            @forelse ($company->products as $product)<span class="chip">{{ $product->name }}</span>@empty<span class="chip sin">Sin productos</span>@endforelse
                        </td>
                        <td data-etiqueta="Tickets abiertos">{{ $company->open_tickets_count }}</td>
                        <td data-etiqueta="Estado">
                            @if (! $company->isActive())
                                <span class="estado-cuenta">○ De baja</span>
                            @elseif ($company->activated_users_count === 0)
                                <span class="estado-cuenta">✉ Invitación pendiente</span>
                            @else
                                <span class="estado-cuenta">✓ Activa</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">No hay empresas que mostrar.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('records._pages', ['paginator' => $companies])
@endsection
