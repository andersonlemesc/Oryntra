<?php

declare(strict_types=1);

namespace App\Actions\AgentTools;

use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\DocumentChunk;
use App\Models\Workspace;
use App\Services\AgentRuntime\AgentRuntimeClient;
use App\Services\AgentTools\NativeTool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SearchKnowledgeBase
{
    public function __construct(private AgentRuntimeClient $runtime) {}

    /**
     * @param  array<int, string>|null                                                                                                                     $tags
     * @return array{hits: array<int, array{agent_document_id:int,content:string,score:float,metadata:array<string,mixed>}>, embedding_model: string|null}
     */
    public function execute(
        int $workspaceId,
        int $agentId,
        int $agentRunId,
        ?int $specialistId,
        string $query,
        int $topK = 5,
        float $minScore = 0.0,
        ?array $tags = null,
    ): array {
        $this->assertRunMatchesAgent($workspaceId, $agentId, $agentRunId);
        $this->assertSpecialistMayCall($workspaceId, $agentId, $specialistId);

        $workspace = Workspace::query()->findOrFail($workspaceId);

        $embedding = $this->runtime->embedQuery($workspace, $query);
        $model = $embedding['embedding_model'];
        $tags = $this->normalizeTags($tags);

        $hits = DB::connection()->getDriverName() === 'pgsql'
            ? $this->searchPgvector($workspaceId, $agentId, $embedding['vector'], $model, $topK, $minScore, $tags)
            : $this->searchInPhp($workspaceId, $agentId, $embedding['vector'], $model, $topK, $minScore, $tags);

        return ['hits' => $hits, 'embedding_model' => $model];
    }

    /**
     * @param  array<int, float>                                                                                $vector
     * @param  array<int, string>                                                                               $tags
     * @return array<int, array{agent_document_id:int,content:string,score:float,metadata:array<string,mixed>}>
     */
    private function searchPgvector(int $workspaceId, int $agentId, array $vector, string $model, int $topK, float $minScore, array $tags): array
    {
        $literal = '[' . implode(',', $vector) . ']';

        $sql = 'SELECT dc.agent_document_id, dc.content, dc.metadata, '
            . '1 - (dc.embedding <=> ?::vector) AS score '
            . 'FROM document_chunks dc '
            . 'JOIN agent_documents ad ON ad.id = dc.agent_document_id '
            . 'WHERE dc.workspace_id = ? AND dc.embedding_model = ? '
            // Scope to this agent: docs linked to it, plus global docs (no link at all).
            . 'AND (EXISTS (SELECT 1 FROM agent_knowledge_document akd WHERE akd.agent_document_id = ad.id AND akd.agent_id = ?) '
            . 'OR NOT EXISTS (SELECT 1 FROM agent_knowledge_document akd2 WHERE akd2.agent_document_id = ad.id))';

        $bindings = [$literal, $workspaceId, $model, $agentId];

        if ($tags !== []) {
            $sql .= ' AND jsonb_exists_any(ad.tags, ARRAY(SELECT jsonb_array_elements_text(?::jsonb)))';
            $bindings[] = json_encode(array_values($tags));
        }

        $sql .= ' ORDER BY dc.embedding <=> ?::vector LIMIT ?';
        $bindings[] = $literal;
        $bindings[] = $topK;

        $rows = DB::select($sql, $bindings);

        $hits = [];

        foreach ($rows as $row) {
            $score = (float) $row->score;

            if ($score < $minScore) {
                continue;
            }

            $hits[] = [
                'agent_document_id' => (int) $row->agent_document_id,
                'content' => (string) $row->content,
                'score' => $score,
                'metadata' => $this->decodeMetadata($row->metadata),
            ];
        }

        return $hits;
    }

    /**
     * Portable fallback (sqlite test suite has no pgvector): compute cosine
     * similarity in PHP over the workspace+model scoped chunks.
     *
     * @param  array<int, float>                                                                                $vector
     * @param  array<int, string>                                                                               $tags
     * @return array<int, array{agent_document_id:int,content:string,score:float,metadata:array<string,mixed>}>
     */
    private function searchInPhp(int $workspaceId, int $agentId, array $vector, string $model, int $topK, float $minScore, array $tags): array
    {
        $chunks = DocumentChunk::query()
            ->with('agentDocument:id,tags')
            ->where('workspace_id', $workspaceId)
            ->where('embedding_model', $model)
            ->get();

        // Scope to this agent: docs linked to it, plus global docs (no link at all).
        $linkedToAgent = DB::table('agent_knowledge_document')
            ->where('agent_id', $agentId)
            ->pluck('agent_document_id')
            ->all();
        $linkedToAgent = array_flip($linkedToAgent);
        $hasAnyLink = array_flip(DB::table('agent_knowledge_document')->pluck('agent_document_id')->all());

        $scored = [];

        foreach ($chunks as $chunk) {
            $documentId = $chunk->agent_document_id;
            $visible = isset($linkedToAgent[$documentId]) || ! isset($hasAnyLink[$documentId]);

            if (! $visible) {
                continue;
            }

            if ($tags !== [] && ! $this->chunkMatchesTags($chunk, $tags)) {
                continue;
            }

            $embedding = $chunk->embedding;

            if (! is_array($embedding) || $embedding === []) {
                continue;
            }

            $score = $this->cosineSimilarity($vector, $embedding);

            if ($score < $minScore) {
                continue;
            }

            $scored[] = [
                'agent_document_id' => $chunk->agent_document_id,
                'content' => $chunk->content,
                'score' => $score,
                'metadata' => is_array($chunk->metadata) ? $chunk->metadata : [],
            ];
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $topK);
    }

    /**
     * @param array<int, string> $tags
     */
    private function chunkMatchesTags(DocumentChunk $chunk, array $tags): bool
    {
        $documentTags = $chunk->agentDocument?->tags;

        if (! is_array($documentTags)) {
            return false;
        }

        return array_intersect($tags, $documentTags) !== [];
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);

            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param  array<int, string>|null $tags
     * @return array<int, string>
     */
    private function normalizeTags(?array $tags): array
    {
        if ($tags === null) {
            return [];
        }

        return array_values(array_filter(
            $tags,
            fn (string $tag): bool => trim($tag) !== '',
        ));
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

    private function assertSpecialistMayCall(int $workspaceId, int $agentId, ?int $specialistId): void
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

        $allowlist = is_array($specialist->tools_allowlist) ? $specialist->tools_allowlist : [];

        if (! in_array(NativeTool::SearchKnowledgeBase->value, $allowlist, true)) {
            throw ValidationException::withMessages([
                'specialist_id' => "The specialist is not allowed to call tool '" . NativeTool::SearchKnowledgeBase->value . "'.",
            ]);
        }
    }
}
