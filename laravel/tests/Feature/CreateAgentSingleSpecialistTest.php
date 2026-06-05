<?php

declare(strict_types=1);

use App\Enums\AgentLlmProvider;
use App\Enums\AgentMode;
use App\Enums\AgentResponseMode;
use App\Enums\AgentSpecialistStatus;
use App\Enums\AgentStatus;
use App\Filament\Resources\Agents\Pages\CreateAgent;
use App\Models\Agent;
use App\Models\AgentLlmKey;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function bootSingleSpecTenant(Workspace $workspace): void
{
    Filament::setCurrentPanel('admin');
    Filament::setTenant($workspace);
    Filament::bootCurrentPanel();
}

function singleSpecAgentFormData(array $overrides = []): array
{
    return [
        'name' => 'Atendimento',
        'status' => AgentStatus::Active->value,
        'mode' => AgentMode::Single->value,
        'description' => 'Agente de teste.',
        'locale' => 'pt_BR',
        'timezone' => 'America/Sao_Paulo',
        'response_mode' => AgentResponseMode::Automatic->value,
        'debounce_config' => ['enabled' => true, 'window_seconds' => 8, 'max_wait_seconds' => 20, 'max_messages' => 10],
        'media_policy' => [],
        'guard_config' => [],
        'rag_config' => [],
        'runtime_config' => ['graph' => 'default_support_agent', 'checkpointing' => true, 'tool_call_limit' => 8],
        ...$overrides,
    ];
}

it('auto-creates exactly one specialist for a single-mode agent', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);

    actingAs($user);
    bootSingleSpecTenant($workspace);

    Livewire::test(CreateAgent::class)
        ->fillForm(singleSpecAgentFormData(['name' => 'Calculadora', 'mode' => AgentMode::Single->value]))
        ->call('create')
        ->assertHasNoFormErrors();

    $agent = Agent::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect($agent->mode)->toBe(AgentMode::Single)
        ->and($agent->specialists()->count())->toBe(1);

    $specialist = $agent->specialists()->first();
    expect($specialist->name)->toBe('Calculadora')
        ->and($specialist->status)->toBe(AgentSpecialistStatus::Active)
        ->and($specialist->workspace_id)->toBe($workspace->id)
        ->and($specialist->priority)->toBe(100)
        ->and($specialist->tools_allowlist)->toBe([])
        ->and($specialist->intent_keywords)->toBe([]);
});

it('does not auto-create a specialist for a supervisor-mode agent', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);
    $llmKey = AgentLlmKey::factory()->provider(AgentLlmProvider::OpenAI)->for($workspace)->create();

    actingAs($user);
    bootSingleSpecTenant($workspace);

    Livewire::test(CreateAgent::class)
        ->fillForm(singleSpecAgentFormData([
            'mode' => AgentMode::Supervisor->value,
            'supervisor_llm_key_id' => $llmKey->id,
            'supervisor_llm_model__choice' => '__custom__',
            'supervisor_llm_model' => 'gpt-4.1-nano',
            'supervisor_prompt' => 'Route to the best specialist.',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $agent = Agent::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect($agent->mode)->toBe(AgentMode::Supervisor)
        ->and($agent->specialists()->count())->toBe(0);
});
