<?php

declare(strict_types=1);

use App\Actions\Api\IssueApiToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace, 2: string}
 */
function issueAdminToken(array $abilities): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'admin']);

    $token = app(IssueApiToken::class)->execute($user, $workspace, 'test', $abilities);

    return [$user, $workspace, $token->plainTextToken];
}

/**
 * @return array<string, string>
 */
function bearerHeader(string $token): array
{
    return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
}

it('blocks API writes once the token owner is demoted to agent', function () {
    [$user, $workspace, $token] = issueAdminToken(['agent:read', 'agent:write']);

    // Token was minted while admin; user is later demoted to agent (member).
    $workspace->users()->updateExistingPivot($user->id, ['role' => 'member']);

    postJson('/api/v1/agents', ['name' => 'X', 'mode' => 'single'], bearerHeader($token))
        ->assertForbidden();

    deleteJson('/api/v1/agents/1', [], bearerHeader($token))
        ->assertForbidden();
});

it('still allows API reads for a demoted agent token', function () {
    [$user, $workspace, $token] = issueAdminToken(['agent:read', 'agent:write']);

    $workspace->users()->updateExistingPivot($user->id, ['role' => 'member']);

    getJson('/api/v1/me', bearerHeader($token))->assertOk();
    getJson('/api/v1/agents', bearerHeader($token))->assertOk();
});

it('keeps writes working while the user remains an admin', function () {
    [, , $token] = issueAdminToken(['agent:read', 'agent:write', 'specialist:read']);

    postJson('/api/v1/agents', ['name' => 'Bot', 'mode' => 'single'], bearerHeader($token))
        ->assertCreated();
});
