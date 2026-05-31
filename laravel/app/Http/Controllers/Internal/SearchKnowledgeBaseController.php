<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\AgentTools\SearchKnowledgeBase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\SearchKnowledgeBaseRequest;
use Illuminate\Http\JsonResponse;

class SearchKnowledgeBaseController extends Controller
{
    public function __invoke(
        SearchKnowledgeBaseRequest $request,
        SearchKnowledgeBase $search,
    ): JsonResponse {
        $validated = $request->validated();

        $result = $search->execute(
            workspaceId: $validated['workspace_id'],
            agentId: $validated['agent_id'],
            agentRunId: $validated['agent_run_id'],
            specialistId: $validated['specialist_id'] ?? null,
            query: $validated['query'],
            topK: $validated['top_k'] ?? 5,
            minScore: isset($validated['min_score']) ? (float) $validated['min_score'] : 0.0,
            tags: $validated['tags'] ?? null,
        );

        return response()->json($result);
    }
}
