<?php

declare(strict_types=1);

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use App\Models\AgentLlmKey;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates key tied to workspace with default active status', function () {
    $workspace = Workspace::factory()->create();
    $key = AgentLlmKey::factory()->for($workspace)->create();

    expect($key->status)->toBe(AgentLlmKeyStatus::Active)
        ->and($key->provider)->toBe(AgentLlmProvider::OpenAI)
        ->and($key->workspace_id)->toBe($workspace->id);
});

it('encrypts api_key at rest and decrypts via model', function () {
    $key = AgentLlmKey::factory()->create([
        'api_key' => 'sk-supersecret-XYZ-123',
    ]);

    $stored = DB::table('agent_llm_keys')->where('id', $key->id)->value('api_key');

    expect($stored)->not->toBe('sk-supersecret-XYZ-123')
        ->and($key->fresh()?->api_key)->toBe('sk-supersecret-XYZ-123');
});

it('enforces unique name per workspace', function () {
    $workspace = Workspace::factory()->create();
    AgentLlmKey::factory()->for($workspace)->create(['name' => 'OpenAI Prod']);

    expect(fn () => AgentLlmKey::factory()->for($workspace)->create(['name' => 'OpenAI Prod']))
        ->toThrow(QueryException::class);
});

it('allows same key name across different workspaces', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    AgentLlmKey::factory()->for($a)->create(['name' => 'OpenAI Prod']);
    $second = AgentLlmKey::factory()->for($b)->create(['name' => 'OpenAI Prod']);

    expect($second->exists)->toBeTrue();
});

it('cascades keys deletion when workspace deleted', function () {
    $workspace = Workspace::factory()->create();
    $key = AgentLlmKey::factory()->for($workspace)->create();

    $workspace->delete();

    expect(AgentLlmKey::find($key->id))->toBeNull();
});

it('hides api_key from model array output', function () {
    $key = AgentLlmKey::factory()->create([
        'api_key' => 'sk-shouldnotleak',
    ]);

    expect($key->toArray())->not->toHaveKey('api_key');
});
