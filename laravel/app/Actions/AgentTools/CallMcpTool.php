<?php

declare(strict_types=1);

namespace App\Actions\AgentTools;

use App\Enums\ExternalToolKind;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ExternalTool;
use App\Models\ExternalToolCallLog;
use App\Services\MCP\McpHttpClient;
use Illuminate\Validation\ValidationException;

class CallMcpTool
{
    public function __construct(
        private readonly McpHttpClient $client,
    ) {}

    /**
     * @param  array{workspace_id:int,agent_id:int,agent_run_id:int,specialist_id?:int|null,server_slug:string,session_id?:string|null,tool_name:string,args?:array<string,mixed>} $payload
     * @return array{result:string,success:bool,error:string|null}
     */
    public function execute(array $payload): array
    {
        $run = $this->loadRun($payload);
        $server = $this->resolveServer($payload);
        $this->assertSpecialistCanUse($payload, $server->slug);

        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        $sessionId = isset($payload['session_id']) && is_string($payload['session_id']) ? $payload['session_id'] : null;
        $toolName = (string) $payload['tool_name'];

        $start = microtime(true);
        $result = $this->client->callTool($server, $sessionId, $toolName, $args);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $this->log($server, $payload, $run->id, $toolName, $latencyMs, $result->text, $result->isError, $result->error);

        return [
            'result' => $result->text,
            'success' => ! $result->isError,
            'error' => $result->error,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function loadRun(array $payload): AgentRun
    {
        $run = AgentRun::query()
            ->where('id', $payload['agent_run_id'])
            ->where('workspace_id', $payload['workspace_id'])
            ->where('agent_id', $payload['agent_id'])
            ->first();

        if (! $run instanceof AgentRun) {
            throw ValidationException::withMessages([
                'agent_run_id' => 'The agent run does not match the workspace and agent.',
            ]);
        }

        return $run;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveServer(array $payload): ExternalTool
    {
        $server = ExternalTool::query()
            ->where('workspace_id', $payload['workspace_id'])
            ->where('slug', $payload['server_slug'])
            ->where('kind', ExternalToolKind::Mcp)
            ->enabled()
            ->first();

        if (! $server instanceof ExternalTool) {
            throw ValidationException::withMessages([
                'server_slug' => 'The MCP server does not exist, is disabled, or belongs to another workspace.',
            ]);
        }

        return $server;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertSpecialistCanUse(array $payload, string $serverSlug): void
    {
        $specialistId = $payload['specialist_id'] ?? null;

        if ($specialistId === null) {
            return;
        }

        $specialist = AgentSpecialist::query()
            ->where('id', $specialistId)
            ->where('workspace_id', $payload['workspace_id'])
            ->where('agent_id', $payload['agent_id'])
            ->first();

        if (! $specialist instanceof AgentSpecialist) {
            throw ValidationException::withMessages([
                'specialist_id' => 'The specialist does not belong to this workspace and agent.',
            ]);
        }

        $toolsAllowlist = is_array($specialist->tools_allowlist) ? $specialist->tools_allowlist : [];

        if (! in_array($serverSlug, $toolsAllowlist, true)) {
            throw ValidationException::withMessages([
                'specialist_id' => "The specialist is not allowed to use MCP server '{$serverSlug}'.",
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function log(
        ExternalTool $server,
        array $payload,
        int $agentRunId,
        string $toolName,
        int $latencyMs,
        string $excerpt,
        bool $isError,
        ?string $error,
    ): void {
        ExternalToolCallLog::query()->create([
            'workspace_id' => $server->workspace_id,
            'external_tool_id' => $server->id,
            'agent_run_id' => $agentRunId,
            'specialist_id' => $payload['specialist_id'] ?? null,
            'tool_slug' => $server->slug . '__' . $toolName,
            'request_args' => is_array($payload['args'] ?? null) ? $payload['args'] : [],
            'http_status' => null,
            'success' => ! $isError,
            'latency_ms' => $latencyMs,
            'response_excerpt' => mb_substr($excerpt, 0, 500),
            'error' => $error,
        ]);
    }
}
