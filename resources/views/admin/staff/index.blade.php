{{--
    «Usuarios» (Administración): empleados de Ling Marco. Mockup validado: admin-usuarios-a-empleados.html.
    Cuándo tocarla: al cambiar la pantalla. Variables: $staff (administrador + agentes, incluidos los de baja).
    Trampas:
     - Qué botones salen lo deciden las policies (@can), no el rol visto a ojo.
     - Desviaciones del mockup: sin «Último acceso» (no se guarda) y el traspaso pide el equipo del administrador saliente.
--}}
@extends('layouts.panel')
@section('titulo', 'Usuarios')

@section('contenido')
    <div class="titulo-fila">
        <div><h1>Usuarios</h1><p class="sub">Empleados de Ling Marco. Solo tú puedes crearlos, desactivarlos o cambiar su equipo.</p></div>
    </div>

    @if ($errors->any())
        <div class="alerta error" role="alert">{{ $errors->first() }}</div>
    @endif

    @php $candidates = $staff->filter(fn ($m) => $user->can('transferAdmin', $m)); @endphp
    <div class="dos">
        <div class="apilado">
            <div class="tabla-caja">
                <table class="fichas">
                    <caption>{{ $staff->count() }} {{ $staff->count() === 1 ? 'empleado' : 'empleados' }}</caption>
                    <thead><tr><th scope="col">Nombre</th><th scope="col">Rol</th><th scope="col">Equipo</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
                    <tbody>
                        @foreach ($staff as $member)
                            <tr>
                                <td class="asunto-celda" data-etiqueta="Nombre"><strong>{{ $member->name }}</strong><span class="secundario">{{ $member->email }}</span></td>
                                <td data-etiqueta="Rol"><span class="rolchip {{ $member->role === \App\Enums\UserRole::Admin ? 'admin' : '' }}">{{ $member->role->label() }}</span></td>
                                <td data-etiqueta="Equipo">{{ $member->team?->label() ?? '—' }}</td>
                                <td data-etiqueta="Estado"><span class="estado-cuenta">{{ $member->isDeactivated() ? '○ De baja' : ($member->isPendingInvitation() ? '✉ Invitación pendiente' : '✓ Activo') }}</span></td>
                                <td data-etiqueta="Acciones">
                                    @if ($member->role === \App\Enums\UserRole::Admin)
                                        <span class="secundario" style="max-width:15rem">No se puede desactivar mientras sea el administrador: traspasa antes el rol.</span>
                                    @else
                                        <div class="acc">
                                            @can('changeTeam', $member)
                                                <form action="{{ route('admin.staff.team', $member) }}" method="post" class="fila-form">
                                                    @csrf
                                                    <label class="sr-only" for="eq-{{ $member->id }}">Equipo de {{ $member->name }}</label>
                                                    <select id="eq-{{ $member->id }}" name="team">
                                                        @foreach (\App\Enums\Team::cases() as $team)
                                                            <option value="{{ $team->value }}" @selected($member->team === $team)>{{ $team->label() }}</option>
                                                        @endforeach
                                                    </select>
                                                    <button type="submit" class="boton secundario pequeno">Cambiar equipo</button>
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

            <form class="tarjeta peligro" action="{{ route('admin.staff.transfer') }}" method="post" aria-label="Traspasar el rol de administrador" style="margin-top:1.25rem">
                @csrf
                <h2>Traspasar el rol de administrador</h2>
                <p class="sub" style="color:var(--texto)">Solo puede haber un administrador. Si quieres dejar de serlo, elige antes a quién pasa el rol. Tú pasarás a ser agente del equipo que elijas y solo verás los tickets de ese equipo. Los tickets que llevara la persona elegida quedan sin asignar.</p>
                @if ($candidates->isEmpty())
                    <p class="ayuda">No hay ningún agente en activo al que traspasar el rol.</p>
                @else
                    <div class="cols2">
                        <div class="campo">
                            <label for="nuevo-adm">Nuevo administrador</label>
                            <select id="nuevo-adm" name="nuevo" required>
                                <option value="">Elige un empleado</option>
                                @foreach ($candidates as $candidate)
                                    <option value="{{ $candidate->id }}" @selected((string) old('nuevo') === (string) $candidate->id)>{{ $candidate->name }}</option>
                                @endforeach
                            </select>
                            @error('nuevo')<p class="error-campo" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="campo">
                            <label for="equipo-saliente">Tu equipo cuando seas agente</label>
                            <select id="equipo-saliente" name="equipo" required>
                                @foreach (\App\Enums\Team::cases() as $team)
                                    <option value="{{ $team->value }}" @selected(old('equipo', $user->team?->value ?? 'support') === $team->value)>{{ $team->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="campo">
                            <label for="conf">Escribe TRASPASAR para confirmar</label>
                            <input type="text" id="conf" name="confirmacion" autocomplete="off" required>
                            @error('confirmacion')<p class="error-campo" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <button type="submit" class="boton peligro-b">Traspasar el rol</button>
                @endif
            </form>
        </div>

        <aside>
            <form class="tarjeta" action="{{ route('admin.staff.store') }}" method="post" aria-label="Nuevo empleado">
                @csrf
                <h2>Nuevo empleado</h2>
                <div class="campo"><label for="en">Nombre y apellidos <span class="oblig" aria-hidden="true">*</span></label><input type="text" id="en" name="name" value="{{ old('name') }}" required maxlength="255">@error('name')<p class="error-campo" role="alert">{{ $message }}</p>@enderror</div>
                <div class="campo"><label for="ee">Correo de Ling Marco <span class="oblig" aria-hidden="true">*</span></label><input type="email" id="ee" name="email" value="{{ old('email') }}" required maxlength="255"><p class="ayuda">Recibirá aquí la invitación para crear su contraseña.</p>@error('email')<p class="error-campo" role="alert">{{ $message }}</p>@enderror</div>
                <div class="campo">
                    <label for="eq">Equipo <span class="oblig" aria-hidden="true">*</span></label>
                    <select id="eq" name="team" required>
                        @foreach (\App\Enums\Team::cases() as $team)<option value="{{ $team->value }}" @selected(old('team') === $team->value)>{{ $team->label() }}</option>@endforeach
                    </select>
                    @error('team')<p class="error-campo" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="campo"><label for="ne-rol">Rol</label><input type="text" id="ne-rol" value="Agente" readonly><p class="ayuda">Solo existe un administrador; se cambia con el traspaso.</p></div>
                <button type="submit" class="boton principal">Crear y enviar invitación</button>
            </form>
        </aside>
    </div>
@endsection
