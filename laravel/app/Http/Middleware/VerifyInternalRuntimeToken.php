<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalRuntimeToken
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config('services.agent_runtime.internal_token');
        $providedToken = (string) $request->header('X-Internal-Token');

        if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
