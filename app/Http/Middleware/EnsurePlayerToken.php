<?php

namespace App\Http\Middleware;

use App\Models\Player;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only authentication in Vacantastic.
 *
 * Resolves the current player from the room uuid (route) + a token supplied
 * either in the X-Player-Token header or the `token` query param (the latter
 * lets a plain <img> tag authenticate the private photo). Attaches the player
 * to the request attributes for downstream controllers.
 */
class EnsurePlayerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $uuid = $request->route('uuid');
        $token = $request->header('X-Player-Token') ?? $request->query('token');

        if (! $uuid || ! $token) {
            abort(401);
        }

        $player = Player::query()
            ->where('room_uuid', $uuid)
            ->where('token', $token)
            ->first();

        if (! $player) {
            abort(401);
        }

        $request->attributes->set('player', $player);

        return $next($request);
    }
}
