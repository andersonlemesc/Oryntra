<?php

declare(strict_types=1);

namespace App\Jobs\Agent;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Services\AgentRuntime\AgentRuntimeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchAgentRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $agentRunId)
    {
        $this->onQueue('agent');
    }

    public function handle(AgentRuntimeClient $runtime): void
    {
        $run = AgentRun::query()->find($this->agentRunId);

        if ($run === null) {
            return;
        }

        $lockKey = "agent-run:{$run->chatwoot_connection_id}:{$run->conversation_id}";

        Cache::lock($lockKey, 120)->block(30, function () use ($run, $runtime): void {
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

            try {
                $run->forceFill([
                    'status' => AgentRunStatus::Running,
                    'started_at' => Carbon::now(),
                ])->save();

                $output = $runtime->run($run);

                DB::transaction(function () use ($run, $output): void {
                    $run->forceFill([
                        'status' => $output['status'] === 'waiting_human'
                            ? AgentRunStatus::WaitingHuman
                            : AgentRunStatus::Completed,
                        'output' => $output,
                        'finished_at' => Carbon::now(),
                    ])->save();

                    $run->webhookEvents()
                        ->where('status', 'debouncing')
                        ->update([
                            'status' => 'processed',
                            'processed_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                });

                Log::info('agent_run completed by runtime', [
                    'agent_run_id' => $run->id,
                    'agent_id' => $run->agent_id,
                    'conversation_id' => $run->conversation_id,
                    'messages' => count($run->input['messages'] ?? []),
                    'runtime_status' => $output['status'],
                ]);
            } catch (Throwable $e) {
                $run->forceFill([
                    'status' => AgentRunStatus::Failed,
                    'error_message' => $e->getMessage(),
                    'finished_at' => Carbon::now(),
                ])->save();

                Log::error('agent_run failed', [
                    'agent_run_id' => $run->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }
}
