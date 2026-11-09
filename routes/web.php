<?php

/*
 * Rutas web. Cuándo tocarlo: al añadir pantallas.
 *
 * R18: NO hay ruta de registro. Las cuentas nacen por invitación (App\Actions\Users\InviteUser).
 * NewPasswordController atiende dos enlaces distintos (recuperar y activar) con el mismo código;
 * lo que cambia es el «broker», que se pasa como valor por defecto de la ruta.
 */

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\PanelController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Solo para quien NO ha iniciado sesión.
Route::middleware('guest')->group(function () {
    Route::get('/acceso', [LoginController::class, 'create'])->name('login');
    Route::post('/acceso', [LoginController::class, 'store'])->name('login.store');

    Route::get('/recuperar', [ForgotPasswordController::class, 'create'])->name('password.request');
    // Tope de peticiones por IP para que nadie use el formulario para llenar de correos un buzón ajeno.
    Route::post('/recuperar', [ForgotPasswordController::class, 'store'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/recuperar/enviado', [ForgotPasswordController::class, 'sent'])->name('password.sent');
});

// Segundo factor del administrador (R21). SIN sesión iniciada pero con el «acceso a medias» (correo y
// contraseña ya acertados): lo exige el middleware login.pending.
Route::prefix('acceso/segundo-factor')->middleware(['guest', 'login.pending'])->group(function () {
    Route::get('/activar', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/activar', [TwoFactorController::class, 'confirmSetup'])->name('two-factor.setup.confirm');
    Route::get('/codigos', [TwoFactorController::class, 'codes'])->name('two-factor.codes');
    Route::post('/codigos', [TwoFactorController::class, 'finish'])->name('two-factor.finish');
    Route::get('/', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/', [TwoFactorController::class, 'verify'])->name('two-factor.verify');
    Route::get('/recuperacion', [TwoFactorController::class, 'recovery'])->name('two-factor.recovery');
    Route::post('/recuperacion', [TwoFactorController::class, 'useRecovery'])->name('two-factor.recovery.use');
});

// Enlaces de los correos. SIN el middleware «guest»: lo protege el token, y quien abre el enlace
// puede tener otra sesión iniciada en ese navegador.
Route::get('/contrasena/{token}', [NewPasswordController::class, 'create'])->defaults('broker', 'users')->name('password.reset');
Route::post('/contrasena', [NewPasswordController::class, 'store'])->defaults('broker', 'users')->name('password.update');
Route::get('/invitacion/{token}', [NewPasswordController::class, 'create'])->defaults('broker', 'invitations')->name('invitation.accept');
Route::post('/invitacion', [NewPasswordController::class, 'store'])->defaults('broker', 'invitations')->name('invitation.store');

// admin.2fa: un administrador sin segundo factor activado no pasa (R21), p. ej. si lo ascienden con la sesión abierta.
Route::middleware(['auth', 'admin.2fa'])->group(function () {
    Route::get('/panel', PanelController::class)->name('panel');
    Route::post('/salir', [LoginController::class, 'destroy'])->name('logout');
});
