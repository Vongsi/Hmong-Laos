<?php

use App\Http\Controllers\WhatsAppCallbackController;
use App\Http\Middleware\TrackLastSeen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // The WhatsApp sender (whatsapp-sender/) reports what happened to each message. Outside the "web" group: no
        // session or CSRF token; it signs in with the shared token instead (WhatsAppCallbackController).
        then: fn () => Route::post('whatsapp/status', WhatsAppCallbackController::class)->middleware('throttle:120,1')->name('whatsapp.status'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Members sign in on the Join page (there is no separate login route).
        $middleware->redirectGuestsTo('/join');
        $middleware->web(append: TrackLastSeen::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
