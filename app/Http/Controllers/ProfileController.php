<?php

/*
 * «Mis datos» (todos los roles): datos personales y cambio de contraseña. Mockups validados: mis-datos-a-cliente,
 * mis-datos-b-interno y mis-datos-c-administrador (sin su tarjeta de verificación en dos pasos, ver abajo).
 * Cuándo tocarlo: al cambiar esta pantalla.
 *
 * Trampas:
 *  - Solo se editan nombre y teléfono (el teléfono solo lo pide el cliente en el mockup). El correo, la empresa, el
 *    rol y el equipo son de solo lectura: los cambia otra persona (R8) y NO están en #[Fillable] a propósito.
 *  - FALTA la tarjeta del 2FA del administrador (activar, desactivar, códigos nuevos): su mockup (mis-datos-c/d)
 *    está pendiente de validar y no cubre «activar» ni «desactivar». Mientras tanto no se puede activar desde la
 *    interfaz (R21 es opcional).
 *  - La contraseña actual se comprueba SIEMPRE (quien tenga una sesión abierta ajena no puede cambiarla sin
 *    saberla) y la ruta limita los intentos. Otras sesiones abiertas de la misma cuenta NO se cierran (pendiente).
 */

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('profile.show', ['user' => $this->me($request)]);
    }

    public function updateData(Request $request): RedirectResponse
    {
        $user = $this->me($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ], [], ['name' => 'nombre', 'phone' => 'teléfono']);

        $user->name = $data['name'];
        // El teléfono solo se edita si es de cliente (así lo dibuja el mockup); a un empleado no se le toca.
        if (! $user->isInternal()) {
            $user->phone = $data['phone'] ?? null;
        }
        $user->save();

        return redirect()->route('profile.show')->with('status', 'Datos guardados.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $this->me($request);
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min((int) config('access.password.min_length'))],
        ], [], ['current_password' => 'contraseña actual', 'password' => 'contraseña nueva']);

        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'La contraseña actual no es correcta.']);
        }

        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save(); // el cast `hashed` la cifra
        $request->session()->regenerate();
        Audit::record('password_changed', "{$user->email} cambió su contraseña.", $user, $user, $request->ip());

        return redirect()->route('profile.show')->with('status', 'Contraseña cambiada.');
    }

    private function me(Request $request): User
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        return $user;
    }
}
