<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        // SPA only, no API tokens: there is no personal_access_tokens table. An empty token makes the Sanctum guard
        // skip the token lookup, so a Bearer header ends in 401 instead of a query on a table that does not exist.
        Sanctum::getAccessTokenFromRequestUsing(static fn (Request $request): ?string => null);
    }
}
