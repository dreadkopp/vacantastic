<?php

namespace App\Providers;

use App\Models\Player;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Safe multi-process SQLite (the app + scheduler both write):
        // WAL mode + a busy timeout so writers wait instead of failing.
        if (config('database.default') === 'sqlite') {
            DB::selectOne('PRAGMA journal_mode=WAL;');
            DB::statement('PRAGMA busy_timeout=5000;');
        }

        // The app has no session-based login — every request (REST and
        // broadcasting-auth alike) identifies itself with a per-player
        // token (X-Player-Token header or `token` query param). Exposing
        // that as a real Auth guard lets Laravel's broadcasting auth
        // (which requires a resolvable "user" for private channels) work.
        Auth::viaRequest('player-token', function ($request) {
            $token = $request->header('X-Player-Token') ?? $request->query('token');

            return $token ? Player::where('token', $token)->first() : null;
        });
    }
}
