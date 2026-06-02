<?php

declare(strict_types=1);

namespace App\Services\AgentTools;

use App\Enums\ExternalToolAuthType;
use App\Enums\ExternalToolParamLocation;
use App\Models\ExternalTool;
use App\Models\ExternalToolCallLog;
use App\Support\Net\SsrfGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Executes an admin-defined HTTP connector: validates the LLM args against the
 * connector's param schema, builds the outbound request (path/query/body/header
 * + auth), calls the external API, extracts a compact result for the LLM and
 * records an audit row in ``external_tool_call_logs``.
 *
 * Security posture (trust-admin): the LLM never controls the URL/host — it only
 * fills declared params; ``base_url`` and auth are fixed by the admin. Network
 * guards: scheme allowlist (http/https), an SSRF egress denylist (blocks
 * link-local/metadata, multicast and reserved targets while still allowing
 * private/internal APIs) and redirects are disabled so a response cannot bounce
 * the request to an unchecked host.
 */
final class ExternalToolExecutor
{
    private const DEFAULT_TIMEOUT = 10;

    private const DEFAULT_MAX_LENGTH = 2000;

    public function __construct(
        private readonly ExternalToolSchemaBuilder $schemaBuilder,
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    /**
     * @param  array<string, mixed>                                                            $args
     * @return array{result: string, success: bool, error: string|null, http_status: int|null}
     */
    public function execute(ExternalTool $tool, array $args, int $agentRunId, ?int $specialistId = null): array
    {
        $config = $tool->config;
        $properties = $this->schemaBuilder->properties($tool->paramSchema());

        $validationError = $this->validateArgs($args, $properties);
        if ($validationError !== null) {
            return $this->fail($tool, $args, $agentRunId, $specialistId, $validationError, null);
        }

        $partitioned = $this->partitionArgs($args, $properties);

        $url = $this->buildUrl(
            (string) ($config['base_url'] ?? ''),
            (string) ($config['path'] ?? ''),
            $partitioned[ExternalToolParamLocation::Path->value],
        );

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return $this->fail($tool, $args, $agentRunId, $specialistId, 'URL scheme must be http or https.', null);
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || $this->ssrfGuard->hostIsBlocked($host)) {
            return $this->fail($tool, $args, $agentRunId, $specialistId, 'Destination host is not allowed.', null);
        }

        $method = strtoupper((string) ($config['http_method'] ?? 'GET'));
        $timeout = is_numeric($config['timeout_seconds'] ?? null)
            ? max(1, (int) $config['timeout_seconds'])
            : self::DEFAULT_TIMEOUT;

        $headers = $this->buildHeaders($config, $partitioned[ExternalToolParamLocation::Header->value]);
        $query = $partitioned[ExternalToolParamLocation::Query->value];
        $body = $partitioned[ExternalToolParamLocation::Body->value];

        $request = $this->authenticate(Http::timeout($timeout)->withoutRedirecting()->withHeaders($headers), $tool);

        if ($method === 'GET') {
            $request = $request->retry(2, 200, throw: false);
        }

        $start = microtime(true);

        try {
            $response = match ($method) {
                'GET' => $request->get($url, $query),
                'DELETE' => $request->delete($url, $body),
                'POST' => $request->post($url, $body),
                'PUT' => $request->put($url, $body),
                'PATCH' => $request->patch($url, $body),
                default => $request->get($url, $query),
            };
        } catch (Throwable $exception) {
            $latency = $this->elapsedMs($start);

            return $this->fail($tool, $args, $agentRunId, $specialistId, $exception->getMessage(), null, $latency);
        }

        $latency = $this->elapsedMs($start);
        $status = $response->status();
        $maxLength = is_numeric($config['response_extraction']['max_length'] ?? null)
            ? max(1, (int) $config['response_extraction']['max_length'])
            : self::DEFAULT_MAX_LENGTH;

        if ($response->failed()) {
            $excerpt = $this->truncate($response->body(), $maxLength);

            return $this->fail(
                $tool,
                $args,
                $agentRunId,
                $specialistId,
                "HTTP {$status}",
                $status,
                $latency,
                $excerpt,
            );
        }

        $result = $this->extractResponse($config, $response->json(), $response->body(), $maxLength);

        $this->log($tool, $args, $agentRunId, $specialistId, $status, true, $latency, $result, null);

        return ['result' => $result, 'success' => true, 'error' => null, 'http_status' => $status];
    }

    /**
     * @param array<string, mixed>                $args
     * @param array<string, array<string, mixed>> $properties
     */
    private function validateArgs(array $args, array $properties): ?string
    {
        foreach ($args as $key => $value) {
            if (! array_key_exists($key, $properties)) {
                return "Unknown parameter '{$key}'.";
            }
        }

        foreach ($properties as $name => $definition) {
            $required = (bool) ($definition['required'] ?? false);
            $present = array_key_exists($name, $args) && $args[$name] !== null && $args[$name] !== '';

            if ($required && ! $present) {
                return "Missing required parameter '{$name}'.";
            }

            if ($present && ! $this->matchesType($args[$name], (string) ($definition['type'] ?? 'string'))) {
                return "Parameter '{$name}' must be of type " . ($definition['type'] ?? 'string') . '.';
            }
        }

        return null;
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
            'string' => is_scalar($value),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>                                                                                                     $args
     * @param  array<string, array<string, mixed>>                                                                                      $properties
     * @return array{query: array<string, mixed>, path: array<string, mixed>, body: array<string, mixed>, header: array<string, mixed>}
     */
    private function partitionArgs(array $args, array $properties): array
    {
        $buckets = [
            ExternalToolParamLocation::Query->value => [],
            ExternalToolParamLocation::Path->value => [],
            ExternalToolParamLocation::Body->value => [],
            ExternalToolParamLocation::Header->value => [],
        ];

        foreach ($properties as $name => $definition) {
            if (! array_key_exists($name, $args) || $args[$name] === null) {
                continue;
            }

            $location = (string) ($definition['location'] ?? ExternalToolParamLocation::Query->value);
            $buckets[$location][$name] = $args[$name];
        }

        return $buckets;
    }

    /**
     * @param array<string, mixed> $pathParams
     */
    private function buildUrl(string $baseUrl, string $path, array $pathParams): string
    {
        $base = rtrim($baseUrl, '/');
        $url = trim($path) === '' ? $base : $base . '/' . ltrim($path, '/');

        foreach ($pathParams as $name => $value) {
            $url = str_replace('{' . $name . '}', rawurlencode((string) $value), $url);
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $headerParams
     * @return array<string, string>
     */
    private function buildHeaders(array $config, array $headerParams): array
    {
        $headers = [];

        $static = $config['static_headers'] ?? [];
        if (is_array($static)) {
            foreach ($static as $key => $value) {
                if (is_string($key)) {
                    $headers[$key] = (string) $value;
                }
            }
        }

        foreach ($headerParams as $key => $value) {
            $headers[$key] = (string) $value;
        }

        return $headers;
    }

    private function authenticate(PendingRequest $request, ExternalTool $tool): PendingRequest
    {
        $authType = ExternalToolAuthType::tryFrom((string) ($tool->config['auth_type'] ?? 'none'))
            ?? ExternalToolAuthType::None;

        $credentials = is_array($tool->credentials) ? $tool->credentials : [];
        $authConfig = is_array($tool->config['auth_config'] ?? null) ? $tool->config['auth_config'] : [];

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
     * @param array<string, mixed> $config
     */
    private function extractResponse(array $config, mixed $json, string $rawBody, int $maxLength): string
    {
        $extraction = is_array($config['response_extraction'] ?? null) ? $config['response_extraction'] : [];
        $mode = (string) ($extraction['mode'] ?? 'raw');
        $expression = (string) ($extraction['expression'] ?? '');

        $value = match ($mode) {
            'jsonpath' => $this->resolvePath($json, $expression),
            'template' => $this->renderTemplate($json, $expression),
            default => $json ?? $rawBody,
        };

        if ($value === null) {
            $value = $rawBody;
        }

        $stringified = is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->truncate($stringified, $maxLength);
    }

    private function resolvePath(mixed $json, string $expression): mixed
    {
        $path = ltrim(trim($expression), '$');
        $path = ltrim($path, '.');

        if ($path === '') {
            return $json;
        }

        return data_get($json, $path);
    }

    private function renderTemplate(mixed $json, string $template): string
    {
        return (string) preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/', function (array $matches) use ($json): string {
            $resolved = $this->resolvePath($json, (string) $matches[1]);

            if (is_scalar($resolved)) {
                return (string) $resolved;
            }

            return (string) json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $template);
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, max(0, $limit - 3)) . '...';
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    /**
     * @param  array<string, mixed>                                                            $args
     * @return array{result: string, success: bool, error: string|null, http_status: int|null}
     */
    private function fail(
        ExternalTool $tool,
        array $args,
        int $agentRunId,
        ?int $specialistId,
        string $error,
        ?int $status,
        int $latency = 0,
        ?string $excerpt = null,
    ): array {
        $this->log($tool, $args, $agentRunId, $specialistId, $status, false, $latency, $excerpt, $error);

        return ['result' => "error: {$error}", 'success' => false, 'error' => $error, 'http_status' => $status];
    }

    /**
     * @param array<string, mixed> $args
     */
    private function log(
        ExternalTool $tool,
        array $args,
        int $agentRunId,
        ?int $specialistId,
        ?int $status,
        bool $success,
        int $latency,
        ?string $excerpt,
        ?string $error,
    ): void {
        ExternalToolCallLog::query()->create([
            'workspace_id' => $tool->workspace_id,
            'external_tool_id' => $tool->id,
            'agent_run_id' => $agentRunId,
            'specialist_id' => $specialistId,
            'tool_slug' => $tool->slug,
            'request_args' => $args,
            'http_status' => $status,
            'success' => $success,
            'latency_ms' => $latency,
            'response_excerpt' => $excerpt,
            'error' => $error,
        ]);
    }
}
