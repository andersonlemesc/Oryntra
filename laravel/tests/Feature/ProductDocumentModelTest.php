<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates a product document', function () {
    $workspace = Workspace::factory()->create();
    $product = Product::factory()->for($workspace)->create();
    $doc = ProductDocument::factory()->for($workspace)->for($product)->create();

    expect($doc)->toBeInstanceOf(ProductDocument::class);
    expect($doc->workspace_id)->toBe($workspace->id);
    expect($doc->product_id)->toBe($product->id);
    expect($doc->mime_type)->toBe('application/pdf');
});

it('product has documents relation', function () {
    $workspace = Workspace::factory()->create();
    $product = Product::factory()->for($workspace)->create();
    ProductDocument::factory()->for($workspace)->for($product)->create([
        'original_filename' => 'catalogo.pdf',
    ]);
    ProductDocument::factory()->for($workspace)->for($product)->create([
        'original_filename' => 'ficha-tecnica.pdf',
    ]);

    $product->load('documents');
    expect($product->documents)->toHaveCount(2);
});

it('product toAgentPayload includes documents when loaded', function () {
    $workspace = Workspace::factory()->create();
    $product = Product::factory()->for($workspace)->create();
    ProductDocument::factory()->for($workspace)->for($product)->create([
        'original_filename' => 'catalogo.pdf',
    ]);

    $product->load('documents');
    $payload = $product->toAgentPayload();

    expect($payload)->toHaveKey('documents');
    expect($payload['documents'])->toHaveCount(1);
    expect($payload['documents'][0]['original_filename'])->toBe('catalogo.pdf');
});

it('product toAgentPayload has empty documents array when not loaded', function () {
    $workspace = Workspace::factory()->create();
    $product = Product::factory()->for($workspace)->create();

    $payload = $product->toAgentPayload();

    expect($payload)->toHaveKey('documents');
    expect($payload['documents'])->toBeEmpty();
});
