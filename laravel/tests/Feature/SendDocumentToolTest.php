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
        ->assertJsonValidationErrors(['agent_run_id', 'document_ids', 'document_type', 'conversation_id']);
});

it('rejects an invalid document_type', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    postJson('/api/internal/agent-tools/send-document', [
        'workspace_id' => 1,
        'agent_run_id' => 1,
        'document_ids' => [1],
        'document_type' => 'banana',
        'conversation_id' => 1,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_type']);
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
        'document_ids' => [99999],
        'document_type' => 'standalone',
        'caption' => 'Test',
        'conversation_id' => 123,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_ids']);
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
        '*' => Http::response(['id' => 1], 200),
    ]);

    $result = (new SendDocument)->execute([
        'workspace_id' => $workspace->id,
        'agent_run_id' => $run->id,
        'document_ids' => [$productDoc->id],
        'document_type' => 'product',
        'caption' => 'Aqui esta o catalogo',
        'conversation_id' => 123,
    ]);

    expect($result['sent'])->toBeTrue();
    expect($result['filenames'])->toBe(['catalogo.pdf']);
    expect($result['count'])->toBe(1);

    // Chatwoot rejects a multipart message when `private` serializes to a bool
    // false (NOT NULL violation). It must go out as a string form field.
    Http::assertSent(function ($request): bool {
        $body = (string) $request->body();

        return str_contains($body, 'name="private"')
            && str_contains($body, "\r\nfalse\r\n")
            && str_contains($body, 'name="attachments[]"');
    });
});

it('sends multiple documents as a single message with multiple attachments', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Storage::fake('s3');

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $product = Product::factory()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    $first = ProductDocument::factory()->for($workspace)->for($product)->create([
        'path' => 'documents/a.jpg',
        'original_filename' => 'foto-1.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    $second = ProductDocument::factory()->for($workspace)->for($product)->create([
        'path' => 'documents/b.jpg',
        'original_filename' => 'foto-2.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    Storage::disk('s3')->put('documents/a.jpg', 'bytes-a');
    Storage::disk('s3')->put('documents/b.jpg', 'bytes-b');

    Http::fake(['*' => Http::response(['id' => 1], 200)]);

    $result = (new SendDocument)->execute([
        'workspace_id' => $workspace->id,
        'agent_run_id' => $run->id,
        'document_ids' => [$first->id, $second->id],
        'document_type' => 'product',
        'caption' => 'Fotos da bike',
        'conversation_id' => 123,
    ]);

    expect($result['sent'])->toBeTrue();
    expect($result['count'])->toBe(2);
    expect($result['filenames'])->toBe(['foto-1.jpg', 'foto-2.jpg']);

    // With multiple attachments the caption goes once as a separate text message,
    // then the gallery is sent without a per-image caption (avoids repeating the
    // text on every photo on WhatsApp).
    Http::assertSentCount(2);

    // 1) caption as a plain text message (JSON body, no attachments).
    Http::assertSent(function ($request): bool {
        $body = (string) $request->body();

        return ! str_contains($body, 'attachments[]')
            && ($request['content'] ?? null) === 'Fotos da bike';
    });

    // 2) gallery: two attachments[] parts, no repeated caption.
    Http::assertSent(function ($request): bool {
        $body = (string) $request->body();

        return substr_count($body, 'name="attachments[]"') === 2
            && str_contains($body, 'foto-1.jpg')
            && str_contains($body, 'foto-2.jpg')
            && ! str_contains($body, 'Fotos da bike');
    });
});

it('keeps the caption on the message when a single document is sent', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Storage::fake('s3');

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $product = Product::factory()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    $doc = ProductDocument::factory()->for($workspace)->for($product)->create([
        'path' => 'documents/solo.jpg',
        'original_filename' => 'solo.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    Storage::disk('s3')->put('documents/solo.jpg', 'bytes');

    Http::fake(['*' => Http::response(['id' => 1], 200)]);

    (new SendDocument)->execute([
        'workspace_id' => $workspace->id,
        'agent_run_id' => $run->id,
        'document_ids' => [$doc->id],
        'document_type' => 'product',
        'caption' => 'Olha essa bike',
        'conversation_id' => 123,
    ]);

    // Single attachment: caption rides on the media message, no extra text message.
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_contains((string) $request->body(), 'Olha essa bike')
        && str_contains((string) $request->body(), 'name="attachments[]"'));
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
        '*' => Http::response(['id' => 2], 200),
    ]);

    $result = (new SendDocument)->execute([
        'workspace_id' => $workspace->id,
        'agent_run_id' => $run->id,
        'document_ids' => [$doc->id],
        'document_type' => 'standalone',
        'caption' => 'Termos de uso',
        'conversation_id' => 456,
    ]);

    expect($result['sent'])->toBeTrue();
    expect($result['filenames'])->toBe(['termos-de-uso.pdf']);
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
        'document_ids' => [$doc->id],
        'document_type' => 'standalone',
        'caption' => 'Not my doc',
        'conversation_id' => 123,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_ids']);
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
        'document_ids' => [$doc->id],
        'document_type' => 'standalone',
        'caption' => 'Missing',
        'conversation_id' => 123,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_ids']);
});

it('resolves the correct table when both tables share the same id', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Storage::fake('s3');

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $product = Product::factory()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    // Force a shared id explicitly. Relying on autoincrement starting at 1 is not
    // portable: Postgres sequences are not rolled back between tests (unlike SQLite
    // rowids), so the two tables drift apart across the suite.
    $sharedId = 90001;
    $productDoc = ProductDocument::factory()->for($workspace)->for($product)->create([
        'id' => $sharedId,
        'path' => 'documents/product.pdf',
        'original_filename' => 'produto.pdf',
    ]);
    $standaloneDoc = Document::factory()->for($workspace)->create([
        'id' => $sharedId,
        'category' => 'catalog',
        'path' => 'documents/standalone.pdf',
        'original_filename' => 'avulso.pdf',
    ]);

    // Both rows now share the same id across the two tables.
    expect($productDoc->id)->toBe($standaloneDoc->id);

    Storage::disk('s3')->put('documents/product.pdf', 'product-bytes');
    Storage::disk('s3')->put('documents/standalone.pdf', 'standalone-bytes');

    Http::fake(['*' => Http::response(['id' => 9], 200)]);

    $standaloneResult = (new SendDocument)->execute([
        'workspace_id' => $workspace->id,
        'agent_run_id' => $run->id,
        'document_ids' => [$standaloneDoc->id],
        'document_type' => 'standalone',
        'conversation_id' => 123,
    ]);

    $productResult = (new SendDocument)->execute([
        'workspace_id' => $workspace->id,
        'agent_run_id' => $run->id,
        'document_ids' => [$productDoc->id],
        'document_type' => 'product',
        'conversation_id' => 123,
    ]);

    expect($standaloneResult['filenames'])->toBe(['avulso.pdf']);
    expect($productResult['filenames'])->toBe(['produto.pdf']);
});

it('rejects sending a knowledge-only standalone document', function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Storage::fake('s3');

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
    ]);

    $doc = Document::factory()->for($workspace)->create([
        'category' => 'knowledge',
        'path' => 'documents/internal.pdf',
        'original_filename' => 'manual-interno.pdf',
    ]);

    Storage::disk('s3')->put('documents/internal.pdf', 'secret-bytes');

    postJson('/api/internal/agent-tools/send-document', [
        'workspace_id' => $workspace->id,
        'agent_run_id' => $run->id,
        'document_ids' => [$doc->id],
        'document_type' => 'standalone',
        'conversation_id' => 123,
    ], ['X-Internal-Token' => 'ci-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_ids']);
});
