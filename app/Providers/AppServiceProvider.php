<?php

namespace App\Providers;

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
    }
}
