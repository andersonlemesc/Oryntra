<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesWorkspaceRoles
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        $workspaceId = $this->currentWorkspaceId();

        return $workspaceId !== null && $user->canViewWorkspace($workspaceId);
    }

    public function view(User $user, Model $record): bool
    {
        $workspaceId = $this->recordWorkspaceId($record);

        return $workspaceId !== null && $user->canViewWorkspace($workspaceId);
    }

    public function create(User $user): bool
    {
        $workspaceId = $this->currentWorkspaceId();

        return $workspaceId !== null && $user->canManageWorkspace($workspaceId);
    }

    public function update(User $user, Model $record): bool
    {
        $workspaceId = $this->recordWorkspaceId($record);

        return $workspaceId !== null && $user->canManageWorkspace($workspaceId);
    }

    public function delete(User $user, Model $record): bool
    {
        $workspaceId = $this->recordWorkspaceId($record);

        return $workspaceId !== null && $user->canManageWorkspace($workspaceId);
    }

    public function deleteAny(User $user): bool
    {
        $workspaceId = $this->currentWorkspaceId();

        return $workspaceId !== null && $user->canManageWorkspace($workspaceId);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->delete($user, $record);
    }

    public function restoreAny(User $user): bool
    {
        return $this->deleteAny($user);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->delete($user, $record);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->deleteAny($user);
    }

    public function replicate(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    public function reorder(User $user): bool
    {
        return $this->deleteAny($user);
    }

    private function currentWorkspaceId(): ?int
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Model && is_numeric($tenant->getKey())
            ? (int) $tenant->getKey()
            : null;
    }

    private function recordWorkspaceId(Model $record): ?int
    {
        $workspaceId = $record->getAttribute('workspace_id');

        return is_numeric($workspaceId) ? (int) $workspaceId : null;
    }
}
