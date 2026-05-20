<?php

declare(strict_types=1);

namespace App\Actions\AgentRuns;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Services\AgentRuntime\AgentRuntimeClient;
use App\Services\AgentRuntime\AgentRuntimeException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveAgentRun
{
    public function __construct(private readonly AgentRuntimeClient $runtime) {}

    public function execute(
        AgentRun $run,
        ?int $actorId,
        string $decision = 'approved',
        ?string $originalContent = null,
        bool $notifyRuntime = true,
    ): AgentRun {
        if ($run->status !== AgentRunStatus::WaitingHuman) {
            throw ValidationException::withMessages([
                'status' => 'Only runs waiting for human review can be approved.',
            ]);
        }

        $approved = DB::transaction(function () use ($run, $actorId, $decision, $originalContent): AgentRun {
            $locked = AgentRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof AgentRun || $locked->status !== AgentRunStatus::WaitingHuman) {
                throw ValidationException::withMessages([
                    'status' => 'Only runs waiting for human review can be approved.',
                ]);
            }

            $output = is_array($locked->output) ? $locked->output : [];
            $output['hitl'] = [
                'decision' => $decision,
                'actor_id' => $actorId,
                'decided_at' => Carbon::now()->toISOString(),
                'original_content' => $originalContent,
            ];

            $locked->update([
                'status' => AgentRunStatus::Completed,
                'output' => $output,
                'finished_at' => Carbon::now(),
            ]);

            return $locked->refresh();
        });

        if ($notifyRuntime) {
            try {
                $this->runtime->resume($approved, [
                    'decision' => $decision,
                    'response_content' => data_get($approved->output, 'response.content'),
                    'actor_id' => $actorId,
                ]);
            } catch (AgentRuntimeException) {
                // Resume failure is logged inside the runtime client.
                // The local state transition is already persisted.
            }
        }

        return $approved;
    }
}
