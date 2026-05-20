<?php

declare(strict_types=1);

namespace App\Actions\AgentRuns;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectAgentRun
{
    public function execute(AgentRun $run, ?int $actorId, string $reason): AgentRun
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A rejection reason is required.',
            ]);
        }

        if ($run->status !== AgentRunStatus::WaitingHuman) {
            throw ValidationException::withMessages([
                'status' => 'Only runs waiting for human review can be rejected.',
            ]);
        }

        return DB::transaction(function () use ($run, $actorId, $reason): AgentRun {
            $locked = AgentRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof AgentRun || $locked->status !== AgentRunStatus::WaitingHuman) {
                throw ValidationException::withMessages([
                    'status' => 'Only runs waiting for human review can be rejected.',
                ]);
            }

            $output = is_array($locked->output) ? $locked->output : [];
            $output['hitl'] = [
                'decision' => 'rejected',
                'actor_id' => $actorId,
                'decided_at' => Carbon::now()->toISOString(),
                'reason' => $reason,
            ];

            $locked->update([
                'status' => AgentRunStatus::Failed,
                'output' => $output,
                'error_message' => $reason,
                'finished_at' => Carbon::now(),
            ]);

            return $locked->refresh();
        });
    }
}
