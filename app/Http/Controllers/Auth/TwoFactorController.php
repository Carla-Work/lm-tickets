<?php

/*
 * Segundo factor (2FA) del administrador: activarlo, verificarlo al entrar y usar un código de
 * recuperación. Regla R21; los intentos fallidos cuentan para el bloqueo R20 y los errores son
 * genéricos (R17).
 * Cuándo tocarlo: al cambiar cómo se activa o se verifica el segundo factor.
 * Mockups validados: docs/plan/mockups/acceso-f … acceso-i.
 *
 * Cómo encaja con el acceso: LoginController, tras acertar correo y contraseña de un administrador,
 * NO inicia sesión: deja un «acceso a medias» (App\Support\PendingLogin) y manda aquí. Solo al pasar el
 * segundo factor se llama a Auth::login(). Hasta entonces la persona NO está autenticada.
 *
 * Trampas:
 *  - Los fallos de código usan la MISMA clave de bloqueo (correo + IP) que la contraseña y SOLO se
 *    borran al terminar el acceso entero. Si se borraran al acertar la contraseña, quien la conoce
 *    podría reiniciar el contador a voluntad y probar códigos sin límite.
 *  - Los códigos de recuperación en claro viajan por la sesión CIFRADOS y solo hasta que la persona
 *    confirma que los ha guardado; no se pueden volver a ver.
 *  - Un código TOTP no se acepta dos veces (se guarda el último intervalo usado).
 */

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\RecoveryCodeUsedNotification;
use App\Support\LoginThrottle;
use App\Support\PendingLogin;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(private readonly LoginThrottle $throttle) {}

    // ---------- Activar (primera vez) ----------

    public function setup(Request $request): View|RedirectResponse
    {
        $usuario = $this->usuario($request);

        if ($usuario->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.challenge');
        }

        if ($usuario->two_factor_secret === null) {
            $usuario->forceFill(['two_factor_secret' => Totp::generateSecret()])->save();
        }

        return view('acceso.segundo-factor-activar', [
            'clave' => trim(chunk_split((string) $usuario->two_factor_secret, 4, ' ')),
            'enlace' => Totp::otpauthUri((string) config('access.two_factor.issuer'), $usuario->email, (string) $usuario->two_factor_secret),
            'email' => $usuario->email,
        ]);
    }

    public function confirmSetup(Request $request): RedirectResponse
    {
        $request->validate(['codigo' => ['required', 'string']]);
        $usuario = $this->usuario($request);

        if ($usuario->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.challenge');
        }

        $clave = $this->claveDeBloqueo($request, $usuario);
        $this->rechazarSiBloqueado($clave);

        $intervalo = $this->totp()->verify((string) $usuario->two_factor_secret, (string) $request->input('codigo'), now()->timestamp, (int) config('access.two_factor.window'));

        if ($intervalo === null) {
            $this->throttle->fail($clave);
            throw ValidationException::withMessages(['codigo' => trans('auth.two_factor_failed')]);
        }

        $codigos = RecoveryCodes::generate((int) config('access.two_factor.recovery_codes'));
        $usuario->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $intervalo,
            'two_factor_recovery_codes' => RecoveryCodes::hashAll($codigos),
        ])->save();

        // Los códigos en claro se ven UNA vez: viajan cifrados en la sesión hasta que se confirma que se guardaron.
        $request->session()->put('two_factor.codes', Crypt::encryptString((string) json_encode($codigos)));

        return redirect()->route('two-factor.codes');
    }

    public function codes(Request $request): View|RedirectResponse
    {
        $cifrado = $request->session()->get('two_factor.codes');
        if (! is_string($cifrado)) {
            return redirect()->route('login');
        }

        /** @var list<string> $codigos */
        $codigos = json_decode(Crypt::decryptString($cifrado), true);

        return view('acceso.segundo-factor-codigos', ['codigos' => $codigos, 'email' => $this->usuario($request)->email]);
    }

    public function finish(Request $request): RedirectResponse
    {
        $request->validate(['guardados' => ['accepted']]);

        return $this->completarAcceso($request, $this->usuario($request));
    }

    // ---------- Verificar al entrar ----------

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $this->usuario($request)->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.setup');
        }

        return view('acceso.segundo-factor-verificar');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['codigo' => ['required', 'string']]);
        $usuario = $this->usuario($request);

        if (! $usuario->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.setup');
        }

        $clave = $this->claveDeBloqueo($request, $usuario);
        $this->rechazarSiBloqueado($clave);

        $intervalo = $this->totp()->verify(
            (string) $usuario->two_factor_secret,
            (string) $request->input('codigo'),
            now()->timestamp,
            (int) config('access.two_factor.window'),
            $usuario->two_factor_last_step,
        );

        if ($intervalo === null) {
            $this->throttle->fail($clave);
            throw ValidationException::withMessages(['codigo' => trans('auth.two_factor_failed')]);
        }

        $usuario->forceFill(['two_factor_last_step' => $intervalo])->save(); // este código ya no vale más

        return $this->completarAcceso($request, $usuario);
    }

    // ---------- Código de recuperación ----------

    public function recovery(Request $request): View|RedirectResponse
    {
        $usuario = $this->usuario($request);

        if (! $usuario->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.setup');
        }

        return view('acceso.segundo-factor-recuperacion', ['quedan' => $usuario->remainingRecoveryCodes()]);
    }

    public function useRecovery(Request $request): RedirectResponse
    {
        $request->validate(['codigo' => ['required', 'string']]);
        $usuario = $this->usuario($request);

        if (! $usuario->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.setup');
        }

        $clave = $this->claveDeBloqueo($request, $usuario);
        $this->rechazarSiBloqueado($clave);

        $restantes = RecoveryCodes::consume($usuario->two_factor_recovery_codes ?? [], (string) $request->input('codigo'));

        if ($restantes === null) {
            $this->throttle->fail($clave);
            throw ValidationException::withMessages(['codigo' => trans('auth.two_factor_failed')]);
        }

        $usuario->forceFill(['two_factor_recovery_codes' => $restantes])->save(); // uso único
        $usuario->notify(new RecoveryCodeUsedNotification(count($restantes)));

        $request->session()->flash('status', 'Has entrado con un código de recuperación. Te quedan '.count($restantes).' sin usar.');

        return $this->completarAcceso($request, $usuario);
    }

    // ---------- Apoyo ----------

    private function usuario(Request $request): User
    {
        /** @var User $usuario */
        $usuario = $request->attributes->get('pending_user');

        return $usuario;
    }

    private function totp(): Totp
    {
        return new Totp((int) config('access.two_factor.digits'), (int) config('access.two_factor.period'));
    }

    private function claveDeBloqueo(Request $request, User $usuario): string
    {
        return LoginThrottle::key($usuario->email, (string) $request->ip());
    }

    /** R20: estando bloqueado se rechaza ANTES de mirar el código (aunque sea el bueno). */
    private function rechazarSiBloqueado(string $clave): void
    {
        if ($this->throttle->isLocked($clave)) {
            throw ValidationException::withMessages([
                'codigo' => trans('auth.throttle', ['minutes' => (int) ceil($this->throttle->secondsLeft($clave) / 60)]),
            ]);
        }
    }

    /** El único sitio donde un administrador queda autenticado. */
    private function completarAcceso(Request $request, User $usuario): RedirectResponse
    {
        $this->throttle->clear($this->claveDeBloqueo($request, $usuario)); // acceso completo: ahora sí se borran los fallos

        Auth::login($usuario);
        PendingLogin::forget($request);
        $request->session()->regenerate();

        return redirect()->intended(route('panel'));
    }
}
