<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ApiToken;
use App\Support\WorkspaceContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Laravel\Telescope\TelescopeServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(WorkspaceContext::class);

        // Telescope is a dev-only dependency (composer require-dev). Register it
        // only when installed and running locally, so production images built
        // with --no-dev boot without the Telescope classes present.
        if ($this->app->environment('local') && class_exists(TelescopeServiceProvider::class)) {
            $this->app->register(TelescopeServiceProvider::class);
            $this->app->register(\App\Providers\TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        RateLimiter::for('mcp', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();
            $key = $token?->getKey() ?? $request->ip() ?? 'guest';

            return Limit::perMinute(120)->by('mcp:' . $key);
        });
    }
}
