<?php

declare(strict_types=1);

use App\Http\Middleware\BlockRegisterAfterBootstrap;
use App\Http\Middleware\ResolveApiWorkspace;
use App\Http\Middleware\ResolveChatwootWebhookConnection;
use App\Http\Middleware\VerifyInternalRuntimeToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a TLS-terminating reverse proxy: honor X-Forwarded-* so the app
        // sees the real client IP and the https scheme (secure cookies, redirects).
        $middleware->trustProxies(at: '*');

        // But never trust an arbitrary X-Forwarded-Host: restrict to the app's own
        // host (from APP_URL) so a forged Host cannot poison generated links
        // (password resets, signed URLs). Empty APP_URL => no restriction.
        $middleware->trustHosts(at: function (): array {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            return is_string($host) && $host !== '' ? [$host] : [];
        });

        $middleware->alias([
            'chatwoot.webhook' => ResolveChatwootWebhookConnection::class,
            'internal.runtime' => VerifyInternalRuntimeToken::class,
            'api.workspace' => ResolveApiWorkspace::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        $middleware->web(append: [
            BlockRegisterAfterBootstrap::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
