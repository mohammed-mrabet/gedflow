<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // API : 60 requêtes par minute et par jeton (ou par adresse IP sans jeton).
        RateLimiter::for('api', function (Request $request) {
            $token = $request->user()?->currentAccessToken();

            return Limit::perMinute(60)->by($token instanceof PersonalAccessToken ? 'token:'.$token->id : 'ip:'.$request->ip());
        });
    }
}
