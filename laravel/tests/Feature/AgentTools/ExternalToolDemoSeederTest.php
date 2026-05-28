<?php

declare(strict_types=1);

use App\Models\AgentRun;
use App\Models\ExternalTool;
use App\Models\Workspace;
use App\Services\AgentTools\ExternalToolExecutor;
use Database\Seeders\ExternalToolDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('seeds an idempotent ViaCEP connector per workspace', function () {
    $workspace = Workspace::factory()->create();

    $this->seed(ExternalToolDemoSeeder::class);
    $this->seed(ExternalToolDemoSeeder::class);

    $tools = ExternalTool::query()->where('workspace_id', $workspace->id)->where('slug', 'consulta_cep')->get();

    expect($tools)->toHaveCount(1);

    $tool = $tools->first();
    expect($tool->config['path'])->toBe('/ws/{cep}/json/')
        ->and($tool->config['param_schema']['properties']['cep']['location'])->toBe('path')
        ->and($tool->enabled)->toBeTrue();
});

it('executes the seeded ViaCEP connector end to end with a mocked response', function () {
    Http::fake([
        'viacep.com.br/*' => Http::response([
            'cep' => '01001-000',
            'logradouro' => 'Praca da Se',
            'bairro' => 'Se',
            'localidade' => 'Sao Paulo',
            'uf' => 'SP',
        ], 200),
    ]);

    $workspace = Workspace::factory()->create();
    $this->seed(ExternalToolDemoSeeder::class);

    $tool = ExternalTool::query()->where('workspace_id', $workspace->id)->where('slug', 'consulta_cep')->firstOrFail();
    $run = AgentRun::factory()->create(['workspace_id' => $workspace->id]);

    $result = app(ExternalToolExecutor::class)->execute($tool, ['cep' => '01001000'], $run->id, null);

    expect($result['success'])->toBeTrue()
        ->and($result['result'])->toContain('address_street: Praca da Se')
        ->and($result['result'])->toContain('address_city: Sao Paulo')
        ->and($result['result'])->toContain('address_state: SP');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/ws/01001000/json/'));
});
