<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Services\AgentTools\NativeTool;
use App\Services\Products\ProductSearchService;
use Illuminate\Validation\ValidationException;

class QueryProducts
{
    public function __construct(
        private ProductSearchService $searchService,
    ) {}

    /**
     * @return array{products: array<int, array<string, mixed>>, total: int}
     */
    public function execute(
        int $workspaceId,
        int $agentId,
        int $agentRunId,
        ?int $specialistId = null,
        ?string $query = null,
        ?string $category = null,
        ?float $minPrice = null,
        ?float $maxPrice = null,
        int $limit = 20,
    ): array {
        $this->assertRunMatchesAgent($workspaceId, $agentId, $agentRunId);
        $this->assertSpecialistCanUse($workspaceId, $agentId, $specialistId);

        return $this->searchService->search(
            workspaceId: $workspaceId,
            query: $query,
            category: $category,
            minPrice: $minPrice,
            maxPrice: $maxPrice,
            activeOnly: true,
            limit: $limit,
            agentId: $agentId,
        );
    }

    private function assertRunMatchesAgent(int $workspaceId, int $agentId, int $agentRunId): void
    {
        $exists = AgentRun::query()
            ->where('id', $agentRunId)
            ->where('workspace_id', $workspaceId)
            ->where('agent_id', $agentId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'agent_run_id' => 'The agent run does not match the workspace and agent.',
            ]);
        }
    }

    private function assertSpecialistCanUse(int $workspaceId, int $agentId, ?int $specialistId): void
    {
        if ($specialistId === null) {
            return;
        }

        $specialist = AgentSpecialist::query()
            ->where('id', $specialistId)
            ->where('workspace_id', $workspaceId)
            ->where('agent_id', $agentId)
            ->first();

        if (! $specialist instanceof AgentSpecialist) {
            throw ValidationException::withMessages([
                'specialist_id' => 'The specialist does not belong to this workspace and agent.',
            ]);
        }

        $toolsAllowlist = is_array($specialist->tools_allowlist) ? $specialist->tools_allowlist : [];

        if (! in_array(NativeTool::QueryProducts->value, $toolsAllowlist, true)) {
            throw ValidationException::withMessages([
                'specialist_id' => "The specialist is not allowed to call tool '" . NativeTool::QueryProducts->value . "'.",
            ]);
        }
    }
}
