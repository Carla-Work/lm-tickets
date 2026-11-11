{{--
    «Nuevo ticket». Mockup validado: nuevo-ticket-a-departamentos-tarjetas.html.
    Cuándo tocarlo: al cambiar el formulario (y entonces también StoreTicketRequest).
    Trampas:
     · Sin JavaScript todas las categorías se ven agrupadas por departamento (como en el mockup); el servidor
       valida que la categoría elegida sea del departamento elegido (R6/StoreTicketRequest).
     · Ticket relacionado (mockup nuevo-ticket-b-relacionado): `?relacionado={id}` rellena departamento, tipo y
       producto con los del ticket original y manda `related_ticket_id` oculto. El servidor NO se fía de él:
       StoreTicketRequest vuelve a comprobar que se puede (R15/R16).
    Variables: $categories (agrupadas por valor de departamento), $products (los de la empresa, activos),
       $related (ticket original o null), $defaults (valores por defecto del original, ya validados).
--}}
@extends('layouts.panel')
@section('titulo', 'Nuevo ticket')

@section('contenido')
    @php $selectedDepartment = old('department', $defaults['department'] ?? 'support'); @endphp
    <p class="migas">
        <a href="{{ route('tickets.index') }}">Mis tickets</a> ›
        @if ($related)<a href="{{ route('tickets.show', $related) }}">#{{ $related->number }}</a> ›@endif
        Nuevo ticket
    </p>
    <h1>Nuevo ticket</h1>
    <p class="ayuda" style="font-size:1rem">Cuéntanos qué te ocurre y lo atenderá el departamento que elijas.</p>

    @if ($related)
        <p class="aviso" style="margin:1rem 0 0"><strong>Este ticket quedará relacionado con el <a href="{{ route('tickets.show', $related) }}">#{{ $related->number }} · {{ $related->subject }}</a>,</strong> que está {{ mb_strtolower($related->status->customerLabel()) }} y no se puede reabrir. Hemos rellenado el departamento, el tipo y el producto con los de ese ticket; cámbialos si el problema es distinto.</p>
    @endif

    @if ($errors->any())
        <div class="alerta error" role="alert">Revisa los campos marcados: {{ $errors->count() }} {{ $errors->count() === 1 ? 'error' : 'errores' }}.</div>
    @endif

    <form class="dos-columnas" action="{{ route('tickets.store') }}" method="post" enctype="multipart/form-data">
        @csrf
        @if ($related)<input type="hidden" name="related_ticket_id" value="{{ $related->id }}">@endif
        @error('related_ticket_id')<div class="alerta error" role="alert">{{ $message }}</div>@enderror
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
                @error('department')<p class="error-campo" id="err-department" role="alert">{{ $message }}</p>@enderror
            </fieldset>

            <div class="campo">
                <label for="category_id">Tipo de incidencia <span class="obligatorio" aria-hidden="true">*</span></label>
                <select id="category_id" name="category_id" @errorAttrs('category_id') required>
                    <option value="" disabled @selected(! old('category_id', $defaults['category_id'] ?? null))>Elige uno</option>
                    @foreach (\App\Enums\Department::cases() as $department)
                        <optgroup label="{{ $department->label() }}">
                            @foreach ($categories->get($department->value, collect()) as $category)
                                <option value="{{ $category->id }}" @selected((int) old('category_id', $defaults['category_id'] ?? 0) === $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p class="ayuda">Elige un tipo del departamento que has marcado.</p>
                @error('category_id')<p class="error-campo" id="err-category_id" role="alert">{{ $message }}</p>@enderror
            </div>

            @if ($products->isNotEmpty())
                <div class="campo">
                    <label for="product_id">Producto o servicio afectado <span class="obligatorio" aria-hidden="true">*</span></label>
                    <select id="product_id" name="product_id" @errorAttrs('product_id') required>
                        <option value="" disabled @selected(! old('product_id', $defaults['product_id'] ?? null))>Elige uno</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((int) old('product_id', $defaults['product_id'] ?? 0) === $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                    <p class="ayuda">Solo aparecen los productos contratados por tu empresa.</p>
                    @error('product_id')<p class="error-campo" id="err-product_id" role="alert">{{ $message }}</p>@enderror
                </div>
            @endif

            <div class="campo">
                <label for="subject">Asunto <span class="obligatorio" aria-hidden="true">*</span></label>
                <input type="text" id="subject" name="subject" @errorAttrs('subject') required maxlength="200" value="{{ old('subject') }}">
                @error('subject')<p class="error-campo" id="err-subject" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="campo">
                <label for="description">Descripción <span class="obligatorio" aria-hidden="true">*</span></label>
                <textarea id="description" name="description" @errorAttrs('description') required maxlength="10000">{{ old('description') }}</textarea>
                <p class="ayuda">Cuanto más detalle (qué pulsaste, qué mensaje salió), antes lo resolvemos.</p>
                @error('description')<p class="error-campo" id="err-description" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="campo">
                <label for="adjuntos">Adjuntos (opcional)</label>
                <input type="file" id="adjuntos" name="adjuntos[]" multiple aria-label="Adjuntar archivos">
                <p class="ayuda" style="margin-top:.3rem">Capturas, PDF, hojas de cálculo o ZIP. Hasta {{ config('access.attachments.max_files') }} archivos de {{ intdiv(config('access.attachments.max_kb'), 1024) }} MB cada uno.</p>
                @error('adjuntos')<p class="error-campo" id="err-adjuntos" role="alert">{{ $message }}</p>@enderror
                @foreach ($errors->get('adjuntos.*') as $messages)@foreach ($messages as $message)<p class="error-campo" role="alert">{{ $message }}</p>@endforeach @endforeach
            </div>

            <div class="acciones">
                <button type="submit" class="boton principal">Enviar ticket</button>
                <a class="boton secundario" href="{{ route('tickets.index') }}">Cancelar</a>
            </div>
        </div>

        <aside class="tarjeta lateral" aria-label="Información">
            @if ($related)
                <h2>Ticket relacionado</h2>
                <ul class="productos" style="margin-bottom:1rem">
                    <li><a href="{{ route('tickets.show', $related) }}">#{{ $related->number }}</a></li>
                    <li>{{ $related->status->customerLabel() }} {{ $related->resolved_at?->timezone(config('app.timezone'))->translatedFormat('j M, H:i') }}</li>
                </ul>
            @endif
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
