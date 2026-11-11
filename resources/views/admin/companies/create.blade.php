{{--
    «Nueva empresa» (Administración). Mockup validado: admin-empresa-c-nueva.html.
    Cuándo tocarla: al cambiar el alta (y entonces también StoreCompanyRequest y CreateCompany).
    Variables: $products (solo activos).
--}}
@extends('layouts.panel')
@section('titulo', 'Nueva empresa')

@section('contenido')
    <p class="migas"><a href="{{ route('admin.companies.index') }}">Empresas</a> › Nueva empresa</p>
    <h1>Nueva empresa</h1>
    <p class="sub" style="color:var(--texto-suave)">Da de alta la empresa y a su primer usuario. Recibirá un correo para crear su contraseña.</p>

    @if ($errors->any())
        <div class="alerta error" role="alert" style="margin-top:1rem">Revisa los campos marcados: {{ $errors->count() }} {{ $errors->count() === 1 ? 'error' : 'errores' }}.</div>
    @endif

    <form class="apilado" action="{{ route('admin.companies.store') }}" method="post" style="margin-top:1rem;max-width:820px">
        @csrf
        <div class="tarjeta">
            <h2>Datos de la empresa</h2>
            <div class="cols2">
                <div class="campo"><label for="name">Nombre o razón social <span class="oblig" aria-hidden="true">*</span></label><input type="text" id="name" name="name" @errorAttrs('name') value="{{ old('name') }}" required maxlength="255" placeholder="Talleres Marrero S.L.">@error('name')<p class="error-campo" id="err-name" role="alert">{{ $message }}</p>@enderror</div>
                <div class="campo"><label for="tax_id">CIF <span class="oblig" aria-hidden="true">*</span></label><input type="text" id="tax_id" name="tax_id" @errorAttrs('tax_id') value="{{ old('tax_id') }}" required maxlength="20" placeholder="B00000000">@error('tax_id')<p class="error-campo" id="err-tax_id" role="alert">{{ $message }}</p>@enderror</div>
                <div class="campo"><label for="phone">Teléfono</label><input type="tel" id="phone" name="phone" @errorAttrs('phone') value="{{ old('phone') }}" maxlength="30">@error('phone')<p class="error-campo" id="err-phone" role="alert">{{ $message }}</p>@enderror</div>
                <div class="campo"><label for="contact_email">Correo de contacto</label><input type="email" id="contact_email" name="contact_email" @errorAttrs('contact_email') value="{{ old('contact_email') }}" maxlength="255">@error('contact_email')<p class="error-campo" id="err-contact_email" role="alert">{{ $message }}</p>@enderror</div>
            </div>
        </div>

        <fieldset class="tarjeta" style="border:1px solid var(--gris)">
            <legend style="display:none">Productos contratados</legend>
            <h2>Productos contratados</h2>
            <p class="sub">Puedes dejarlo vacío y asignarlos después. Si no tiene ninguno, sus usuarios no verán el campo Producto al abrir un ticket.</p>
            @include('admin.companies._products', ['products' => $products, 'checked' => array_map('intval', (array) old('productos', []))])
            @error('productos.*')<p class="error-campo" role="alert">{{ $message }}</p>@enderror
        </fieldset>

        <div class="tarjeta">
            <h2>Primer usuario (administrador de la empresa)</h2>
            <p class="sub">Será quien gestione los demás usuarios de su empresa.</p>
            <div class="cols2">
                <div class="campo"><label for="user_name">Nombre y apellidos <span class="oblig" aria-hidden="true">*</span></label><input type="text" id="user_name" name="user_name" @errorAttrs('user_name') value="{{ old('user_name') }}" required maxlength="255">@error('user_name')<p class="error-campo" id="err-user_name" role="alert">{{ $message }}</p>@enderror</div>
                <div class="campo"><label for="user_email">Correo electrónico <span class="oblig" aria-hidden="true">*</span></label><input type="email" id="user_email" name="user_email" @errorAttrs('user_email') value="{{ old('user_email') }}" required maxlength="255"><p class="ayuda">Recibirá aquí la invitación.</p>@error('user_email')<p class="error-campo" id="err-user_email" role="alert">{{ $message }}</p>@enderror</div>
            </div>
        </div>

        <div class="acciones">
            <button type="submit" class="boton principal">Crear empresa y enviar invitación</button>
            <a class="boton secundario" href="{{ route('admin.companies.index') }}">Cancelar</a>
        </div>
    </form>
@endsection
