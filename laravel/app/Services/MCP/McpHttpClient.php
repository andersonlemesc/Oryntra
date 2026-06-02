<?php

declare(strict_types=1);

namespace App\Services\MCP;

use App\Enums\ExternalToolAuthType;
use App\Models\ExternalTool;
use App\Support\Net\SsrfGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Streamable HTTP MCP client (spec 2025-03-26).
 *
 * Sends JSON-RPC requests to a single endpoint. Session ID is carried as the
 * `Mcp-Session-Id` response header from `initialize` and forwarded on every
 * subsequent call. No SSE channel — request/response only.
 *
 * Security: admin fixes the URL and auth; the LLM only provides tool arguments.
 * The destination host is checked against the SSRF egress denylist and redirects
 * are disabled, matching the external HTTP connector executor.
 */
final class McpHttpClient
{
    private const PROTOCOL_VERSION = '2025-03-26';

    private const DEFAULT_TIMEOUT = 30;

    public function __construct(
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    /**
     * Perform the MCP `initialize` handshake and return a session token.
     * Logs a warning if the server advertises a different protocol version
     * but does not block execution.
     */
    public function initialize(ExternalTool $server): McpSession
    {
        $response = $this->request($server, null)->post(
            $this->url($server),
            $this->rpc('initialize', [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'oryntra', 'version' => '1'],
            ]),
        )->throw();

        $body = $this->parseBody($response->body());
        $sessionId = $response->header('Mcp-Session-Id') ?: null;

        $serverVersion = data_get($body, 'result.protocolVersion');
        if ($serverVersion !== null && $serverVersion !== self::PROTOCOL_VERSION) {
            Log::warning('MCP server protocol version mismatch', [
                'server_slug' => $server->slug,
                'expected' => self::PROTOCOL_VERSION,
                'received' => $serverVersion,
            ]);
        }

        return new McpSession(serverSlug: $server->slug, sessionId: $sessionId);
    }

    /**
     * Fetch the tools exposed by the server.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function listTools(ExternalTool $server, McpSession $session): array
    {
        $response = $this->request($server, $session->sessionId)->post(
            $this->url($server),
            $this->rpc('tools/list'),
        )->throw();

        $tools = data_get($this->parseBody($response->body()), 'result.tools');

        return is_array($tools) ? $tools : [];
    }

    /**
     * Invoke a tool on the server.
     */
    public function callTool(ExternalTool $server, ?string $sessionId, string $name, array $args): McpCallResult
    {
        try {
            $response = $this->request($server, $sessionId)->post(
                $this->url($server),
                $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $args]),
            );
        } catch (Throwable $e) {
            return McpCallResult::failure($e->getMessage());
        }

        $body = $this->parseBody($response->body());

        // JSON-RPC level error
        if (isset($body['error'])) {
            $message = (string) ($body['error']['message'] ?? 'unknown error');
            $data = isset($body['error']['data']) ? ' — ' . json_encode($body['error']['data']) : '';

            return McpCallResult::failure($message . $data);
        }

        $content = data_get($body, 'result.content');
        $isToolError = (bool) data_get($body, 'result.isError', false);
        $text = $this->extractText($content);

        if ($isToolError) {
            return McpCallResult::failure($text ?: 'tool returned isError=true');
        }

        return McpCallResult::success($text);
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method];

        if ($params !== []) {
            $payload['params'] = $params;
        }

        return $payload;
    }

    private function url(ExternalTool $server): string
    {
        return rtrim((string) ($server->config['base_url'] ?? ''), '/');
    }

    private function request(ExternalTool $server, ?string $sessionId): PendingRequest
    {
        $this->assertHostAllowed($server);

        $timeout = is_numeric($server->config['timeout_seconds'] ?? null)
            ? max(1, (int) $server->config['timeout_seconds'])
            : self::DEFAULT_TIMEOUT;

        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];

        if ($sessionId !== null) {
            $headers['Mcp-Session-Id'] = $sessionId;
        }

        $pending = Http::timeout($timeout)->withoutRedirecting()->withHeaders($headers);

        return $this->authenticate($pending, $server);
    }

    /**
     * Reject MCP endpoints that resolve to a blocked network target (link-local,
     * cloud metadata, multicast, reserved). Private/internal hosts stay allowed.
     */
    private function assertHostAllowed(ExternalTool $server): void
    {
        $host = (string) parse_url($this->url($server), PHP_URL_HOST);

        if ($host === '' || $this->ssrfGuard->hostIsBlocked($host)) {
            throw new RuntimeException('MCP server host is not allowed.');
        }
    }

    private function authenticate(PendingRequest $request, ExternalTool $server): PendingRequest
    {
        $authType = ExternalToolAuthType::tryFrom((string) ($server->config['auth_type'] ?? 'none'))
            ?? ExternalToolAuthType::None;

        $credentials = is_array($server->credentials) ? $server->credentials : [];
        $authConfig = is_array($server->config['auth_config'] ?? null) ? $server->config['auth_config'] : [];

        return match ($authType) {
            ExternalToolAuthType::ApiKey => $request->withHeaders([
                (string) ($authConfig['header_name'] ?? 'X-API-Key') => (string) ($credentials['token'] ?? ''),
            ]),
            ExternalToolAuthType::Bearer => $request->withToken((string) ($credentials['token'] ?? '')),
            ExternalToolAuthType::Basic => $request->withBasicAuth(
                (string) ($credentials['username'] ?? ''),
                (string) ($credentials['password'] ?? ''),
            ),
            ExternalToolAuthType::None => $request,
        };
    }

    /**
     * Parse a response body that may be either plain JSON or SSE format.
     *
     * SSE (text/event-stream) wraps each JSON-RPC message as:
     *   event: message
     *   data: {...json...}
     *
     * We extract all `data:` lines, concatenate, and decode.
     *
     * @return array<string, mixed>|null
     */
    private function parseBody(string $body): ?array
    {
        $trimmed = trim($body);

        // Plain JSON: starts with '{' or '['
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);

            return is_array($decoded) ? $decoded : null;
        }

        // SSE: extract concatenated data: lines
        $data = '';
        foreach (explode("\n", $trimmed) as $line) {
            $line = rtrim($line);
            if (str_starts_with($line, 'data:')) {
                $data .= ltrim(substr($line, 5));
            }
        }

        if ($data === '') {
            return null;
        }

        $decoded = json_decode($data, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function extractText(mixed $content): string
    {
        if (! is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'text' && isset($item['text'])) {
                $parts[] = (string) $item['text'];
            }
        }

        return implode("\n", $parts);
    }
}
