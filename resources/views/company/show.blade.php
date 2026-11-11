{{--
    «Mi empresa» (cliente). Mockup validado: cliente-mi-empresa-a-administrador.html (la vista del usuario normal no
    está dibujada: solo ve los productos, decisión abierta nº 8 resuelta con lo más restrictivo).
    Cuándo tocarla: al cambiar la pantalla. Variables: $company, $products (activos), $canManage, $members.
    Trampas: qué botones salen lo deciden las policies (@can); los productos no se pueden cambiar aquí (R7).
--}}
@extends('layouts.panel')
@section('titulo', 'Mi empresa')

@section('contenido')
    <h1>Mi empresa</h1>
    <p class="sub" style="color:var(--texto-suave)">{{ $company->name }} · CIF {{ $company->tax_id }}</p>

    @if ($errors->any())
        <div class="alerta error" role="alert" style="margin-top:1rem">{{ $errors->first() }}</div>
    @endif

    <div class="dos" style="margin-top:1rem">
        <div class="apilado">
            @if ($canManage)
                <section class="tarjeta" aria-label="Usuarios de la empresa">
                    <h2>Usuarios de tu empresa</h2>
                    <div class="tabla-caja" style="border:0">
                        <table class="fichas">
                            <thead><tr><th scope="col">Nombre</th><th scope="col">Rol</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
                            <tbody>
                                @foreach ($members as $member)
                                    <tr>
                                        <td class="asunto-celda" data-etiqueta="Nombre"><strong>{{ $member->name }}</strong><span class="secundario">{{ $member->email }}</span></td>
                                        <td data-etiqueta="Rol"><span class="rolchip {{ $member->role === \App\Enums\UserRole::CompanyAdmin ? 'admin' : '' }}">{{ $member->role->label() }}</span></td>
                                        <td data-etiqueta="Estado"><span class="estado-cuenta">{{ $member->isDeactivated() ? '○ De baja' : ($member->isPendingInvitation() ? '✉ Invitación pendiente' : '✓ Activo') }}</span></td>
                                        <td data-etiqueta="Acciones">
                                            @if ($member->is($user))
                                                <span class="secundario">Eres tú</span>
                                            @else
                                                <div class="acc">
                                                    @can('changeCompanyRole', $member)
                                                        <form action="{{ route('company.users.role', $member) }}" method="post">@csrf
                                                            <input type="hidden" name="role" value="{{ $member->role === \App\Enums\UserRole::CompanyAdmin ? 'company_user' : 'company_admin' }}">
                                                            <button type="submit" class="boton secundario pequeno">{{ $member->role === \App\Enums\UserRole::CompanyAdmin ? 'Pasar a usuario' : 'Hacer administrador' }}</button>
                                                        </form>
                                                    @endcan
                                                    @can('resendInvitation', $member)
                                                        <form action="{{ route('users.invitation.resend', $member) }}" method="post">@csrf<button type="submit" class="boton secundario pequeno">Reenviar invitación</button></form>
                                                    @endcan
                                                    @can('deactivate', $member)
                                                        @if ($member->isDeactivated())
                                                            <form action="{{ route('users.reactivate', $member) }}" method="post">@csrf<button type="submit" class="boton secundario pequeno">Reactivar</button></form>
                                                        @else
                                                            <form action="{{ route('users.deactivate', $member) }}" method="post">@csrf<button type="submit" class="boton peligro-b pequeno">Desactivar</button></form>
                                                        @endif
                                                    @endcan
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <form class="tarjeta" action="{{ route('company.users.store') }}" method="post" aria-label="Añadir usuario">
                    @csrf
                    <h2>Añadir un usuario</h2>
                    <div class="cols2">
                        <div class="campo"><label for="nn">Nombre y apellidos <span class="oblig" aria-hidden="true">*</span></label><input type="text" id="nn" name="name" @errorAttrs('name') value="{{ old('name') }}" required maxlength="255">@error('name')<p class="error-campo" id="err-name" role="alert">{{ $message }}</p>@enderror</div>
                        <div class="campo"><label for="ne">Correo electrónico <span class="oblig" aria-hidden="true">*</span></label><input type="email" id="ne" name="email" @errorAttrs('email') value="{{ old('email') }}" required maxlength="255">@error('email')<p class="error-campo" id="err-email" role="alert">{{ $message }}</p>@enderror</div>
                        <div class="campo">
                            <label for="nr">Rol</label>
                            <select id="nr" name="role">
                                <option value="company_user" @selected(old('role') === 'company_user')>Usuario</option>
                                <option value="company_admin" @selected(old('role') === 'company_admin')>Administrador de empresa</option>
                            </select>
                            <p class="ayuda">Un usuario solo ve sus tickets. Un administrador de empresa ve los de toda la empresa.</p>
                            @error('role')<p class="error-campo" id="err-role" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <button type="submit" class="boton principal">Enviar invitación</button>
                </form>
            @else
                <section class="tarjeta" aria-label="Tu empresa">
                    <h2>Tu empresa</h2>
                    <p style="margin:0">Los usuarios de la empresa los gestiona su administrador. Aquí puedes ver los productos contratados.</p>
                </section>
            @endif
        </div>

        <aside class="tarjeta" aria-label="Productos contratados">
            <h2>Productos contratados</h2>
            @if ($products->isEmpty())
                <p class="ayuda" style="margin:0 0 1rem">Tu empresa no tiene productos contratados.</p>
            @else
                <ul style="list-style:none;margin:0 0 1rem;padding:0">
                    @foreach ($products as $product)
                        <li style="padding:.5rem 0;border-bottom:1px solid var(--gris)"><strong>{{ $product->name }}</strong><br><span style="color:var(--texto-suave);font-size:.9rem">{{ $product->description }}</span></li>
                    @endforeach
                </ul>
            @endif
            <p class="aviso" style="margin:0">Solo Ling Marco puede cambiar los productos contratados. Si necesitas uno nuevo, abre un ticket en el departamento <strong>Otros</strong>.</p>
        </aside>
    </div>
@endsection
