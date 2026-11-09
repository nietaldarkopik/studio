<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Limiter bernama: tiap limiter punya hitungan sendiri, sehingga polling GET
        // tidak menghabiskan jatah POST (kunci `throttle:N,M` bersama per pengguna).
        $perUser = fn (Request $request) => (string) ($request->user()?->id ?? $request->ip());

        foreach ([
            'script-generate' => 5,
            'script-poll' => 240,
            'ai-generate' => 10,
            'ai-poll' => 240,
            'run-start' => 6,
            'run-poll' => 240,
            'hub' => 300,
        ] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by($perUser($request)));
        }
    }
}
