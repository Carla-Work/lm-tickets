{{--
    «Nuevo ticket». Mockup validado: nuevo-ticket-a-departamentos-tarjetas.html.
    Cuándo tocarlo: al cambiar el formulario (y entonces también StoreTicketRequest).
    Trampas:
     · Sin JavaScript todas las categorías se ven agrupadas por departamento (como en el mockup); el servidor
       valida que la categoría elegida sea del departamento elegido (R6/StoreTicketRequest).
     · Adjuntos: llegan en la entrega 4d; el campo aún no está.
    Variables: $categories (agrupadas por valor de departamento), $products (los de la empresa, activos).
--}}
@extends('layouts.panel')
@section('titulo', 'Nuevo ticket')

@section('contenido')
    @php $selectedDepartment = old('department', 'support'); @endphp
    <p class="migas"><a href="{{ route('tickets.index') }}">Mis tickets</a> › Nuevo ticket</p>
    <h1>Nuevo ticket</h1>
    <p class="ayuda" style="font-size:1rem">Cuéntanos qué te ocurre y lo atenderá el departamento que elijas.</p>

    @if ($errors->any())
        <div class="alerta error" role="alert">Revisa los campos marcados: {{ $errors->count() }} {{ $errors->count() === 1 ? 'error' : 'errores' }}.</div>
    @endif

    <form class="dos-columnas" action="{{ route('tickets.store') }}" method="post">
        @csrf
        <div class="tarjeta">
            <fieldset>
                <legend>Departamento <span class="obligatorio" aria-hidden="true">*</span></legend>
                <div class="departamentos">
                    @foreach ([
                        \App\Enums\Department::Support->value => 'Equipos, correo, copias y seguridad',
                        \App\Enums\Department::WebApps->value => 'Programas de gestión y desarrollos a medida',
                        \App\Enums\Department::Websites->value => 'Tu web, dominio y hosting',
                        \App\Enums\Department::Other->value => 'Presupuestos, facturas y contratos',
                    ] as $value => $description)
                        @php $department = \App\Enums\Department::from($value); @endphp
                        <label class="dep-opcion {{ $department->cssClass() }}">
                            <input type="radio" name="department" value="{{ $value }}" required @checked($selectedDepartment === $value)>
                            <span class="caja"><strong>{{ $department->label() }}</strong><small>{{ $description }}</small></span>
                        </label>
                    @endforeach
                </div>
                @error('department')<p class="error-campo" role="alert">{{ $message }}</p>@enderror
            </fieldset>

            <div class="campo">
                <label for="category_id">Tipo de incidencia <span class="obligatorio" aria-hidden="true">*</span></label>
                <select id="category_id" name="category_id" required>
                    <option value="" disabled @selected(! old('category_id'))>Elige uno</option>
                    @foreach (\App\Enums\Department::cases() as $department)
                        <optgroup label="{{ $department->label() }}">
                            @foreach ($categories->get($department->value, collect()) as $category)
                                <option value="{{ $category->id }}" @selected((int) old('category_id') === $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p class="ayuda">Elige un tipo del departamento que has marcado.</p>
                @error('category_id')<p class="error-campo" role="alert">{{ $message }}</p>@enderror
            </div>

            @if ($products->isNotEmpty())
                <div class="campo">
                    <label for="product_id">Producto o servicio afectado <span class="obligatorio" aria-hidden="true">*</span></label>
                    <select id="product_id" name="product_id" required>
                        <option value="" disabled @selected(! old('product_id'))>Elige uno</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((int) old('product_id') === $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                    <p class="ayuda">Solo aparecen los productos contratados por tu empresa.</p>
                    @error('product_id')<p class="error-campo" role="alert">{{ $message }}</p>@enderror
                </div>
            @endif

            <div class="campo">
                <label for="subject">Asunto <span class="obligatorio" aria-hidden="true">*</span></label>
                <input type="text" id="subject" name="subject" required maxlength="200" value="{{ old('subject') }}">
                @error('subject')<p class="error-campo" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="campo">
                <label for="description">Descripción <span class="obligatorio" aria-hidden="true">*</span></label>
                <textarea id="description" name="description" required maxlength="10000">{{ old('description') }}</textarea>
                <p class="ayuda">Cuanto más detalle (qué pulsaste, qué mensaje salió), antes lo resolvemos.</p>
                @error('description')<p class="error-campo" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="acciones">
                <button type="submit" class="boton principal">Enviar ticket</button>
                <a class="boton secundario" href="{{ route('tickets.index') }}">Cancelar</a>
            </div>
        </div>

        <aside class="tarjeta lateral" aria-label="Información">
            <h2>¿Qué pasa después?</h2>
            <ol>
                <li>Recibes un número de ticket, por ejemplo <strong>#{{ now()->format('Y') }}-0318</strong>.</li>
                <li>Un técnico del departamento lo recoge.</li>
                <li>Te avisamos por correo y en el panel cuando haya respuesta.</li>
            </ol>
            @if ($products->isNotEmpty())
                <h2>Tu empresa tiene contratado</h2>
                <ul class="productos">
                    @foreach ($products as $product)<li>{{ $product->name }}</li>@endforeach
                </ul>
            @endif
            <p class="aviso">Solo tú y el equipo de Ling Marco veis este ticket. Los administradores de tu empresa también.</p>
        </aside>
    </form>
@endsection
