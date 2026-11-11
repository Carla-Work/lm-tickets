<?php

/*
 * Segundo factor del administrador desde «Mis datos»: activarlo, regenerar los códigos de recuperación y
 * desactivarlo. Regla R21 (opcional: sin activarlo, el administrador entra con contraseña).
 * Cuándo tocarlo: al cambiar cómo se gestiona el 2FA con la sesión ya iniciada. El 2FA al ENTRAR es otro
 * controlador (Auth\TwoFactorController). Mockups: mis-datos-c/d (regenerar), e (desactivada), f (activar),
 * g (códigos tras activar) y h (desactivar), validados por la usuaria el 2026-11-10.
 *
 * Trampas:
 *  - SOLO el administrador (R21). Un agente o un cliente recibe 403 en todas las rutas.
 *  - La clave NUEVA vive solo en la sesión (cifrada) hasta que se acierta un código: si se abandona la pantalla
 *    no queda nada a medias en la base de datos (a diferencia del acceso a medias del login).
 *  - Se guarda el intervalo del código usado en la activación (`two_factor_last_step`): ese mismo código no vale
 *    luego para entrar (un código TOTP no se acepta dos veces).
 *  - Regenerar y desactivar exigen la CONTRASEÑA actual, y las rutas limitan intentos: quien tenga la sesión
 *    abierta de otra persona no puede quitarle ni cambiarle el 2FA sin saberla.
 *  - Los códigos de recuperación en claro viajan por la sesión CIFRADOS, se ven UNA vez y no se guardan ni se
 *    auditan. La auditoría nunca lleva la clave ni los códigos.
 */

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Audit;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TwoFactorSettingsController extends Controller
{
    private const SECRET_KEY = 'two_factor_settings.secret';

    private const CODES_KEY = 'two_factor_settings.codes';

    private const KIND_KEY = 'two_factor_settings.kind';

    // ---------- Activar ----------

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $this->admin($request);
        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.show');
        }

        $secret = $this->pendingSecret($request) ?? Totp::generateSecret();
        $request->session()->put(self::SECRET_KEY, Crypt::encryptString($secret));

        return view('profile.two-factor-activate', [
            'user' => $user,
            'clave' => trim(chunk_split($secret, 4, ' ')),
            'enlace' => Totp::otpauthUri((string) config('access.two_factor.issuer'), $user->email, $secret),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $this->admin($request);
        $request->validate(['codigo' => ['required', 'string']], [], ['codigo' => 'código']);

        $secret = $this->pendingSecret($request);
        if ($user->hasTwoFactorEnabled() || $secret === null) {
            return redirect()->route('profile.show');
        }

        $step = $this->totp()->verify($secret, (string) $request->input('codigo'), now()->timestamp, (int) config('access.two_factor.window'));
        if ($step === null) {
            throw ValidationException::withMessages(['codigo' => trans('auth.two_factor_failed')]);
        }

        $codes = RecoveryCodes::generate((int) config('access.two_factor.recovery_codes'));
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => RecoveryCodes::hashAll($codes),
        ])->save();

        $request->session()->forget(self::SECRET_KEY);
        Audit::record('two_factor_enabled', 'Ha activado el segundo factor desde «Mis datos».', $user, $user, $request->ip());
        $this->showCodesOnce($request, $codes, 'activated');

        return redirect()->route('profile.two-factor.codes');
    }

    // ---------- Códigos de recuperación (tras activar o regenerar) ----------

    public function codes(Request $request): View|RedirectResponse
    {
        $user = $this->admin($request);
        $encrypted = $request->session()->get(self::CODES_KEY);
        if (! is_string($encrypted)) {
            return redirect()->route('profile.show'); // ya se vieron (o nunca hubo): no se pueden volver a mostrar
        }

        /** @var list<string> $codes */
        $codes = json_decode(Crypt::decryptString($encrypted), true);

        return view('profile.two-factor-codes', [
            'user' => $user,
            'codes' => $codes,
            'regenerated' => $request->session()->get(self::KIND_KEY) === 'regenerated',
        ]);
    }

    public function finish(Request $request): RedirectResponse
    {
        $this->admin($request);
        $request->validate(['guardados' => ['accepted']], [], ['guardados' => 'confirmación']);
        $request->session()->forget([self::CODES_KEY, self::KIND_KEY]);

        return redirect()->route('profile.show')->with('status', 'Verificación en dos pasos lista.');
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $user = $this->admin($request);
        $this->requirePassword($request, $user);
        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.show');
        }

        $codes = RecoveryCodes::generate((int) config('access.two_factor.recovery_codes'));
        $user->forceFill(['two_factor_recovery_codes' => RecoveryCodes::hashAll($codes)])->save();
        Audit::record('recovery_codes_regenerated', 'Ha generado códigos de recuperación nuevos.', $user, $user, $request->ip());
        $this->showCodesOnce($request, $codes, 'regenerated');

        return redirect()->route('profile.two-factor.codes');
    }

    // ---------- Desactivar ----------

    public function disable(Request $request): RedirectResponse
    {
        $user = $this->admin($request);
        $this->requirePassword($request, $user);

        if ($user->hasTwoFactorEnabled()) {
            $user->clearTwoFactor();
            Audit::record('two_factor_disabled', 'Ha desactivado el segundo factor.', $user, $user, $request->ip());
        }

        return redirect()->route('profile.show')->with('status', 'Verificación en dos pasos desactivada.');
    }

    // ---------- Apoyo ----------

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user !== null && $user->hasRole(UserRole::Admin), 403);

        return $user;
    }

    private function requirePassword(Request $request, User $user): void
    {
        $data = $request->validate(['password' => ['required', 'string']], [], ['password' => 'contraseña']);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'La contraseña no es correcta.']);
        }
    }

    private function pendingSecret(Request $request): ?string
    {
        $encrypted = $request->session()->get(self::SECRET_KEY);

        return is_string($encrypted) ? Crypt::decryptString($encrypted) : null;
    }

    /** @param  list<string>  $codes */
    private function showCodesOnce(Request $request, array $codes, string $kind): void
    {
        $request->session()->put(self::CODES_KEY, Crypt::encryptString((string) json_encode($codes)));
        $request->session()->put(self::KIND_KEY, $kind);
    }

    private function totp(): Totp
    {
        return new Totp((int) config('access.two_factor.digits'), (int) config('access.two_factor.period'));
    }
}
