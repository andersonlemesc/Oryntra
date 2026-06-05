<?php

declare(strict_types=1);

use App\Enums\AgentDocumentStatus;
use App\Jobs\Rag\IndexKnowledgeDocumentJob;
use App\Models\AgentDocument;
use App\Models\AgentLlmKey;
use App\Models\Workspace;
use App\Services\AgentRuntime\AgentRuntimeClient;
use App\Services\AgentRuntime\AgentRuntimeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function configureRuntime(): void
{
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');
}

function knowledgeDocument(): AgentDocument
{
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration): string => 'http://minio:9000/' . $path
    );

    $workspace = Workspace::factory()->create();
    $key = AgentLlmKey::factory()->create(['workspace_id' => $workspace->id]);
    $workspace->update([
        'embedding_llm_key_id' => $key->id,
        'embedding_model' => 'text-embedding-3-small',
        'embedding_dim' => 1536,
    ]);

    $document = AgentDocument::factory()->create([
        'workspace_id' => $workspace->id,
        'storage_path' => 'workspaces/' . $workspace->id . '/knowledge/doc.md',
        'index_status' => AgentDocumentStatus::Pending,
    ]);
    Storage::disk('s3')->put($document->storage_path, "# Title\n\nbody");

    return $document;
}

it('indexes a knowledge document into chunks', function () {
    configureRuntime();
    $document = knowledgeDocument();

    Http::fake([
        '*/internal/rag/ingest' => Http::response([
            'chunks' => [
                ['index' => 0, 'content' => 'first chunk', 'tokens' => 12, 'metadata' => ['page' => 1]],
                ['index' => 1, 'content' => 'second chunk', 'tokens' => 8, 'metadata' => []],
            ],
            'vectors' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]],
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dim' => 3,
            'usage' => ['tokens' => 20],
        ]),
    ]);

    (new IndexKnowledgeDocumentJob($document->id))->handle(app(AgentRuntimeClient::class));

    $document->refresh();

    expect($document->index_status)->toBe(AgentDocumentStatus::Indexed)
        ->and($document->chunks_count)->toBe(2)
        ->and($document->embedding_model)->toBe('text-embedding-3-small')
        ->and($document->indexed_at)->not->toBeNull()
        ->and($document->chunks()->count())->toBe(2);

    $first = $document->chunks()->where('chunk_index', 0)->firstOrFail();
    expect($first->embedding)->toBe([0.1, 0.2, 0.3])
        ->and($first->content)->toBe('first chunk');
});

it('marks the document failed when ingest fails', function () {
    configureRuntime();
    $document = knowledgeDocument();

    Http::fake([
        '*/internal/rag/ingest' => Http::response(['error' => 'boom'], 500),
    ]);

    $job = new IndexKnowledgeDocumentJob($document->id);

    expect(fn () => $job->handle(app(AgentRuntimeClient::class)))
        ->toThrow(AgentRuntimeException::class);

    $job->failed(new AgentRuntimeException('ingest failed'));

    expect($document->refresh()->index_status)->toBe(AgentDocumentStatus::Failed)
        ->and($document->index_error)->toContain('ingest failed');
});

it('reindexes by replacing existing chunks', function () {
    configureRuntime();
    $document = knowledgeDocument();

    $payload = [
        'chunks' => [['index' => 0, 'content' => 'only chunk', 'tokens' => 5, 'metadata' => []]],
        'vectors' => [[0.9, 0.8, 0.7]],
        'embedding_provider' => 'openai',
        'embedding_model' => 'text-embedding-3-small',
        'embedding_dim' => 3,
        'usage' => [],
    ];
    Http::fake(['*/internal/rag/ingest' => Http::response($payload)]);

    (new IndexKnowledgeDocumentJob($document->id))->handle(app(AgentRuntimeClient::class));
    (new IndexKnowledgeDocumentJob($document->id))->handle(app(AgentRuntimeClient::class));

    expect($document->refresh()->chunks()->count())->toBe(1);
});
