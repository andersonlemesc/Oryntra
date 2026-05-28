<?php

declare(strict_types=1);

use App\Filament\Resources\ExternalTools\ExternalToolResource;
use App\Filament\Resources\ExternalTools\Pages\CreateExternalTool;
use App\Filament\Resources\ExternalTools\Pages\EditExternalTool;
use App\Models\ExternalTool;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @return array{User, Workspace}
 */
function externalToolUserAndWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);

    return [$user, $workspace];
}

function bootExternalToolTenant(Workspace $workspace): void
{
    Filament::setCurrentPanel('admin');
    Filament::setTenant($workspace);
    Filament::bootCurrentPanel();
}

it('creates a connector with a typed param repeater and encrypted bearer token', function () {
    [$user, $workspace] = externalToolUserAndWorkspace();

    actingAs($user);
    bootExternalToolTenant($workspace);

    Livewire::test(CreateExternalTool::class)
        ->fillForm([
            'workspace_id' => $workspace->id,
            'kind' => 'http_connector',
            'slug' => 'query_orders',
            'label' => 'Status do pedido',
            'description' => 'Consulta o status de um pedido.',
            'enabled' => true,
            'config' => [
                'http_method' => 'GET',
                'base_url' => 'https://erp.example.test',
                'path' => '/orders/{order_id}',
                'auth_type' => 'bearer',
                'response_extraction' => ['mode' => 'jsonpath', 'expression' => '$.status', 'max_length' => 2000],
            ],
            'secret_token' => 'tok-123',
            'advanced_schema' => false,
            'param_rows' => [
                ['name' => 'order_id', 'type' => 'string', 'location' => 'path', 'required' => true, 'description' => 'ID'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tool = ExternalTool::query()->where('slug', 'query_orders')->firstOrFail();

    expect($tool->workspace_id)->toBe($workspace->id)
        ->and($tool->config['param_schema']['properties']['order_id']['location'])->toBe('path')
        ->and($tool->config['param_schema']['properties']['order_id']['required'])->toBeTrue()
        ->and($tool->credentials)->toBe(['token' => 'tok-123']);

    // Stored encrypted (raw column is not the plaintext token).
    $raw = $tool->getRawOriginal('credentials');
    expect($raw)->not->toContain('tok-123');
});

it('creates a connector from the advanced JSON schema editor', function () {
    [$user, $workspace] = externalToolUserAndWorkspace();

    actingAs($user);
    bootExternalToolTenant($workspace);

    Livewire::test(CreateExternalTool::class)
        ->fillForm([
            'workspace_id' => $workspace->id,
            'kind' => 'http_connector',
            'slug' => 'create_ticket',
            'label' => 'Abrir ticket',
            'description' => 'Cria um ticket.',
            'enabled' => true,
            'config' => [
                'http_method' => 'POST',
                'base_url' => 'https://desk.example.test',
                'path' => '/tickets',
                'auth_type' => 'none',
                'response_extraction' => ['mode' => 'template', 'expression' => 'Ticket {{ id }}', 'max_length' => 500],
            ],
            'advanced_schema' => true,
            'param_schema_json' => '{"properties":{"subject":{"type":"string","location":"body","required":true,"description":"Assunto"}}}',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tool = ExternalTool::query()->where('slug', 'create_ticket')->firstOrFail();

    expect($tool->config['param_schema']['properties']['subject']['location'])->toBe('body')
        ->and($tool->config['http_method'])->toBe('POST');
});

it('rejects a non-snake_case slug', function () {
    [$user, $workspace] = externalToolUserAndWorkspace();

    actingAs($user);
    bootExternalToolTenant($workspace);

    Livewire::test(CreateExternalTool::class)
        ->fillForm([
            'workspace_id' => $workspace->id,
            'kind' => 'http_connector',
            'slug' => 'Query Orders',
            'label' => 'x',
            'description' => 'x',
            'enabled' => true,
            'config' => ['http_method' => 'GET', 'base_url' => 'https://x.test', 'auth_type' => 'none'],
            'advanced_schema' => false,
            'param_rows' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('keeps the existing secret when the token field is left blank on edit', function () {
    [$user, $workspace] = externalToolUserAndWorkspace();
    $tool = ExternalTool::factory()->for($workspace)->create([
        'slug' => 'query_orders',
        'config' => array_replace_recursive(ExternalTool::factory()->definition()['config'], ['auth_type' => 'bearer']),
        'credentials' => ['token' => 'keep-me'],
    ]);

    actingAs($user);
    bootExternalToolTenant($workspace);

    Livewire::test(EditExternalTool::class, ['record' => $tool->getRouteKey()])
        ->fillForm(['label' => 'Renomeado', 'secret_token' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    $tool->refresh();

    expect($tool->label)->toBe('Renomeado')
        ->and($tool->credentials)->toBe(['token' => 'keep-me']);
});

it('scopes the resource query to the current workspace', function () {
    [$user, $workspace] = externalToolUserAndWorkspace();
    $otherWorkspace = Workspace::factory()->create();
    $visible = ExternalTool::factory()->for($workspace)->create(['slug' => 'visible_tool']);
    $hidden = ExternalTool::factory()->for($otherWorkspace)->create(['slug' => 'hidden_tool']);

    actingAs($user);
    bootExternalToolTenant($workspace);

    expect(ExternalToolResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($visible->id)
        ->not->toContain($hidden->id);
});
