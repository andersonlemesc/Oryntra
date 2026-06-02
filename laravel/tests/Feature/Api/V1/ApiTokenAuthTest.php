<?php

declare(strict_types=1);

use App\Actions\Api\IssueApiToken;
use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace, 2: string}
 */
function issueToken(array $abilities, ?Workspace $workspace = null): array
{
    $user = User::factory()->create();
    $workspace ??= Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);

    $token = app(IssueApiToken::class)->execute($user, $workspace, 'test', $abilities);

    return [$user, $workspace, $token->plainTextToken];
}

/**
 * @return array<string, string>
 */
function bearer(string $token): array
{
    return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
}

it('rejects requests without a token', function () {
    getJson('/api/v1/me')->assertUnauthorized();
});

it('returns the workspace and abilities for a valid token', function () {
    [$user, $workspace, $token] = issueToken(['agent:read']);

    getJson('/api/v1/me', bearer($token))
        ->assertOk()
        ->assertJsonPath('data.workspace.id', $workspace->id)
        ->assertJsonPath('data.token.abilities', ['agent:read']);
});

it('blocks an action the token ability does not grant', function () {
    [, , $token] = issueToken(['agent:read']);

    postJson('/api/v1/agents', ['name' => 'X', 'mode' => 'single'], bearer($token))
        ->assertForbidden();
});

it('creates a single-mode agent and auto-creates its specialist', function () {
    [, $workspace, $token] = issueToken(['agent:read', 'agent:write', 'specialist:read']);

    $response = postJson('/api/v1/agents', ['name' => 'Bot', 'mode' => 'single'], bearer($token))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Bot')
        ->assertJsonPath('data.specialists_count', 1);

    $agentId = $response->json('data.id');

    expect(Agent::query()->find($agentId)?->specialists()->count())->toBe(1);
});

it('never exposes resources from another workspace', function () {
    [, $workspaceA, $tokenA] = issueToken(['agent:read', 'agent:write']);
    [, $workspaceB] = issueToken(['agent:read']);

    $foreign = Agent::factory()->create(['workspace_id' => $workspaceB->id]);

    getJson("/api/v1/agents/{$foreign->id}", bearer($tokenA))->assertNotFound();
});

it('validates required fields', function () {
    [, , $token] = issueToken(['agent:write']);

    postJson('/api/v1/agents', ['name' => 'NoMode'], bearer($token))
        ->assertStatus(422)
        ->assertJsonValidationErrors('mode');
});
