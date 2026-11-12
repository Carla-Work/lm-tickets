{{--
    «Nuevo ticket para un cliente», paso 1: ¿para quién? Mockup validado: ticket-nuevo-interno-a-para-quien.html.
    Cuándo tocarlo: al cambiar el paso 1 (y entonces también StaffTicketController::create).
    Trampa: es un formulario GET a propósito (sin JavaScript el paso 2 necesita saber la empresa para ofrecer solo sus
    productos). El servidor vuelve a comprobar que la persona es válida; esto solo ofrece las activas.
    Variable: $customers (usuarios activos de empresas activas, agrupados por nombre de empresa).
--}}
@extends('layouts.panel')
@section('titulo', 'Nuevo ticket para un cliente')

@section('contenido')
    <p class="migas"><a href="{{ route('inbox') }}">Bandeja</a> › Nuevo ticket</p>
    <h1>Nuevo ticket para un cliente</h1>
    <p class="ayuda" style="font-size:1rem">Paso 1 de 2. Elige a la persona que lo ha pedido: el ticket quedará a su nombre y de su empresa.</p>

    <form class="dos-columnas" action="{{ route('tickets.staff.create') }}" method="get">
        <div class="tarjeta">
            <div class="campo">
                <label for="solicitante">Persona de la empresa cliente <span class="obligatorio" aria-hidden="true">*</span></label>
                <select id="solicitante" name="solicitante" @errorAttrs('solicitante') required>
                    <option value="" selected disabled>Elige una persona</option>
                    @foreach ($customers as $company => $people)
                        <optgroup label="{{ $company }}">
                            @foreach ($people as $person)
                                <option value="{{ $person->id }}">{{ $person->name }} · {{ $person->role === \App\Enums\UserRole::CompanyAdmin ? 'administrador de empresa' : 'usuario' }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p class="ayuda">Solo salen empresas y personas activas. Si la persona no tiene cuenta, pídele al administrador que la invite.</p>
                @error('solicitante')<p class="error-campo" id="err-solicitante" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="acciones">
                <button type="submit" class="boton principal">Continuar</button>
                <a class="boton secundario" href="{{ route('inbox') }}">Cancelar</a>
            </div>
        </div>

        <aside class="tarjeta lateral" aria-label="Información">
            <h2>¿Cuándo usar esto?</h2>
            <p>Cuando un cliente te llama, viene a la oficina o te escribe por otra vía y quieres dejar la incidencia registrada.</p>
            <p class="aviso">Si el cliente puede abrirlo él mismo, es mejor que lo haga desde su panel.</p>
            <p class="aviso interna">Los tickets de Contabilidad solo los crea el equipo de Contabilidad (y el administrador).</p>
        </aside>
    </form>
@endsection
