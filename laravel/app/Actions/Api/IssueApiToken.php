<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Models\User;
use App\Models\Workspace;
use App\Support\ApiTokenAbilities;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

class IssueApiToken
{
    /**
     * Issue a personal access token scoped to a single workspace.
     *
     * @param array<int, string> $abilities
     *
     * @throws ValidationException
     */
    public function execute(User $user, Workspace $workspace, string $name, array $abilities): NewAccessToken
    {
        if (! $user->canAccessTenant($workspace)) {
            throw ValidationException::withMessages([
                'workspace_id' => 'Você não tem acesso a este workspace.',
            ]);
        }

        $invalid = array_diff($abilities, ApiTokenAbilities::all());

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'abilities' => 'Permissões inválidas: ' . implode(', ', $invalid),
            ]);
        }

        $newToken = $user->createToken($name, $abilities);

        $newToken->accessToken->forceFill(['workspace_id' => $workspace->getKey()])->save();

        return $newToken;
    }
}
