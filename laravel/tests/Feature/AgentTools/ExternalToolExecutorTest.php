<?php

declare(strict_types=1);

use App\Models\AgentRun;
use App\Models\ExternalTool;
use App\Models\ExternalToolCallLog;
use App\Models\Workspace;
use App\Services\AgentTools\ExternalToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function makeConnector(array $configOverrides = [], ?array $credentials = null): ExternalTool
{
    $workspace = Workspace::factory()->create();

    $config = array_replace_recursive([
        'http_method' => 'GET',
        'base_url' => 'https://api.example.test',
        'path' => '/orders',
        'auth_type' => 'none',
        'auth_config' => [],
        'static_headers' => [],
        'param_schema' => ['properties' => []],
        'response_extraction' => ['mode' => 'jsonpath', 'expression' => '$', 'max_length' => 2000],
        'timeout_seconds' => null,
    ], $configOverrides);

    return ExternalTool::factory()->for($workspace)->create([
        'slug' => 'query_orders',
        'config' => $config,
        'credentials' => $credentials,
    ]);
}

function runForTool(ExternalTool $tool): AgentRun
{
    return AgentRun::factory()->create(['workspace_id' => $tool->workspace_id]);
}

function executor(): ExternalToolExecutor
{
    return app(ExternalToolExecutor::class);
}

it('executes a GET with a query param and extracts via jsonpath', function () {
    Http::fake(['*' => Http::response(['order' => ['status' => 'shipped']], 200)]);

    $tool = makeConnector([
        'param_schema' => ['properties' => [
            'order_id' => ['type' => 'string', 'location' => 'query', 'required' => true],
        ]],
        'response_extraction' => ['mode' => 'jsonpath', 'expression' => '$.order.status', 'max_length' => 2000],
    ]);
    $run = runForTool($tool);

    $result = executor()->execute($tool, ['order_id' => 'A1'], $run->id, null);

    expect($result['success'])->toBeTrue()
        ->and($result['result'])->toBe('shipped')
        ->and($result['http_status'])->toBe(200);

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), 'order_id=A1'));

    expect(ExternalToolCallLog::query()->where('agent_run_id', $run->id)->where('success', true)->count())->toBe(1);
});

it('substitutes path parameters into the URL', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $tool = makeConnector([
        'path' => '/orders/{order_id}',
        'param_schema' => ['properties' => [
            'order_id' => ['type' => 'string', 'location' => 'path', 'required' => true],
        ]],
    ]);
    $run = runForTool($tool);

    executor()->execute($tool, ['order_id' => '42'], $run->id, null);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/orders/42'));
});

it('injects api key header auth', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $tool = makeConnector(
        ['auth_type' => 'api_key', 'auth_config' => ['header_name' => 'X-Token']],
        ['token' => 'secret-key'],
    );
    $run = runForTool($tool);

    executor()->execute($tool, [], $run->id, null);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Token', 'secret-key'));
});

it('injects bearer token auth', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $tool = makeConnector(['auth_type' => 'bearer'], ['token' => 'abc123']);
    $run = runForTool($tool);

    executor()->execute($tool, [], $run->id, null);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer abc123'));
});

it('injects basic auth', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $tool = makeConnector(['auth_type' => 'basic'], ['username' => 'u', 'password' => 'p']);
    $run = runForTool($tool);

    executor()->execute($tool, [], $run->id, null);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic ' . base64_encode('u:p')));
});

it('sends a POST body and does not retry on failure', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $tool = makeConnector([
        'http_method' => 'POST',
        'param_schema' => ['properties' => [
            'note' => ['type' => 'string', 'location' => 'body', 'required' => true],
        ]],
    ]);
    $run = runForTool($tool);

    $result = executor()->execute($tool, ['note' => 'hi'], $run->id, null);

    expect($result['success'])->toBeFalse()
        ->and($result['http_status'])->toBe(500);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['note'] === 'hi');
});

it('retries GET requests on failure', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $tool = makeConnector();
    $run = runForTool($tool);

    executor()->execute($tool, [], $run->id, null);

    Http::assertSentCount(2);
});

it('renders a template extraction', function () {
    Http::fake(['*' => Http::response(['order' => ['status' => 'paid', 'id' => 9]], 200)]);

    $tool = makeConnector([
        'response_extraction' => ['mode' => 'template', 'expression' => 'Pedido {{ order.id }}: {{ order.status }}', 'max_length' => 2000],
    ]);
    $run = runForTool($tool);

    $result = executor()->execute($tool, [], $run->id, null);

    expect($result['result'])->toBe('Pedido 9: paid');
});

it('rejects an undeclared parameter', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $tool = makeConnector();
    $run = runForTool($tool);

    $result = executor()->execute($tool, ['evil' => 1], $run->id, null);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Unknown parameter');
    Http::assertNothingSent();
});

it('rejects a missing required parameter', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $tool = makeConnector([
        'param_schema' => ['properties' => [
            'order_id' => ['type' => 'string', 'location' => 'query', 'required' => true],
        ]],
    ]);
    $run = runForTool($tool);

    $result = executor()->execute($tool, [], $run->id, null);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Missing required');
    Http::assertNothingSent();
});

it('rejects a non-http scheme', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $tool = makeConnector(['base_url' => 'ftp://api.example.test']);
    $run = runForTool($tool);

    $result = executor()->execute($tool, [], $run->id, null);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('scheme');
    Http::assertNothingSent();
});

it('allows an internal http host (trust-admin)', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $tool = makeConnector(['base_url' => 'http://192.168.0.10:8080', 'path' => '/internal']);
    $run = runForTool($tool);

    $result = executor()->execute($tool, [], $run->id, null);

    expect($result['success'])->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), '192.168.0.10:8080/internal'));
});

it('truncates the response to max_length', function () {
    Http::fake(['*' => Http::response(str_repeat('x', 5000), 200)]);

    $tool = makeConnector([
        'response_extraction' => ['mode' => 'raw', 'expression' => '', 'max_length' => 50],
    ]);
    $run = runForTool($tool);

    $result = executor()->execute($tool, [], $run->id, null);

    expect(mb_strlen($result['result']))->toBe(50)
        ->and($result['result'])->toEndWith('...');
});

it('blocks a connector targeting a link-local / cloud metadata address', function () {
    Http::fake(['*' => Http::response(['leaked' => 'creds'], 200)]);

    $tool = makeConnector(['base_url' => 'http://169.254.169.254', 'path' => '/latest/meta-data']);
    $run = runForTool($tool);

    $result = executor()->execute($tool, [], $run->id, null);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe('Destination host is not allowed.');

    Http::assertNothingSent();
    expect(ExternalToolCallLog::query()->where('agent_run_id', $run->id)->where('success', false)->count())->toBe(1);
});
