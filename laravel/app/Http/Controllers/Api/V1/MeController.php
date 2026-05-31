<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Connection check: returns the workspace and abilities the current token
 * grants. Used by the MCP package to validate URL + token on startup.
 */
class MeController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $workspace = $this->workspace();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user?->getKey(),
                    'name' => $user?->name,
                    'email' => $user?->email,
                ],
                'workspace' => [
                    'id' => $workspace->getKey(),
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                ],
                'token' => [
                    'name' => $token?->name,
                    'abilities' => $token->abilities ?? [],
                ],
            ],
        ]);
    }
}
