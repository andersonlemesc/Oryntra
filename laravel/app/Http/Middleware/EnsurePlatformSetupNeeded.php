<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformSetupNeeded
{
    /**
     * Gate the /setup/platform route:
     * - User must be authenticated and a super admin.
     * - At least one workspace must already exist => redirect to /admin (setup done).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            abort(404);
        }

        if (Workspace::query()->exists()) {
            return redirect('/admin');
        }

        return $next($request);
    }
}
