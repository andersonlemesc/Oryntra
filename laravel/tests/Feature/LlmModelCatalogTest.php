<?php

declare(strict_types=1);

use App\Enums\AgentLlmProvider;
use App\Models\AgentLlmKey;
use App\Models\AgentLlmModel;
use App\Models\Workspace;
use App\Services\Llm\LlmModelCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('syncs OpenAI-compatible models from a custom base url', function () {
    Http::fake([
        'https://api.groq.com/openai/v1/models' => Http::response([
            'data' => [
                ['id' => 'llama-3.3-70b'],
                ['id' => 'mixtral-8x7b'],
            ],
        ]),
    ]);

    $key = AgentLlmKey::factory()
        ->provider(AgentLlmProvider::OpenAI)
        ->for(Workspace::factory())
        ->create([
            'api_key' => 'sk-test',
            'base_url' => 'https://api.groq.com/openai/v1',
        ]);

    $count = app(LlmModelCatalog::class)->sync($key);

    expect($count)->toBe(2);
    assertDatabaseHas(AgentLlmModel::class, [
        'agent_llm_key_id' => $key->id,
        'model_id' => 'llama-3.3-70b',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-test'));
});

it('syncs Anthropic models with the version header', function () {
    Http::fake([
        'https://api.anthropic.com/v1/models' => Http::response([
            'data' => [['id' => 'claude-sonnet-4-20250514']],
        ]),
    ]);

    $key = AgentLlmKey::factory()
        ->provider(AgentLlmProvider::Anthropic)
        ->for(Workspace::factory())
        ->create(['api_key' => 'sk-ant', 'base_url' => null]);

    $count = app(LlmModelCatalog::class)->sync($key);

    expect($count)->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'sk-ant')
        && $request->hasHeader('anthropic-version', '2023-06-01'));
});

it('syncs Gemini models, stripping the prefix and filtering by generateContent', function () {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models*' => Http::response([
            'models' => [
                ['name' => 'models/gemini-2.0-flash', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/text-embedding-004', 'supportedGenerationMethods' => ['embedContent']],
            ],
        ]),
    ]);

    $key = AgentLlmKey::factory()
        ->provider(AgentLlmProvider::Gemini)
        ->for(Workspace::factory())
        ->create(['api_key' => 'g-key', 'base_url' => null]);

    $count = app(LlmModelCatalog::class)->sync($key);

    expect($count)->toBe(1);
    assertDatabaseHas(AgentLlmModel::class, [
        'agent_llm_key_id' => $key->id,
        'model_id' => 'gemini-2.0-flash',
    ]);
});

it('prunes models that disappear on resync', function () {
    $key = AgentLlmKey::factory()
        ->provider(AgentLlmProvider::OpenAI)
        ->for(Workspace::factory())
        ->create(['api_key' => 'sk-test', 'base_url' => null]);

    $key->models()->create(['model_id' => 'stale-model', 'label' => 'stale-model']);

    Http::fake([
        'https://api.openai.com/v1/models' => Http::response([
            'data' => [['id' => 'gpt-4.1']],
        ]),
    ]);

    app(LlmModelCatalog::class)->sync($key);

    assertDatabaseHas(AgentLlmModel::class, ['agent_llm_key_id' => $key->id, 'model_id' => 'gpt-4.1']);
    assertDatabaseMissing(AgentLlmModel::class, ['agent_llm_key_id' => $key->id, 'model_id' => 'stale-model']);
});
