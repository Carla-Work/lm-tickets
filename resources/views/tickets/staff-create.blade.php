{{--
    «Nuevo ticket para un cliente», paso 2: el formulario. Mockups validados: ticket-nuevo-interno-b-agente.html y
    ticket-nuevo-interno-c-administrador.html (la diferencia: el administrador ve todos los departamentos y DEBE elegir agente).
    Cuándo tocarlo: al cambiar el formulario (y entonces también StoreStaffTicketRequest).
    Trampas:
     · Canal y «creado por» son INTERNOS: el cliente no los ve en ninguna pantalla ni correo.
     · La casilla de aviso al cliente sale SIN marcar (decidido 2026-11-12): no se manda nada que nadie haya decidido.
     · Sin JavaScript, «Asignar a» no se filtra por departamento: lo valida el servidor (la persona debe atender ese departamento).
    Variables: $actor, $customer, $departments (los que atiende quien crea), $categories (agrupadas por valor de departamento),
       $products (los de la empresa del cliente), $channels, $agents (agentes en activo agrupados por equipo).
--}}
@extends('layouts.panel')
@section('titulo', 'Nuevo ticket para un cliente')

@section('contenido')
    @php
        $isAdmin = $actor->role === \App\Enums\UserRole::Admin;
        $selectedDepartment = old('department', $departments[0]->value ?? '');
    @endphp
    <p class="migas"><a href="{{ route('inbox') }}">Bandeja</a> › <a href="{{ route('tickets.staff.create') }}">Nuevo ticket</a> › Datos</p>
    <h1>Nuevo ticket para un cliente</h1>
    <p class="ayuda" style="font-size:1rem">{{ $isAdmin ? 'Paso 2 de 2. Como administrador puedes abrirlo en cualquier departamento.' : 'Paso 2 de 2. Rellena la ficha; tú decides si el cliente recibe un aviso.' }}</p>
    <div class="para"><span>Ticket a nombre de <strong>{{ $customer->name }}</strong> · {{ $customer->company?->name }}</span><a href="{{ route('tickets.staff.create') }}">Cambiar</a></div>

    @if ($errors->any())
        <div class="alerta error" role="alert">Revisa los campos marcados: {{ $errors->count() }} {{ $errors->count() === 1 ? 'error' : 'errores' }}.</div>
    @endif

    <form class="dos-columnas" action="{{ route('tickets.staff.store') }}" method="post" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="solicitante" value="{{ $customer->id }}">
        @error('solicitante')<div class="alerta error" role="alert">{{ $message }}</div>@enderror
        <div class="tarjeta">
            <fieldset>
                <legend>Canal por el que ha llegado <span class="obligatorio" aria-hidden="true">*</span><span class="marca-interna">interno</span></legend>
                <div class="canales">
                    @foreach ($channels as $channel)
                        <label class="canal"><input type="radio" name="canal" value="{{ $channel->value }}" required @checked(old('canal', 'phone') === $channel->value)><span>{{ $channel->label() }}</span></label>
                    @endforeach
                </div>
                <p class="ayuda">Solo lo ve el equipo. Sirve para los informes.</p>
                @error('canal')<p class="error-campo" id="err-canal" role="alert">{{ $message }}</p>@enderror
            </fieldset>

            <fieldset>
                <legend>Departamento <span class="obligatorio" aria-hidden="true">*</span></legend>
                <div class="departamentos">
                    @foreach ($departments as $department)
                        <label class="dep-opcion {{ $department->cssClass() }}">
                            <input type="radio" name="department" value="{{ $department->value }}" required @checked($selectedDepartment === $department->value)>
                            <span class="caja"><strong>{{ $department->label() }}</strong><small>{{ match ($department) {
                                \App\Enums\Department::Support => 'Equipos, correo, copias y seguridad',
                                \App\Enums\Department::WebApps => 'Programas de gestión y desarrollos a medida',
                                \App\Enums\Department::Websites => 'Web, dominio y hosting',
                                \App\Enums\Department::Other => 'Presupuestos, facturas y contratos',
                            } }}</small></span>
                        </label>
                    @endforeach
                </div>
                @error('department')<p class="error-campo" id="err-department" role="alert">{{ $message }}</p>@enderror
            </fieldset>

            <div class="campo">
                <label for="category_id">Tipo de incidencia <span class="obligatorio" aria-hidden="true">*</span></label>
                <select id="category_id" name="category_id" @errorAttrs('category_id') required>
                    <option value="" disabled @selected(! old('category_id'))>Elige uno</option>
                    @foreach ($departments as $department)
                        <optgroup label="{{ $department->label() }}">
                            @foreach ($categories->get($department->value, collect()) as $category)
                                <option value="{{ $category->id }}" @selected((int) old('category_id', 0) === $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p class="ayuda">El departamento sale del tipo que elijas; si no coinciden, se rechaza al enviar.</p>
                @error('category_id')<p class="error-campo" id="err-category_id" role="alert">{{ $message }}</p>@enderror
            </div>

            @if ($products->isNotEmpty())
                <div class="campo">
                    <label for="product_id">Producto o servicio afectado <span class="obligatorio" aria-hidden="true">*</span></label>
                    <select id="product_id" name="product_id" @errorAttrs('product_id') required>
                        <option value="" disabled @selected(! old('product_id'))>Elige uno</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((int) old('product_id', 0) === $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                    <p class="ayuda">Solo salen los productos que tiene contratados {{ $customer->company?->name }}</p>
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
                <p class="ayuda">Escríbelo como si fuera el ticket del cliente: él lo verá con este texto.</p>
                @error('description')<p class="error-campo" id="err-description" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="campo">
                <label for="adjuntos">Adjuntos (opcional)</label>
                <input type="file" id="adjuntos" name="adjuntos[]" multiple aria-label="Adjuntar archivos">
                <p class="ayuda" style="margin-top:.3rem">Capturas, imágenes o PDF. Hasta {{ config('access.attachments.max_files') }} archivos de {{ intdiv(config('access.attachments.max_kb'), 1024) }} MB cada uno.</p>
                @error('adjuntos')<p class="error-campo" id="err-adjuntos" role="alert">{{ $message }}</p>@enderror
                @foreach ($errors->get('adjuntos.*') as $messages)@foreach ($messages as $message)<p class="error-campo" role="alert">{{ $message }}</p>@endforeach @endforeach
            </div>

            <div class="campo">
                <label for="asignado">Asignar a <span class="obligatorio" aria-hidden="true">*</span></label>
                <select id="asignado" name="asignado" @errorAttrs('asignado') required>
                    @if ($isAdmin)
                        <option value="" disabled @selected(! old('asignado'))>Elige un agente</option>
                    @endif
                    @foreach ($agents as $team => $people)
                        <optgroup label="{{ $team }}">
                            @foreach ($people as $person)
                                <option value="{{ $person->id }}" @selected((int) old('asignado', $isAdmin ? 0 : $actor->id) === $person->id)>{{ $person->is($actor) ? 'Yo ('.$person->name.')' : $person->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p class="ayuda">{{ $isAdmin ? 'El administrador no atiende tickets: hay que asignarlo a quien pueda hacerlo. ' : 'Por defecto, tú. ' }}Debe poder atender el departamento elegido, o se rechaza al enviar. A quien se asigne se le avisa.</p>
                @error('asignado')<p class="error-campo" id="err-asignado" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="campo">
                <div class="casilla-aviso">
                    <input type="checkbox" id="notificar" name="notificar" value="1" @checked(old('notificar'))>
                    <div>
                        <label for="notificar">Enviar aviso a {{ $customer->name }}</label>
                        <p class="ayuda" style="margin:0">Le llega «Se ha abierto un ticket a tu nombre» (por correo y en su panel). Sin marcar, no se le envía nada.</p>
                    </div>
                </div>
            </div>

            <div class="acciones">
                <button type="submit" class="boton principal">Crear ticket</button>
                <a class="boton secundario" href="{{ route('inbox') }}">Cancelar</a>
            </div>
        </div>

        <aside class="tarjeta lateral" aria-label="Información">
            <h2>¿Qué pasa después?</h2>
            <ol>
                <li>Nace <strong>Abierto</strong> y asignado a la persona que elijas.</li>
                <li>Queda registrado en el historial que lo creaste tú (solo lo ve el equipo).</li>
                <li>El cliente lo ve en su panel como un ticket suyo. Si marcas la casilla, también le llega un aviso.</li>
            </ol>
            @if ($products->isNotEmpty())
                <h2>Contratado por la empresa</h2>
                <ul class="productos">
                    @foreach ($products as $product)<li>{{ $product->name }}</li>@endforeach
                </ul>
            @endif
            <p class="aviso interna">{{ $isAdmin ? 'Como administrador creas y asignas el ticket, pero no lo atiendes ni cambias su estado.' : 'No ves Contabilidad: ese departamento solo lo gestiona su equipo.' }}</p>
            <p class="aviso">Solo el cliente, la gente de su empresa con permiso y el equipo que atiende ese departamento ven el ticket.</p>
        </aside>
    </form>
@endsection
