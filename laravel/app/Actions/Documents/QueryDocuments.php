<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\DocumentCategory;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\Document;
use App\Services\AgentTools\NativeTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class QueryDocuments
{
    /**
     * @return array{documents: array<int, array<string, mixed>>, total: int}
     */
    public function execute(
        int $workspaceId,
        int $agentId,
        int $agentRunId,
        ?int $specialistId = null,
        ?string $query = null,
        ?string $category = null,
        int $limit = 20,
    ): array {
        $this->assertRunMatchesAgent($workspaceId, $agentId, $agentRunId);
        $allowedCategories = $this->resolveAllowedCategories($workspaceId, $agentId, $specialistId);

        $q = Document::query()
            ->sendable()
            ->where('workspace_id', $workspaceId);

        if ($allowedCategories !== null) {
            $q->whereIn('category', $allowedCategories);
        }

        if ($category !== null && $category !== '') {
            $q->where('category', $category);
        }

        if ($query !== null && $query !== '') {
            $operator = Document::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $like = '%' . $query . '%';

            $q->where(function (Builder $sub) use ($operator, $like): void {
                $sub->where('title', $operator, $like)
                    ->orWhere('description', $operator, $like)
                    ->orWhere('original_filename', $operator, $like);
            });
        }

        $total = $q->count();
        $documents = $q->orderBy('title')
            ->limit($limit)
            ->get()
            ->map(fn (Document $document): array => $document->toAgentPayload())
            ->all();

        return ['documents' => $documents, 'total' => $total];
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

    /**
     * Resolve the categories this specialist may read. Returns null when no
     * specialist scoping applies (all sendable categories allowed).
     *
     * @return array<int, string>|null
     */
    private function resolveAllowedCategories(int $workspaceId, int $agentId, ?int $specialistId): ?array
    {
        if ($specialistId === null) {
            return null;
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

        if (! in_array(NativeTool::QueryDocuments->value, $toolsAllowlist, true)) {
            throw ValidationException::withMessages([
                'specialist_id' => "The specialist is not allowed to call tool '" . NativeTool::QueryDocuments->value . "'.",
            ]);
        }

        $config = is_array($specialist->document_tools_config) ? $specialist->document_tools_config : [];
        $allowed = is_array($config['allowed_categories'] ?? null)
            ? array_values(array_filter(
                $config['allowed_categories'],
                fn (mixed $value): bool => is_string($value) && (DocumentCategory::tryFrom($value)?->isSendable() ?? false),
            ))
            : [];

        return $allowed === [] ? null : $allowed;
    }
}
