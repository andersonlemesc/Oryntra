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
        // Only workspace managers (owner/admin, or super admin) may issue API
        // tokens at all. Agents/viewers neither write via the API nor need to
        // generate tokens, so any issuance from them is rejected.
        if (! $user->canManageWorkspace($workspace)) {
            throw ValidationException::withMessages([
                'workspace_id' => 'Apenas administradores do workspace podem gerar tokens de API.',
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
