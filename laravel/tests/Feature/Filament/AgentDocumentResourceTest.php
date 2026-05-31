<?php

declare(strict_types=1);

use App\Enums\AgentDocumentStatus;
use App\Filament\Resources\AgentDocuments\Pages\CreateAgentDocument;
use App\Jobs\Rag\IndexKnowledgeDocumentJob;
use App\Models\AgentDocument;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function bootKnowledgeTenant(): Workspace
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);

    actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($workspace);
    Filament::bootCurrentPanel();

    return $workspace;
}

it('uploads a knowledge document and queues indexing', function (): void {
    Queue::fake();
    Storage::fake('s3');
    $workspace = bootKnowledgeTenant();

    Livewire::test(CreateAgentDocument::class)
        ->fillForm([
            'name' => 'Politica de trocas',
            'storage_path' => UploadedFile::fake()->create('policy.md', 10, 'text/markdown'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $document = AgentDocument::query()->firstOrFail();

    expect($document->workspace_id)->toBe($workspace->id)
        ->and($document->name)->toBe('Politica de trocas')
        ->and($document->index_status)->toBe(AgentDocumentStatus::Pending)
        ->and($document->mime_type)->toBe('text/markdown');

    Queue::assertPushed(
        IndexKnowledgeDocumentJob::class,
        fn (IndexKnowledgeDocumentJob $job): bool => $job->agentDocumentId === $document->id,
    );
});
