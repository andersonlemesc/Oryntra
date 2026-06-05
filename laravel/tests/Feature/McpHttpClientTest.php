<?php

declare(strict_types=1);

use App\Models\ExternalTool;
use App\Models\Workspace;
use App\Services\MCP\McpCallResult;
use App\Services\MCP\McpHttpClient;
use App\Services\MCP\McpSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function makeMcpServer(?string $authType = 'none', string $baseUrl = 'https://mcp.example.test/mcp'): ExternalTool
{
    $workspace = Workspace::factory()->create();

    return ExternalTool::factory()->mcp()->for($workspace)->create([
        'config' => [
            'base_url' => $baseUrl,
            'auth_type' => $authType ?? 'none',
            'auth_config' => [],
            'timeout_seconds' => 10,
        ],
        'credentials' => $authType !== 'none' ? ['token' => 'secret-token'] : null,
    ]);
}

it('sends initialize JSON-RPC and returns session id from header', function () {
    Http::fake([
        'https://mcp.example.test/mcp' => Http::response(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-03-26', 'capabilities' => []]],
            200,
            ['Mcp-Session-Id' => 'sess-abc123'],
        ),
    ]);

    $server = makeMcpServer();
    $session = app(McpHttpClient::class)->initialize($server);

    expect($session->serverSlug)->toBe($server->slug)
        ->and($session->sessionId)->toBe('sess-abc123');

    Http::assertSent(function ($request) {
        return $request->data()['method'] === 'initialize'
            && $request->data()['params']['protocolVersion'] === '2025-03-26';
    });
});

it('returns null session id when Mcp-Session-Id header is absent', function () {
    Http::fake([
        '*' => Http::response(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-03-26']],
            200,
        ),
    ]);

    $server = makeMcpServer();
    $session = app(McpHttpClient::class)->initialize($server);

    expect($session->sessionId)->toBeNull();
});

it('lists tools and returns parsed array', function () {
    $rawTools = [
        ['name' => 'get_order', 'description' => 'Get order by ID', 'inputSchema' => ['type' => 'object', 'properties' => ['order_id' => ['type' => 'string']], 'required' => ['order_id']]],
        ['name' => 'list_orders', 'description' => 'List orders', 'inputSchema' => ['type' => 'object', 'properties' => []]],
    ];

    Http::fake([
        '*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => $rawTools]]),
    ]);

    $server = makeMcpServer();
    $session = new McpSession(serverSlug: $server->slug, sessionId: 'sess-1');
    $result = app(McpHttpClient::class)->listTools($server, $session);

    expect($result)->toHaveCount(2)
        ->and($result[0]['name'])->toBe('get_order');

    Http::assertSent(function ($request) {
        return $request->data()['method'] === 'tools/list'
            && $request->header('Mcp-Session-Id')[0] === 'sess-1';
    });
});

it('calls a tool and extracts text content from result', function () {
    Http::fake([
        '*' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['content' => [['type' => 'text', 'text' => 'Order 123: shipped']]],
        ]),
    ]);

    $server = makeMcpServer();
    $result = app(McpHttpClient::class)->callTool($server, 'sess-1', 'get_order', ['order_id' => '123']);

    expect($result)->toBeInstanceOf(McpCallResult::class)
        ->and($result->isError)->toBeFalse()
        ->and($result->text)->toBe('Order 123: shipped');
});

it('returns non-fatal error result on JSON-RPC error response', function () {
    Http::fake([
        '*' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => -32603, 'message' => 'Internal error', 'data' => 'db timeout'],
        ]),
    ]);

    $server = makeMcpServer();
    $result = app(McpHttpClient::class)->callTool($server, null, 'get_order', []);

    expect($result->isError)->toBeTrue()
        ->and($result->error)->toContain('Internal error');
});

it('returns non-fatal error when result isError flag is true', function () {
    Http::fake([
        '*' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Record not found']]],
        ]),
    ]);

    $server = makeMcpServer();
    $result = app(McpHttpClient::class)->callTool($server, null, 'get_order', []);

    expect($result->isError)->toBeTrue()
        ->and($result->error)->toContain('Record not found');
});

it('injects Bearer token in Authorization header when auth_type is bearer', function () {
    Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []]])]);

    $server = makeMcpServer(authType: 'bearer');
    $session = new McpSession(serverSlug: $server->slug, sessionId: null);
    app(McpHttpClient::class)->listTools($server, $session);

    Http::assertSent(fn ($r) => str_starts_with($r->header('Authorization')[0] ?? '', 'Bearer '));
});

it('listTools returns empty array when tools key is absent', function () {
    Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []])]);

    $server = makeMcpServer();
    $session = new McpSession(serverSlug: $server->slug, sessionId: null);
    $result = app(McpHttpClient::class)->listTools($server, $session);

    expect($result)->toBeArray()->toBeEmpty();
});

it('parses SSE-format initialize response and extracts session id from header', function () {
    $sseBody = "event: message\ndata: {\"result\":{\"protocolVersion\":\"2025-03-26\",\"capabilities\":{\"tools\":{}}},\"jsonrpc\":\"2.0\",\"id\":1}\n";

    Http::fake([
        '*' => Http::response($sseBody, 200, [
            'Content-Type' => 'text/event-stream',
            'Mcp-Session-Id' => 'sse-sess-abc',
        ]),
    ]);

    $server = makeMcpServer();
    $session = app(McpHttpClient::class)->initialize($server);

    expect($session->sessionId)->toBe('sse-sess-abc');
});

it('parses SSE-format listTools response and returns tools array', function () {
    $tool = ['name' => 'Calculator', 'description' => 'Math tool', 'inputSchema' => ['type' => 'object', 'properties' => ['input' => ['type' => 'string']]]];
    $sseBody = "event: message\ndata: {\"result\":{\"tools\":[" . json_encode($tool) . "]},\"jsonrpc\":\"2.0\",\"id\":2}\n";

    Http::fake([
        '*' => Http::response($sseBody, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $server = makeMcpServer();
    $session = new McpSession(serverSlug: $server->slug, sessionId: 'sess-1');
    $result = app(McpHttpClient::class)->listTools($server, $session);

    expect($result)->toHaveCount(1)
        ->and($result[0]['name'])->toBe('Calculator');
});

it('parses SSE-format callTool response and extracts text content', function () {
    $sseBody = "event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"42\"}]},\"jsonrpc\":\"2.0\",\"id\":3}\n";

    Http::fake([
        '*' => Http::response($sseBody, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $server = makeMcpServer();
    $result = app(McpHttpClient::class)->callTool($server, 'sess-1', 'Calculator', ['input' => '6*7']);

    expect($result->isError)->toBeFalse()
        ->and($result->text)->toBe('42');
});

it('blocks an MCP server targeting a link-local / cloud metadata address', function () {
    Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => []]], 200)]);

    $server = makeMcpServer('none', 'http://169.254.169.254/mcp');

    $result = app(McpHttpClient::class)->callTool($server, null, 'get_order', []);

    expect($result->isError)->toBeTrue()
        ->and($result->error)->toBe('MCP server host is not allowed.');

    Http::assertNothingSent();
});

it('throws when initialize targets a blocked address', function () {
    Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], 200)]);

    $server = makeMcpServer('none', 'http://169.254.169.254/mcp');

    expect(fn () => app(McpHttpClient::class)->initialize($server))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});
