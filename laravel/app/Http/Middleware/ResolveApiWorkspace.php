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
 * belongs to the workspace, and blocks writes when the user lacks an active
 * management role (e.g. an admin demoted to agent after the token was issued).
 */
class ResolveApiWorkspace
{
    /**
     * HTTP methods that mutate state and therefore require a management role.
     *
     * @var list<string>
     */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

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

        // Re-check the management role on every mutating request. A token minted
        // while the user was an admin must stop writing once the user is demoted
        // to agent/viewer — the token's stored abilities are not enough alone.
        if (in_array($request->getMethod(), self::WRITE_METHODS, true) && ! $user->canManageWorkspace($workspace)) {
            abort(Response::HTTP_FORBIDDEN, 'Operação de escrita requer perfil de administrador no workspace.');
        }

        $this->context->set($workspace);
        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }
}
