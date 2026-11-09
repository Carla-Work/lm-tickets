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

// Enlaces de los correos. SIN el middleware «guest»: lo protege el token, y quien abre el enlace
// puede tener otra sesión iniciada en ese navegador.
Route::get('/contrasena/{token}', [NewPasswordController::class, 'create'])->defaults('broker', 'users')->name('password.reset');
Route::post('/contrasena', [NewPasswordController::class, 'store'])->defaults('broker', 'users')->name('password.update');
Route::get('/invitacion/{token}', [NewPasswordController::class, 'create'])->defaults('broker', 'invitations')->name('invitation.accept');
Route::post('/invitacion', [NewPasswordController::class, 'store'])->defaults('broker', 'invitations')->name('invitation.store');

Route::middleware('auth')->group(function () {
    Route::get('/panel', PanelController::class)->name('panel');
    Route::post('/salir', [LoginController::class, 'destroy'])->name('logout');
});
