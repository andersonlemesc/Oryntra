<?php

declare(strict_types=1);

namespace App\Actions\AgentTools;

use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\ExternalTool;
use App\Services\AgentTools\ExternalToolExecutor;
use Illuminate\Validation\ValidationException;

class CallExternalTool
{
    public function __construct(
        private readonly ExternalToolExecutor $executor,
    ) {}

    /**
     * @param  array{workspace_id:int,agent_id:int,agent_run_id:int,specialist_id?:int|null,external_tool_slug:string,args?:array<string,mixed>} $payload
     * @return array{result:string,success:bool,error:string|null,http_status:int|null}
     */
    public function execute(array $payload): array
    {
        $run = $this->loadRun($payload);
        $tool = $this->resolveTool($payload);
        $this->assertSpecialistCanUse($payload, $tool->slug);

        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];

        return $this->executor->execute(
            tool: $tool,
            args: $args,
            agentRunId: $run->id,
            specialistId: $payload['specialist_id'] ?? null,
        );
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
    private function resolveTool(array $payload): ExternalTool
    {
        $tool = ExternalTool::query()
            ->where('workspace_id', $payload['workspace_id'])
            ->where('slug', $payload['external_tool_slug'])
            ->enabled()
            ->first();

        if (! $tool instanceof ExternalTool) {
            throw ValidationException::withMessages([
                'external_tool_slug' => 'The external tool does not exist, is disabled, or belongs to another workspace.',
            ]);
        }

        return $tool;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertSpecialistCanUse(array $payload, string $tool): void
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

        if (! in_array($tool, $toolsAllowlist, true)) {
            throw ValidationException::withMessages([
                'specialist_id' => "The specialist is not allowed to call tool '{$tool}'.",
            ]);
        }
    }
}
