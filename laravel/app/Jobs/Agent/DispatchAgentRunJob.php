<?php

declare(strict_types=1);

namespace App\Jobs\Agent;

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\Middleware\ThrottleAgentRunsPerWorkspace;
use App\Models\AgentRun;
use App\Services\AgentRuntime\AgentRuntimeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches an agent run to the Python runtime and frees the worker
 * immediately. The runtime executes the graph asynchronously and posts the
 * result back through the internal callback endpoint, which then enqueues
 * {@see FinalizeAgentRunJob} to deliver the response and finalize the run.
 *
 * If the runtime is unreachable the job retries; once retries are exhausted
 * the run is marked failed. Runs that are accepted but never complete (Python
 * crash) are swept by the stuck-run reaper.
 */
class DispatchAgentRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 45];

    public function __construct(public int $agentRunId)
    {
        $this->onQueue('agent');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new ThrottleAgentRunsPerWorkspace];
    }

    public function handle(AgentRuntimeClient $runtime): void
    {
        $run = AgentRun::query()->find($this->agentRunId);

        if ($run === null) {
            return;
        }

        $lockKey = "agent-run:{$run->chatwoot_connection_id}:{$run->conversation_id}";

        Cache::lock($lockKey, 30)->block(30, function () use ($run, $runtime): void {
            $run->refresh();
            $status = $run->status;

            if (! $status instanceof AgentRunStatus || ! $status->isInFlight()) {
                return;
            }

            if ($status === AgentRunStatus::Debouncing && $run->debounce_until !== null) {
                $now = Carbon::now();
                $debounceUntil = Carbon::parse($run->debounce_until);

                if ($debounceUntil->isFuture()) {
                    self::dispatch($run->id)
                        ->onQueue('agent')
                        ->delay((int) $debounceUntil->diffInSeconds($now) + 1);

                    return;
                }
            }

            // Per-conversation ordering is guaranteed by the partial unique index
            // `agent_runs_one_inflight_per_conversation` (one in-flight run per
            // conversation), so no extra ordering guard is needed here: a second
            // run for the same conversation can only exist once this one is
            // terminal. EnqueueAgentRunForEvent appends to the in-flight run.

            // Fire-and-forget: Python accepts (202) and posts the result back
            // via the internal callback. We only flip to Running once the
            // runtime has accepted the work, so a retry after an unreachable
            // runtime cannot trigger a duplicate run.
            $accepted = $runtime->start($run);

            // Backpressure: the runtime is at capacity (503). Release back onto
            // the queue so the backlog stays in Redis (visible, throttled, and
            // outside the run-timeout window) instead of piling up inside Python.
            if (! $accepted) {
                $this->release(random_int(3, 8));

                return;
            }

            $run->forceFill([
                'status' => AgentRunStatus::Running,
                'started_at' => Carbon::now(),
            ])->save();

            Log::info('agent_run dispatched to runtime', [
                'agent_run_id' => $run->id,
                'agent_id' => $run->agent_id,
                'conversation_id' => $run->conversation_id,
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $run = AgentRun::query()->find($this->agentRunId);

        if ($run === null) {
            return;
        }

        $status = $run->status;

        if ($status instanceof AgentRunStatus && $status->isTerminal()) {
            return;
        }

        $run->forceFill([
            'status' => AgentRunStatus::Failed,
            'error_message' => $exception?->getMessage() ?? 'agent_runtime_dispatch_failed',
            'finished_at' => Carbon::now(),
        ])->save();

        Log::error('agent_run dispatch failed', [
            'agent_run_id' => $run->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
