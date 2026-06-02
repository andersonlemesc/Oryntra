<?php

declare(strict_types=1);

use App\Actions\Api\IssueApiToken;
use App\Models\ChatwootConnection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @return array{0: Workspace, 1: array<string, string>}
 */
function lookupToken(array $abilities): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);
    $token = app(IssueApiToken::class)->execute($user, $workspace, 'lookup', $abilities);

    return [$workspace, ['Authorization' => 'Bearer ' . $token->plainTextToken, 'Accept' => 'application/json']];
}

it('lists workspace-scoped chatwoot teams', function () {
    [$workspace, $headers] = lookupToken(['specialist:read']);
    $other = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->create(['workspace_id' => $workspace->id]);
    $otherConnection = ChatwootConnection::factory()->create(['workspace_id' => $other->id]);

    DB::table('chatwoot_teams')->insert([
        ['workspace_id' => $workspace->id, 'chatwoot_connection_id' => $connection->id, 'chatwoot_team_id' => 7, 'name' => 'Vendas', 'created_at' => now(), 'updated_at' => now()],
        ['workspace_id' => $other->id, 'chatwoot_connection_id' => $otherConnection->id, 'chatwoot_team_id' => 9, 'name' => 'Outro', 'created_at' => now(), 'updated_at' => now()],
    ]);

    getJson('/api/v1/lookups/chatwoot/teams', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.team_id', 7)
        ->assertJsonPath('data.0.name', 'Vendas');
});

it('requires specialist:read for lookups', function () {
    [, $headers] = lookupToken(['agent:read']);

    getJson('/api/v1/lookups/chatwoot/teams', $headers)->assertForbidden();
});
