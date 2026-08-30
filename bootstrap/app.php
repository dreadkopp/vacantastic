<?php

use App\Http\Middleware\EnsureHost;
use App\Http\Middleware\EnsurePlayerToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'player' => EnsurePlayerToken::class,
            'host' => EnsureHost::class,
        ]);

        // Behind the Traefik reverse proxy (TLS termination): trust the
        // calling proxy's X-Forwarded-* headers so generated URLs use https.
        // The app container is only reachable via Traefik (no exposed ports).
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
