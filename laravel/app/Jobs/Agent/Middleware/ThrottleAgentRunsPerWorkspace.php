<?php

declare(strict_types=1);

namespace App\Jobs\Agent\Middleware;

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\DispatchAgentRunJob;
use App\Models\AgentRun;
use Closure;

/**
 * Caps how many agent runs a single workspace (tenant / "account") may have
 * executing at the Python runtime concurrently. Without this the shared `agent`
 * queue is FIFO and global: a tenant with a large BYOK quota could flood it and
 * starve every other tenant, since the bottleneck is our shared worker pool —
 * not their LLM key. Over the cap, the job is released back with a short
 * randomized backoff so other workspaces get a turn.
 *
 * The count is a best-effort admission gate (a small race may let one extra run
 * through); it is a fairness throttle, not a hard limit.
 */
class ThrottleAgentRunsPerWorkspace
{
    public function handle(DispatchAgentRunJob $job, Closure $next): mixed
    {
        $cap = (int) config('services.agent_runtime.max_concurrency_per_account', 0);

        if ($cap <= 0) {
            return $next($job);
        }

        $run = AgentRun::query()->find($job->agentRunId);

        if ($run === null) {
            return $next($job);
        }

        $active = AgentRun::query()
            ->where('workspace_id', $run->workspace_id)
            ->where('status', AgentRunStatus::Running->value)
            ->where('id', '!=', $run->id)
            ->count();

        if ($active >= $cap) {
            $job->release(random_int(5, 15));

            return null;
        }

        return $next($job);
    }
}
