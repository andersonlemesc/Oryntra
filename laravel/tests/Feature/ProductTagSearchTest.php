<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\Workspace;
use App\Services\Products\ProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * Regressão do bug real: cliente pergunta "televisor", produto cadastrado é
 * "Smart TV 55" 4K" — sem overlap léxico, a busca por nome/descrição retornava 0.
 * Com a tag "televisor" no produto, a busca passa a encontrá-lo.
 */
it('encontra produto por tag sem overlap lexico no nome', function () {
    $workspace = Workspace::factory()->create();
    $category = Category::factory()->for($workspace)->create([
        'name' => 'Eletronicos',
        'slug' => 'eletronicos',
    ]);

    $tv = Product::factory()
        ->forCategory($category)
        ->withTags(['televisor', 'tv', 'led', 'smart tv'])
        ->create(['name' => 'Smart TV 55" 4K TechLar UltraView']);

    $result = app(ProductSearchService::class)->search(
        workspaceId: $workspace->id,
        query: 'televisor',
    );

    expect($result['total'])->toBe(1)
        ->and($result['products'][0]['id'])->toBe($tv->id);
});

it('nao encontra o produto quando nao ha tag nem match no nome', function () {
    $workspace = Workspace::factory()->create();

    Product::factory()->for($workspace)->create([
        'name' => 'Smart TV 55" 4K TechLar UltraView',
        'tags' => null,
    ]);

    $result = app(ProductSearchService::class)->search(
        workspaceId: $workspace->id,
        query: 'televisor',
    );

    expect($result['total'])->toBe(0);
});

it('casa tag de forma acento-insensivel', function () {
    $workspace = Workspace::factory()->create();

    $product = Product::factory()->for($workspace)
        ->withTags(['sofa', 'estofado'])
        ->create(['name' => 'Conjunto 3 lugares']);

    $result = app(ProductSearchService::class)->search(
        workspaceId: $workspace->id,
        query: 'sofa',
    );

    expect($result['total'])->toBe(1)
        ->and($result['products'][0]['id'])->toBe($product->id);
});

it('nao vaza tags no payload entregue ao agente', function () {
    $workspace = Workspace::factory()->create();

    Product::factory()->for($workspace)
        ->withTags(['televisor', 'tv'])
        ->create(['name' => 'Smart TV 55']);

    $result = app(ProductSearchService::class)->search(
        workspaceId: $workspace->id,
        query: 'televisor',
    );

    expect($result['products'][0])->not->toHaveKey('tags');
});
