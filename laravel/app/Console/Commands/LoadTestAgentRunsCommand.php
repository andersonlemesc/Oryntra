<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\DispatchAgentRunJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Load-test harness for the async agent runtime. Seeds N agent runs (spread
 * across M workspaces with unique conversation ids so the debounce window does
 * not coalesce them) and dispatches them onto the `agent` queue. Horizon and the
 * Python semaphore then enforce the real concurrency ceiling.
 *
 * Pair with AGENT_FAKE_LATENCY_MS on the Python service to emulate the external
 * LLM call, and watch the ceiling with scripts/loadtest-watch.sh.
 *
 * Seeded data is tagged with a "loadtest" name prefix so `--cleanup` can remove
 * it. Intended for dev/staging only.
 */
#[Signature('agent:loadtest {count=50 : Number of runs to dispatch} {--workspaces=1 : Distinct workspaces to spread across} {--cleanup : Remove previously seeded load-test data and exit}')]
#[Description('Seed and dispatch agent runs to load-test the async runtime ceiling')]
class LoadTestAgentRunsCommand extends Command
{
    private const PREFIX = 'loadtest';

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        $count = (int) $this->argument('count');
        $workspaceCount = max(1, (int) $this->option('workspaces'));

        $agents = $this->seedAgents($workspaceCount);

        $this->info("Dispatching {$count} runs across {$workspaceCount} workspace(s)...");

        $firstId = null;
        $lastId = null;

        for ($i = 0; $i < $count; $i++) {
            $agent = $agents[$i % $workspaceCount];

            $run = AgentRun::query()->create([
                'workspace_id' => $agent->workspace_id,
                'agent_id' => $agent->id,
                'chatwoot_connection_id' => null,
                'conversation_id' => 900_000 + $i,
                'chatwoot_account_id' => 990_000 + ($i % $workspaceCount),
                'thread_id' => self::PREFIX . ":{$agent->workspace_id}:conversation:" . (900_000 + $i),
                'status' => AgentRunStatus::Queued,
                'input' => ['messages' => [['id' => (string) $i, 'content' => 'loadtest']]],
            ]);

            DispatchAgentRunJob::dispatch($run->id)->onQueue('agent');

            $firstId ??= $run->id;
            $lastId = $run->id;
        }

        $this->info("Queued runs {$firstId}..{$lastId} on the `agent` queue.");
        $this->line('Watch the ceiling: bash scripts/loadtest-watch.sh');

        return self::SUCCESS;
    }

    /**
     * @return array<int, Agent>
     */
    private function seedAgents(int $workspaceCount): array
    {
        $agents = [];

        for ($w = 0; $w < $workspaceCount; $w++) {
            $workspace = Workspace::factory()->create(['name' => self::PREFIX . "-ws-{$w}-" . uniqid()]);
            $agents[] = Agent::factory()->active()->for($workspace)->create([
                'name' => self::PREFIX . "-agent-{$w}",
            ]);
        }

        return $agents;
    }

    private function cleanup(): int
    {
        $workspaceIds = Workspace::query()
            ->where('name', 'like', self::PREFIX . '-ws-%')
            ->pluck('id');

        AgentRun::query()->whereIn('workspace_id', $workspaceIds)->delete();
        Agent::query()->whereIn('workspace_id', $workspaceIds)->delete();
        Workspace::query()->whereIn('id', $workspaceIds)->delete();

        $this->info("Removed {$workspaceIds->count()} load-test workspace(s) and their runs.");

        return self::SUCCESS;
    }
}
