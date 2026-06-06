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

        // Restrict the trusted Host so a forged X-Forwarded-Host cannot poison
        // generated links (password resets, signed URLs). Allow the public host
        // (APP_URL) plus the in-cluster service names used by internal calls
        // (agent -> laravel via http://nginx, health checks via localhost).
        // Those names are not externally routable — they cannot be abused for
        // poisoned links — and internal routes stay token-guarded regardless.
        $middleware->trustHosts(at: function (): array {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            return array_values(array_filter([$host, 'nginx', 'localhost']));
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
