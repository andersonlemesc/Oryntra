<?php

declare(strict_types=1);

use App\Actions\AgentTools\SendDocument;
use App\Models\AgentRun;
use App\Models\ChatwootConnection;
use App\Models\Document;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('rejects send_document without the internal runtime token', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    postJson('/api/internal/agent-tools/send-document', [])
        ->assertForbidden();
});

it('validates required fields', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    postJson('/api/internal/agent-tools/send-document', [
        'workspace_id' => 1,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['agent_run_id', 'document_id', 'conversation_id']);
});

it('returns error when document does not exist', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    postJson('/api/internal/agent-tools/send-document', [
        'workspace_id' => $workspace->id,
        'agent_run_id' => $run->id,
        'document_id' => 99999,
        'caption' => 'Test',
        'conversation_id' => 123,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_id']);
});

it('returns sent=true when product document is sent successfully', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Storage::fake('s3');

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $product = Product::factory()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    $productDoc = ProductDocument::factory()->for($workspace)->for($product)->create([
        'path' => 'documents/test.pdf',
        'original_filename' => 'catalogo.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('s3')->put('documents/test.pdf', 'fake-pdf-content');

    Http::fake([
        '*' => Http::response(['message' => ['id' => 1]], 200),
    ]);

    $result = (new SendDocument)->execute([
        'workspace_id' => $workspace->id,
        'agent_run_id' => $run->id,
        'document_id' => $productDoc->id,
        'caption' => 'Aqui esta o catalogo',
        'conversation_id' => 123,
    ]);

    expect($result['sent'])->toBeTrue();
    expect($result['filename'])->toBe('catalogo.pdf');
});

it('returns sent=true when standalone document is sent successfully', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Storage::fake('s3');

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    $doc = Document::factory()->for($workspace)->create([
        'path' => 'docs/terms.pdf',
        'original_filename' => 'termos-de-uso.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('s3')->put('docs/terms.pdf', 'fake-pdf-content');

    Http::fake([
        '*' => Http::response(['message' => ['id' => 2]], 200),
    ]);

    $result = (new SendDocument)->execute([
        'workspace_id' => $workspace->id,
        'agent_run_id' => $run->id,
        'document_id' => $doc->id,
        'caption' => 'Termos de uso',
        'conversation_id' => 456,
    ]);

    expect($result['sent'])->toBeTrue();
    expect($result['filename'])->toBe('termos-de-uso.pdf');
});

it('returns error when document not in workspace', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    $doc = Document::factory()->for($otherWorkspace)->create();

    postJson('/api/internal/agent-tools/send-document', [
        'workspace_id' => $workspace->id,
        'agent_run_id' => $run->id,
        'document_id' => $doc->id,
        'caption' => 'Not my doc',
        'conversation_id' => 123,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_id']);
});

it('returns error when file not found in storage', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Storage::fake('s3');

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    $doc = Document::factory()->for($workspace)->create([
        'path' => 'documents/missing.pdf',
    ]);

    postJson('/api/internal/agent-tools/send-document', [
        'workspace_id' => $workspace->id,
        'agent_run_id' => $run->id,
        'document_id' => $doc->id,
        'caption' => 'Missing',
        'conversation_id' => 123,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_id']);
});
