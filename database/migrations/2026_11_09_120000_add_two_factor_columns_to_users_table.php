<?php

/*
 * Columnas del segundo factor (2FA) del administrador (R21). Ver docs/plan/modelo-de-datos.md.
 * Cuándo tocarlo: si cambia el método del segundo factor.
 *
 * Qué guarda cada una y por qué:
 *  - two_factor_secret: la clave TOTP, CIFRADA con la clave de la aplicación (cast `encrypted`). Si
 *    se guardara en claro, quien leyera la base de datos podría generar los códigos del administrador.
 *  - two_factor_confirmed_at: nulo = el 2FA aún no está activado (la clave existe pero la persona
 *    no ha demostrado que su app funciona). Mientras sea nulo, el administrador no puede entrar.
 *  - two_factor_recovery_codes: los códigos de recuperación, cada uno con HASH (como una contraseña).
 *    Ni el servidor puede volver a mostrarlos. Al usar uno se borra de la lista.
 *  - two_factor_last_step: último intervalo de 30 s ya aceptado. Evita que el MISMO código se use dos
 *    veces (reutilización) dentro de su ventana de validez.
 *
 * Trampa: el segundo factor solo lo exige el rol administrador, pero las columnas están en `users`
 * para todos; el rol decide si se usan (User::requiresTwoFactor()).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->json('two_factor_recovery_codes')->nullable();
            $table->unsignedBigInteger('two_factor_last_step')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes', 'two_factor_last_step']);
        });
    }
};
