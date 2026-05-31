<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the workspace bound to the authenticated API token and makes it
 * available to the rest of the request. Rejects tokens whose user no longer
 * belongs to the workspace.
 */
class ResolveApiWorkspace
{
    public function __construct(private readonly WorkspaceContext $context) {}

    /**
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user instanceof User || ! $token instanceof ApiToken || $token->workspace_id === null) {
            abort(Response::HTTP_FORBIDDEN, 'Token não vinculado a um workspace.');
        }

        $workspace = Workspace::query()->find($token->workspace_id);

        if (! $workspace instanceof Workspace || ! $user->canAccessTenant($workspace)) {
            abort(Response::HTTP_FORBIDDEN, 'Acesso ao workspace revogado.');
        }

        $this->context->set($workspace);
        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }
}
