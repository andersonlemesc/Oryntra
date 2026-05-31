<?php

declare(strict_types=1);

use App\Enums\AgentDocumentStatus;
use App\Models\AgentDocument;
use App\Models\DocumentChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates the rag tables', function () {
    expect(Schema::hasTable('agent_documents'))->toBeTrue();
    expect(Schema::hasTable('document_chunks'))->toBeTrue();
    expect(Schema::hasColumns('agent_documents', [
        'workspace_id', 'name', 'mime_type', 'index_status', 'chunks_count',
        'embedding_provider', 'embedding_model', 'embedding_dim',
    ]))->toBeTrue();
    expect(Schema::hasColumns('document_chunks', [
        'workspace_id', 'agent_document_id', 'chunk_index', 'content',
        'embedding_model', 'embedding_dim', 'embedding',
    ]))->toBeTrue();
});

it('persists an agent document with the status enum cast', function () {
    $document = AgentDocument::factory()->indexed()->create();

    expect($document->refresh()->index_status)->toBe(AgentDocumentStatus::Indexed)
        ->and($document->embedding_dim)->toBe(1536)
        ->and($document->chunks_count)->toBeGreaterThan(0);
});

it('round-trips the embedding vector through the cast', function () {
    $document = AgentDocument::factory()->create();

    $chunk = DocumentChunk::factory()->for($document, 'agentDocument')->create([
        'workspace_id' => $document->workspace_id,
        'embedding' => [0.1, -0.2, 0.3],
        'embedding_dim' => 3,
    ]);

    $reloaded = DocumentChunk::query()->findOrFail($chunk->id);

    expect($reloaded->embedding)->toBe([0.1, -0.2, 0.3])
        ->and($reloaded->agentDocument->is($document))->toBeTrue();
});

it('scopes chunks to their document', function () {
    $document = AgentDocument::factory()->create();
    DocumentChunk::factory()->count(3)->for($document, 'agentDocument')->create([
        'workspace_id' => $document->workspace_id,
    ]);

    expect($document->chunks()->count())->toBe(3);
});
