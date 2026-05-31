<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Workspace;
use RuntimeException;

/**
 * Holds the workspace resolved for the current API request. Registered as a
 * scoped singleton so Actions and controllers can read the active tenant
 * without threading it through every method signature.
 */
class WorkspaceContext
{
    private ?Workspace $workspace = null;

    public function set(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function get(): Workspace
    {
        if (! $this->workspace instanceof Workspace) {
            throw new RuntimeException('No workspace resolved for the current request.');
        }

        return $this->workspace;
    }

    public function id(): int
    {
        return (int) $this->get()->getKey();
    }

    public function has(): bool
    {
        return $this->workspace instanceof Workspace;
    }
}
