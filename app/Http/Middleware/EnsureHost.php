<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `player`; allows only the room host through.
 */
class EnsureHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $player = $request->attributes->get('player');

        if (! $player || ! $player->is_host) {
            abort(403);
        }

        return $next($request);
    }
}
