<?php

use App\Http\Middleware\EnsureAccountIsOperational;
use App\Http\Middleware\EnsurePendingLogin;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Quien ya ha iniciado sesión y abre /acceso va directo al panel.
        $middleware->redirectUsersTo(fn () => route('panel'));

        // Cabeceras de seguridad en todas las respuestas web (D-1). Ver SecurityHeaders.
        $middleware->appendToGroup('web', SecurityHeaders::class);

        $middleware->alias([
            'login.pending' => EnsurePendingLogin::class,   // pantallas del segundo factor
            'operational' => EnsureAccountIsOperational::class, // cuenta o empresa de baja: se cierra la sesión
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
