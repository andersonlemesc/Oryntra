<?php

declare(strict_types=1);

use App\Models\Agent;
use App\Models\AgentDocument;
use App\Models\AgentLlmKey;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\DocumentChunk;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function fakeEmbedQuery(array $vector): void
{
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Http::fake([
        '*/internal/rag/embed-query' => Http::response([
            'vector' => $vector,
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dim' => count($vector),
        ]),
    ]);
}

/**
 * @return array{workspace: Workspace, agent: Agent, run: AgentRun, specialist: AgentSpecialist}
 */
function knowledgeGraph(array $allowlist = ['search_knowledge_base']): array
{
    $workspace = Workspace::factory()->create();
    $key = AgentLlmKey::factory()->create(['workspace_id' => $workspace->id]);
    $workspace->update([
        'embedding_llm_key_id' => $key->id,
        'embedding_model' => 'text-embedding-3-small',
        'embedding_dim' => 3,
    ]);
    $agent = Agent::factory()->active()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()->for($workspace)->for($agent)
        ->create(['tools_allowlist' => $allowlist]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
    ]);

    return ['workspace' => $workspace, 'agent' => $agent, 'run' => $run, 'specialist' => $specialist];
}

function seedChunk(Workspace $workspace, array $embedding, string $content): DocumentChunk
{
    $document = AgentDocument::factory()->indexed()->create([
        'workspace_id' => $workspace->id,
        'embedding_model' => 'text-embedding-3-small',
        'embedding_dim' => 3,
    ]);

    return DocumentChunk::factory()->for($document, 'agentDocument')->create([
        'workspace_id' => $workspace->id,
        'embedding_model' => 'text-embedding-3-small',
        'embedding_dim' => 3,
        'embedding' => $embedding,
        'content' => $content,
    ]);
}

it('rejects search without the internal runtime token', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    postJson('/api/internal/agent-tools/search-knowledge-base', [])
        ->assertForbidden();
});

it('validates that a query is required', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    $graph = knowledgeGraph();

    postJson('/api/internal/agent-tools/search-knowledge-base', [
        'workspace_id' => $graph['workspace']->id,
        'agent_id' => $graph['agent']->id,
        'agent_run_id' => $graph['run']->id,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('query');
});

it('ranks chunks by cosine similarity scoped to the workspace', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    fakeEmbedQuery([1.0, 0.0, 0.0]);

    $graph = knowledgeGraph();
    seedChunk($graph['workspace'], [0.9, 0.1, 0.0], 'most relevant');
    seedChunk($graph['workspace'], [0.0, 1.0, 0.0], 'least relevant');

    $other = Workspace::factory()->create();
    seedChunk($other, [1.0, 0.0, 0.0], 'other workspace chunk');

    $response = postJson('/api/internal/agent-tools/search-knowledge-base', [
        'workspace_id' => $graph['workspace']->id,
        'agent_id' => $graph['agent']->id,
        'agent_run_id' => $graph['run']->id,
        'specialist_id' => $graph['specialist']->id,
        'query' => 'what is most relevant',
        'top_k' => 5,
    ], ['X-Internal-Token' => 'ci-token'])->assertOk();

    $hits = $response->json('hits');
    expect($hits)->toHaveCount(2)
        ->and($hits[0]['content'])->toBe('most relevant')
        ->and($hits[0]['score'])->toBeGreaterThan($hits[1]['score']);
});

it('drops chunks below min_score', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    fakeEmbedQuery([1.0, 0.0, 0.0]);

    $graph = knowledgeGraph();
    seedChunk($graph['workspace'], [1.0, 0.0, 0.0], 'aligned');
    seedChunk($graph['workspace'], [0.0, 1.0, 0.0], 'orthogonal');

    $response = postJson('/api/internal/agent-tools/search-knowledge-base', [
        'workspace_id' => $graph['workspace']->id,
        'agent_id' => $graph['agent']->id,
        'agent_run_id' => $graph['run']->id,
        'query' => 'aligned content',
        'min_score' => 0.5,
    ], ['X-Internal-Token' => 'ci-token'])->assertOk();

    $hits = $response->json('hits');
    expect($hits)->toHaveCount(1)
        ->and($hits[0]['content'])->toBe('aligned');
});

it('scopes knowledge search to the agent (own + global, never another agents docs)', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    fakeEmbedQuery([1.0, 0.0, 0.0]);

    $graph = knowledgeGraph();
    $otherAgent = Agent::factory()->for($graph['workspace'])->create();

    $own = seedChunk($graph['workspace'], [1.0, 0.0, 0.0], 'own doc');
    $other = seedChunk($graph['workspace'], [1.0, 0.0, 0.0], 'other agent doc');
    seedChunk($graph['workspace'], [1.0, 0.0, 0.0], 'global doc');

    $own->agentDocument->agents()->sync([$graph['agent']->id]);
    $other->agentDocument->agents()->sync([$otherAgent->id]);

    $response = postJson('/api/internal/agent-tools/search-knowledge-base', [
        'workspace_id' => $graph['workspace']->id,
        'agent_id' => $graph['agent']->id,
        'agent_run_id' => $graph['run']->id,
        'query' => 'anything',
        'top_k' => 10,
    ], ['X-Internal-Token' => 'ci-token'])->assertOk();

    $contents = array_map(fn (array $hit): string => $hit['content'], $response->json('hits'));

    expect($contents)->toContain('own doc')
        ->toContain('global doc')
        ->not->toContain('other agent doc');
});

it('rejects when the specialist allowlist excludes the tool', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    fakeEmbedQuery([1.0, 0.0, 0.0]);

    $graph = knowledgeGraph(allowlist: []);

    postJson('/api/internal/agent-tools/search-knowledge-base', [
        'workspace_id' => $graph['workspace']->id,
        'agent_id' => $graph['agent']->id,
        'agent_run_id' => $graph['run']->id,
        'specialist_id' => $graph['specialist']->id,
        'query' => 'anything',
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('specialist_id');
});
