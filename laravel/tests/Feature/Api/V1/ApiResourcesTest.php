<?php

declare(strict_types=1);

use App\Actions\Api\IssueApiToken;
use App\Models\AgentDocument;
use App\Models\ExternalTool;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @return array{0: Workspace, 1: array<string, string>}
 */
function workspaceToken(array $abilities): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);
    $token = app(IssueApiToken::class)->execute($user, $workspace, 'test', $abilities);

    return [$workspace, ['Authorization' => 'Bearer '.$token->plainTextToken, 'Accept' => 'application/json']];
}

it('creates a category with an auto-generated slug', function () {
    [, $headers] = workspaceToken(['category:write']);

    postJson('/api/v1/categories', ['name' => 'Eletrônicos'], $headers)
        ->assertCreated()
        ->assertJsonPath('data.slug', 'eletronicos');
});

it('filters products by search and category', function () {
    [$workspace, $headers] = workspaceToken(['product:read', 'product:write', 'category:write']);

    postJson('/api/v1/products', ['name' => 'Mouse Gamer', 'price' => 100], $headers)->assertCreated();
    postJson('/api/v1/products', ['name' => 'Teclado', 'price' => 200], $headers)->assertCreated();

    getJson('/api/v1/products?search=mouse', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Mouse Gamer');
});

it('ingests knowledge from inline text and queues indexing', function () {
    Queue::fake();
    [$workspace, $headers] = workspaceToken(['knowledge:write']);

    postJson('/api/v1/knowledge-documents/from-text', [
        'name' => 'Política',
        'content' => "# Trocas\nEm até 30 dias.",
    ], $headers)
        ->assertCreated()
        ->assertJsonPath('data.index_status', 'pending');

    expect(AgentDocument::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('stores external tool secrets encrypted and never returns them', function () {
    [$workspace, $headers] = workspaceToken(['tool:read', 'tool:write']);

    $response = postJson('/api/v1/mcp-servers', [
        'slug' => 'n8n_test',
        'label' => 'N8N',
        'config' => ['base_url' => 'https://x.test/mcp', 'auth_type' => 'bearer'],
        'secret' => ['token' => 'supersecret'],
    ], $headers)->assertCreated();

    $response->assertJsonPath('data.has_credentials', true)
        ->assertJsonMissingPath('data.credentials')
        ->assertJsonMissingPath('data.secret');

    $tool = ExternalTool::query()->where('slug', 'n8n_test')->first();
    expect($tool?->credentials)->toBe(['token' => 'supersecret'])
        ->and((string) $tool?->getRawOriginal('credentials'))->not->toContain('supersecret');
});

it('scopes deletes to the token workspace', function () {
    [, $headers] = workspaceToken(['tool:write']);
    $otherWorkspace = Workspace::factory()->create();
    $foreign = ExternalTool::factory()->mcp()->create(['workspace_id' => $otherWorkspace->id]);

    deleteJson("/api/v1/mcp-servers/{$foreign->id}", [], $headers)->assertNotFound();
    expect(ExternalTool::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

it('creates a specialist and serializes its default status', function () {
    [, $headers] = workspaceToken(['agent:write', 'specialist:write']);

    $agentId = postJson('/api/v1/agents', ['name' => 'Suporte', 'mode' => 'single'], $headers)
        ->assertCreated()
        ->json('data.id');

    postJson("/api/v1/agents/{$agentId}/specialists", [
        'name' => 'Cobranças',
        'role_prompt' => 'Você cuida de cobranças.',
    ], $headers)
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.name', 'Cobranças');
});

it('rejects a duplicate specialist name within the same agent', function () {
    [, $headers] = workspaceToken(['agent:write', 'specialist:write']);

    $agentId = postJson('/api/v1/agents', ['name' => 'Vendas', 'mode' => 'single'], $headers)
        ->assertCreated()
        ->json('data.id');

    $payload = ['name' => 'Repetido', 'role_prompt' => 'Prompt.'];

    postJson("/api/v1/agents/{$agentId}/specialists", $payload, $headers)->assertCreated();
    postJson("/api/v1/agents/{$agentId}/specialists", $payload, $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});
