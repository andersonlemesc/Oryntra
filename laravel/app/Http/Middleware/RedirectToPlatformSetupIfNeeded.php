<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToPlatformSetupIfNeeded
{
    /**
     * Lock a super admin onto /setup/platform until the platform connection is
     * configured (i.e. at least one workspace has been synced from Chatwoot).
     * Without this, a super admin could navigate to /admin while no workspace
     * exists and hit a broken panel.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user instanceof User
            && $user->isSuperAdmin()
            && ! $request->routeIs('setup.*')
            && ! Workspace::query()->exists()
        ) {
            return redirect()->route('setup.platform.show');
        }

        return $next($request);
    }
}
