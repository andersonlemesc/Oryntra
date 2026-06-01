<?php

declare(strict_types=1);

use App\Models\Agent;
use App\Models\Category;
use App\Models\Product;
use App\Models\Workspace;
use App\Services\Products\ProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('scopes the product catalog to the agent (own + global, never another agents)', function () {
    $workspace = Workspace::factory()->create();
    $agentA = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $agentB = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $category = Category::factory()->create(['workspace_id' => $workspace->id]);

    $own = Product::factory()->create(['workspace_id' => $workspace->id, 'category_id' => $category->id, 'name' => 'Owned', 'active' => true]);
    $other = Product::factory()->create(['workspace_id' => $workspace->id, 'category_id' => $category->id, 'name' => 'Other', 'active' => true]);
    Product::factory()->create(['workspace_id' => $workspace->id, 'category_id' => $category->id, 'name' => 'Shared', 'active' => true]);

    $own->agents()->sync([$agentA->id]);
    $other->agents()->sync([$agentB->id]);
    // The "Shared" product stays unlinked = visible to every agent.

    $result = app(ProductSearchService::class)->search(workspaceId: $workspace->id, agentId: $agentA->id);

    $names = array_map(fn (array $p): string => $p['name'], $result['products']);

    expect($names)->toContain('Owned')
        ->and($names)->toContain('Shared')
        ->and($names)->not->toContain('Other');
});
