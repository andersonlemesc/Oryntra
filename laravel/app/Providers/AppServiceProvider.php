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

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(WorkspaceContext::class);
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
