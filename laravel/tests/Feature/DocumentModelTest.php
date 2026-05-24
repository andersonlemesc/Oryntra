<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class);
uses(RefreshDatabase::class);

it('creates a standalone document', function () {
    $workspace = Workspace::factory()->create();
    $doc = Document::factory()->for($workspace)->create();

    expect($doc)->toBeInstanceOf(Document::class);
    expect($doc->workspace_id)->toBe($workspace->id);
    expect($doc->category)->toBeIn(['general', 'faq', 'policy', 'catalog', 'manual']);
});

it('filters documents by category', function () {
    $workspace = Workspace::factory()->create();
    Document::factory()->for($workspace)->create(['category' => 'policy']);
    Document::factory()->for($workspace)->create(['category' => 'faq']);
    Document::factory()->for($workspace)->create(['category' => 'policy']);

    $policies = Document::query()->byCategory('policy')->get();
    expect($policies)->toHaveCount(2);
});

it('document toAgentPayload includes all fields', function () {
    $workspace = Workspace::factory()->create();
    $doc = Document::factory()->for($workspace)->create([
        'title' => 'Termos de Uso',
        'category' => 'policy',
        'original_filename' => 'termos.pdf',
    ]);

    $payload = $doc->toAgentPayload();

    expect($payload)->toHaveKey('id');
    expect($payload['title'])->toBe('Termos de Uso');
    expect($payload['category'])->toBe('policy');
    expect($payload['original_filename'])->toBe('termos.pdf');
});