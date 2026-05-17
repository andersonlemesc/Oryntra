<?php

declare(strict_types=1);

use App\Enums\AgentLlmProvider;
use App\Enums\AgentMode;
use App\Enums\AgentResponseMode;
use App\Enums\AgentRunStatus;
use App\Enums\AgentSpecialistStatus;
use App\Enums\AgentStatus;
use App\Filament\Resources\Agents\Pages\CreateAgent;
use App\Filament\Resources\Agents\Pages\EditAgent;
use App\Filament\Resources\Agents\RelationManagers\SpecialistsRelationManager;
use App\Models\Agent;
use App\Models\AgentLlmKey;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ChatwootConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentRuntime\AgentRuntimeClient;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('shows supervisor fields only for supervisor agents', function () {
    [$user, $workspace] = supervisorAdminUxUserAndWorkspace();

    actingAs($user);
    supervisorAdminUxBootFilamentTenant($workspace);

    Livewire::test(CreateAgent::class)
        ->assertFormFieldHidden('supervisor_llm_key_id')
        ->assertFormFieldHidden('supervisor_llm_model')
        ->assertFormFieldHidden('supervisor_prompt')
        ->assertFormFieldVisible('llm_key_id')
        ->fillForm(['mode' => AgentMode::Supervisor->value])
        ->assertFormFieldVisible('supervisor_llm_key_id')
        ->assertFormFieldVisible('supervisor_llm_model')
        ->assertFormFieldVisible('supervisor_prompt')
        ->assertFormFieldHidden('llm_key_id');
});

it('requires supervisor llm configuration when creating a supervisor agent', function () {
    [$user, $workspace] = supervisorAdminUxUserAndWorkspace();

    actingAs($user);
    supervisorAdminUxBootFilamentTenant($workspace);

    Livewire::test(CreateAgent::class)
        ->fillForm(supervisorAdminUxAgentFormData([
            'mode' => AgentMode::Supervisor->value,
            'supervisor_llm_key_id' => null,
            'supervisor_llm_model' => null,
            'supervisor_prompt' => null,
        ]))
        ->call('create')
        ->assertHasFormErrors([
            'supervisor_llm_key_id' => 'required',
            'supervisor_llm_model' => 'required',
            'supervisor_prompt' => 'required',
        ]);
});

it('allows single agents without supervisor-only configuration', function () {
    [$user, $workspace] = supervisorAdminUxUserAndWorkspace();

    actingAs($user);
    supervisorAdminUxBootFilamentTenant($workspace);

    Livewire::test(CreateAgent::class)
        ->fillForm(supervisorAdminUxAgentFormData([
            'mode' => AgentMode::Single->value,
            'supervisor_llm_key_id' => null,
            'supervisor_llm_model' => null,
            'supervisor_prompt' => null,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Agent::class, [
        'workspace_id' => $workspace->id,
        'mode' => AgentMode::Single->value,
    ]);
});

it('requires runtime-ready llm fields for specialists created in the relation manager', function () {
    [$user, $workspace] = supervisorAdminUxUserAndWorkspace();
    $agent = Agent::factory()->supervisor()->for($workspace)->create();

    actingAs($user);
    supervisorAdminUxBootFilamentTenant($workspace);

    Livewire::test(SpecialistsRelationManager::class, [
        'ownerRecord' => $agent,
        'pageClass' => EditAgent::class,
    ])
        ->callAction(TestAction::make(CreateAction::class)->table(), [
            'name' => 'Suporte',
            'status' => AgentSpecialistStatus::Active->value,
            'role_prompt' => 'Answer support questions.',
            'llm_key_id' => null,
            'llm_model' => null,
            'llm_temperature' => 0.2,
            'intent_keywords' => ['ajuda'],
            'tools_allowlist' => [],
            'priority' => 10,
            'confidence_threshold' => 0.6,
        ])
        ->assertHasFormErrors([
            'llm_key_id' => 'required',
            'llm_model' => 'required',
        ]);
});

it('creates specialists scoped to the current Filament tenant', function () {
    [$user, $workspace] = supervisorAdminUxUserAndWorkspace();
    $agent = Agent::factory()->supervisor()->for($workspace)->create();
    $llmKey = AgentLlmKey::factory()->provider(AgentLlmProvider::OpenAI)->for($workspace)->create();

    actingAs($user);
    supervisorAdminUxBootFilamentTenant($workspace);

    Livewire::test(SpecialistsRelationManager::class, [
        'ownerRecord' => $agent,
        'pageClass' => EditAgent::class,
    ])
        ->callAction(TestAction::make(CreateAction::class)->table(), [
            'name' => 'Suporte',
            'status' => AgentSpecialistStatus::Active->value,
            'description' => 'Atendimento de suporte.',
            'role_prompt' => 'Answer support questions.',
            'intent_keywords' => ['ajuda', 'suporte'],
            'llm_key_id' => $llmKey->id,
            'llm_model' => 'gpt-4.1-nano',
            'llm_temperature' => 0.2,
            'tools_allowlist' => [],
            'priority' => 10,
            'confidence_threshold' => 0.6,
        ])
        ->assertHasNoFormErrors();

    assertDatabaseHas(AgentSpecialist::class, [
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'name' => 'Suporte',
        'llm_key_id' => $llmKey->id,
        'llm_model' => 'gpt-4.1-nano',
    ]);
});

it('sends admin-configured supervisor and specialist credentials to the runtime as objects', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'completed',
            'response' => [
                'type' => 'text',
                'content' => 'Ok.',
                'document_id' => null,
                'handoff_reason' => null,
                'confidence' => 0.9,
            ],
            'specialist_id' => 5,
            'trace' => [],
            'usage' => [
                'supervisor' => ['input_tokens' => 0, 'output_tokens' => 0],
                'specialist' => ['input_tokens' => 0, 'output_tokens' => 0],
                'total_cost_cents' => 0,
            ],
        ]),
    ]);

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $llmKey = AgentLlmKey::factory()->provider(AgentLlmProvider::OpenAI)->for($workspace)->create([
        'api_key' => 'sk-admin-configured',
    ]);
    $agent = Agent::factory()->active()->supervisor()->for($workspace)->create([
        'supervisor_llm_key_id' => $llmKey->id,
        'supervisor_llm_model' => 'gpt-4.1-nano',
    ]);

    AgentSpecialist::factory()->for($agent)->create([
        'workspace_id' => $workspace->id,
        'name' => 'Suporte',
        'role_prompt' => 'Answer support questions.',
        'intent_keywords' => ['ajuda'],
        'llm_key_id' => $llmKey->id,
        'llm_model' => 'gpt-4.1-nano',
        'confidence_threshold' => 0.6,
    ]);

    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'input' => ['messages' => [['id' => '123', 'content' => 'preciso de ajuda']]],
    ]);

    app(AgentRuntimeClient::class)->run($run);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body());

        return $body instanceof stdClass
            && $body->supervisor->llm_api_key === 'sk-admin-configured'
            && $body->supervisor->llm_provider === 'openai'
            && $body->supervisor->llm_model === 'gpt-4.1-nano'
            && $body->specialists[0]->llm_api_key === 'sk-admin-configured'
            && $body->specialists[0]->llm_provider === 'openai'
            && $body->specialists[0]->llm_model === 'gpt-4.1-nano'
            && $body->contact instanceof stdClass
            && $body->inbox instanceof stdClass
            && $body->guard_config instanceof stdClass
            && $body->media_config instanceof stdClass
            && $body->runtime_config instanceof stdClass;
    });
});

it('can run a real-runtime smoke from the manually configured agent edit page', function () {
    Config::set('services.agent_runtime.base_url', 'http://agent-python:8000');
    Config::set('services.agent_runtime.internal_token', 'ci-token');

    Http::fake([
        'http://agent-python:8000/internal/chatwoot/messages' => Http::response([
            'status' => 'completed',
            'response' => [
                'type' => 'text',
                'content' => 'Ok.',
                'document_id' => null,
                'handoff_reason' => null,
                'confidence' => 0.9,
            ],
            'specialist_id' => 5,
            'trace' => [
                [
                    'step' => 3,
                    'type' => 'specialist_response',
                    'specialist_id' => 5,
                    'tool' => null,
                    'input' => [],
                    'output' => ['response_type' => 'text', 'source' => 'llm'],
                    'tokens' => ['input' => 0, 'output' => 0],
                    'latency_ms' => 0,
                    'ts' => now()->toISOString(),
                ],
            ],
            'usage' => [
                'supervisor' => ['input_tokens' => 0, 'output_tokens' => 0],
                'specialist' => ['input_tokens' => 0, 'output_tokens' => 0],
                'total_cost_cents' => 0,
            ],
        ]),
    ]);

    [$user, $workspace] = supervisorAdminUxUserAndWorkspace();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $llmKey = AgentLlmKey::factory()->provider(AgentLlmProvider::OpenAI)->for($workspace)->create([
        'api_key' => 'sk-admin-action',
    ]);
    $agent = Agent::factory()->active()->supervisor()->for($workspace)->create([
        'supervisor_llm_key_id' => $llmKey->id,
        'supervisor_llm_model' => 'gpt-4.1-nano',
    ]);

    AgentSpecialist::factory()->for($agent)->create([
        'workspace_id' => $workspace->id,
        'name' => 'Suporte',
        'role_prompt' => 'Answer support questions.',
        'intent_keywords' => ['ajuda'],
        'llm_key_id' => $llmKey->id,
        'llm_model' => 'gpt-4.1-nano',
    ]);

    actingAs($user);
    supervisorAdminUxBootFilamentTenant($workspace);

    Livewire::test(EditAgent::class, ['record' => $agent->id])
        ->callAction('testRuntime', data: [
            'chatwoot_connection_id' => $connection->id,
            'message' => 'preciso de ajuda no suporte',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Runtime testado com sucesso');

    $run = AgentRun::query()->latest('id')->firstOrFail();
    $output = $run->output;

    assert(is_array($output));

    expect($run->agent_id)->toBe($agent->id)
        ->and($run->workspace_id)->toBe($workspace->id)
        ->and($run->status)->toBe(AgentRunStatus::Completed)
        ->and($output['response']['content'])->toBe('Ok.');

    Http::assertSent(fn (Request $request): bool => $request['supervisor']['llm_api_key'] === 'sk-admin-action'
        && $request['specialists'][0]['llm_api_key'] === 'sk-admin-action');
});

/**
 * @return array{User, Workspace}
 */
function supervisorAdminUxUserAndWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);

    return [$user, $workspace];
}

function supervisorAdminUxBootFilamentTenant(Workspace $workspace): void
{
    Filament::setCurrentPanel('admin');
    Filament::setTenant($workspace);
    Filament::bootCurrentPanel();
}

/**
 * @param  array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function supervisorAdminUxAgentFormData(array $overrides = []): array
{
    return [
        'name' => 'Atendimento Principal',
        'status' => AgentStatus::Active->value,
        'mode' => AgentMode::Single->value,
        'description' => 'Agente de atendimento.',
        'locale' => 'pt_BR',
        'timezone' => 'America/Sao_Paulo',
        'response_mode' => AgentResponseMode::Automatic->value,
        'llm_provider' => AgentLlmProvider::OpenAI->value,
        'llm_model' => 'gpt-4.1-nano',
        'llm_temperature' => 0.2,
        'llm_max_tokens' => 1024,
        'system_prompt' => 'You are a helpful assistant.',
        'debounce_config' => [
            'enabled' => true,
            'window_seconds' => 8,
            'max_wait_seconds' => 20,
            'max_messages' => 10,
        ],
        'media_policy' => [],
        'guard_config' => [],
        'rag_config' => [],
        'runtime_config' => [
            'graph' => 'default_support_agent',
            'checkpointing' => true,
            'tool_call_limit' => 8,
        ],
        ...$overrides,
    ];
}
