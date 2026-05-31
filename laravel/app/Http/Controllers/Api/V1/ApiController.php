<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Support\WorkspaceContext;

/**
 * Base controller for the public /api/v1 surface. Exposes the workspace the
 * authenticated token is scoped to and a normalized per-page resolver.
 */
abstract class ApiController extends Controller
{
    public function __construct(protected readonly WorkspaceContext $workspaceContext) {}

    protected function workspace(): Workspace
    {
        return $this->workspaceContext->get();
    }

    protected function workspaceId(): int
    {
        return $this->workspaceContext->id();
    }

    /**
     * Clamp the requested page size to a sane range (default 20, max 100).
     */
    protected function perPage(?int $requested): int
    {
        if ($requested === null) {
            return 20;
        }

        return max(1, min(100, $requested));
    }

    /**
     * Resolve a model by key within the active workspace or abort 404. Keeps
     * cross-workspace access impossible regardless of the id supplied.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel> $model
     * @return TModel
     */
    protected function findInWorkspace(string $model, int|string $id)
    {
        /** @var TModel */
        return $model::query()
            ->where('workspace_id', $this->workspaceId())
            ->findOrFail($id);
    }
}
