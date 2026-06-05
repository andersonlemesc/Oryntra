<?php

declare(strict_types=1);

use App\Enums\AgentLlmProvider;
use App\Enums\AgentSpecialistStatus;
use App\Filament\Resources\AgentLlmKeys\Pages\CreateAgentLlmKey;
use App\Filament\Resources\Agents\Pages\EditAgent;
use App\Filament\Resources\Agents\RelationManagers\SpecialistsRelationManager;
use App\Models\Agent;
use App\Models\AgentLlmKey;
use App\Models\AgentSpecialist;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @return array{User, Workspace}
 */
function llmFieldUserAndWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);

    actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($workspace);
    Filament::bootCurrentPanel();

    return [$user, $workspace];
}

it('persists a synced model picked from the list without using custom', function () {
    [, $workspace] = llmFieldUserAndWorkspace();
    $agent = Agent::factory()->supervisor()->for($workspace)->create();
    $key = AgentLlmKey::factory()->provider(AgentLlmProvider::OpenAI)->for($workspace)->create();
    $key->models()->create(['model_id' => 'gpt-4.1-mini', 'label' => 'gpt-4.1-mini']);

    Livewire::test(SpecialistsRelationManager::class, [
        'ownerRecord' => $agent,
        'pageClass' => EditAgent::class,
    ])
        ->callAction(TestAction::make(CreateAction::class)->table(), [
            'name' => 'Suporte',
            'status' => AgentSpecialistStatus::Active->value,
            'role_prompt' => 'Answer support questions.',
            'intent_keywords' => ['ajuda'],
            'llm_key_id' => $key->id,
            'llm_model__choice' => 'gpt-4.1-mini',
            'llm_temperature' => 0.2,
            'tools_allowlist' => [],
            'priority' => 10,
            'confidence_threshold' => 0.6,
        ])
        ->assertHasNoFormErrors();

    assertDatabaseHas(AgentSpecialist::class, [
        'agent_id' => $agent->id,
        'name' => 'Suporte',
        'llm_model' => 'gpt-4.1-mini',
    ]);
});

it('saves base_url when creating an LLM key', function () {
    [, $workspace] = llmFieldUserAndWorkspace();

    Livewire::test(CreateAgentLlmKey::class)
        ->fillForm([
            'name' => 'Groq Prod',
            'provider' => AgentLlmProvider::OpenAI->value,
            'base_url' => 'https://api.groq.com/openai/v1',
            'api_key' => 'sk-groq',
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas(AgentLlmKey::class, [
        'workspace_id' => $workspace->id,
        'name' => 'Groq Prod',
        'base_url' => 'https://api.groq.com/openai/v1',
    ]);
});
