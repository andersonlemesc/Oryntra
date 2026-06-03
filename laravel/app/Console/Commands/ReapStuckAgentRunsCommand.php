<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\FinalizeAgentRunJob;
use App\Models\AgentRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fails agent runs that were dispatched to the Python runtime but never reported
 * back. With the async (fire-and-forget) flow the synchronous HTTP timeout no
 * longer protects us: if Python crashes mid-run the callback never arrives and
 * the run would stay `Running` forever. This sweep marks runs that have been
 * `Running` past the runtime timeout as failed, reusing FinalizeAgentRunJob so
 * the same idempotent finalization path runs (webhook events, status).
 */
#[Signature('agent:reap-stuck-runs')]
#[Description('Fail agent runs stuck in Running past the runtime timeout')]
class ReapStuckAgentRunsCommand extends Command
{
    public function handle(): int
    {
        $timeout = (int) config('services.agent_runtime.run_timeout', 180);
        $cutoff = Carbon::now()->subSeconds($timeout);

        $stuck = AgentRun::query()
            ->where('status', AgentRunStatus::Running->value)
            ->where('started_at', '<', $cutoff)
            ->pluck('id');

        foreach ($stuck as $runId) {
            FinalizeAgentRunJob::dispatch((int) $runId, [
                'status' => AgentRunStatus::Failed->value,
                'error' => 'runtime_timeout',
            ]);
        }

        $count = $stuck->count();

        if ($count > 0) {
            $this->info("Reaped {$count} stuck agent run(s).");
        }

        return self::SUCCESS;
    }
}
