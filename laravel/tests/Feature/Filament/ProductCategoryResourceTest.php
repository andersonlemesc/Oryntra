<?php

declare(strict_types=1);

use App\Actions\Products\ImportProductsFromCsv;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates categories scoped to the current workspace', function () {
    [$user, $workspace] = productCatalogUserAndWorkspace();

    actingAs($user);
    productCatalogBootFilamentTenant($workspace);

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'workspace_id' => $workspace->id,
            'name' => 'Bikes',
            'slug' => 'bikes',
            'description' => 'Linha de bicicletas.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Category::class, [
        'workspace_id' => $workspace->id,
        'name' => 'Bikes',
        'slug' => 'bikes',
    ]);
});

it('creates products with a category foreign key from the current workspace', function () {
    [$user, $workspace] = productCatalogUserAndWorkspace();
    $category = Category::factory()->for($workspace)->create(['name' => 'Bikes', 'slug' => 'bikes']);

    actingAs($user);
    productCatalogBootFilamentTenant($workspace);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'workspace_id' => $workspace->id,
            'category_id' => $category->id,
            'name' => 'Bike Eletrica Urbana',
            'sku' => 'BIKE-001',
            'description' => 'Autonomia de 50km.',
            'price' => 3499.90,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Product::class, [
        'workspace_id' => $workspace->id,
        'category_id' => $category->id,
        'sku' => 'BIKE-001',
    ]);
});

it('scopes product and category resource queries to the current workspace', function () {
    [$user, $workspace] = productCatalogUserAndWorkspace();
    $otherWorkspace = Workspace::factory()->create();
    $visibleCategory = Category::factory()->for($workspace)->create();
    $hiddenCategory = Category::factory()->for($otherWorkspace)->create();
    $visibleProduct = Product::factory()->forCategory($visibleCategory)->create();
    $hiddenProduct = Product::factory()->forCategory($hiddenCategory)->create();

    actingAs($user);
    productCatalogBootFilamentTenant($workspace);

    expect(CategoryResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($visibleCategory->id)
        ->not->toContain($hiddenCategory->id);

    expect(ProductResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($visibleProduct->id)
        ->not->toContain($hiddenProduct->id);
});

it('imports csv products and creates workspace categories by foreign key', function () {
    $workspace = Workspace::factory()->create();

    $result = app(ImportProductsFromCsv::class)->execute(
        $workspace->id,
        "name,sku,description,price,category\nBike Eletrica Urbana,BIKE-001,Autonomia de 50km,3499.90,Bikes\n",
    );

    $category = Category::query()
        ->where('workspace_id', $workspace->id)
        ->where('slug', 'bikes')
        ->firstOrFail();

    expect($result)->toMatchArray(['imported' => 1, 'updated' => 0]);

    assertDatabaseHas(Product::class, [
        'workspace_id' => $workspace->id,
        'category_id' => $category->id,
        'sku' => 'BIKE-001',
    ]);
});

/**
 * @return array{User, Workspace}
 */
function productCatalogUserAndWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);

    return [$user, $workspace];
}

function productCatalogBootFilamentTenant(Workspace $workspace): void
{
    Filament::setCurrentPanel('admin');
    Filament::setTenant($workspace);
    Filament::bootCurrentPanel();
}
