<?php

declare(strict_types=1);

namespace App\Jobs\Rag;

use App\Enums\AgentDocumentStatus;
use App\Models\AgentDocument;
use App\Models\DocumentChunk;
use App\Services\AgentRuntime\AgentRuntimeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class IndexKnowledgeDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 1200;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 180, 600];

    public function __construct(public int $agentDocumentId)
    {
        $this->onQueue('rag');
    }

    public function handle(AgentRuntimeClient $runtime): void
    {
        $document = AgentDocument::query()->find($this->agentDocumentId);

        if (! $document instanceof AgentDocument) {
            return;
        }

        $document->update([
            'index_status' => AgentDocumentStatus::Indexing,
            'index_error' => null,
        ]);

        $result = $runtime->ingestKnowledge($document);

        DB::transaction(function () use ($document, $result): void {
            $document->chunks()->delete();

            foreach ($result['chunks'] as $index => $chunk) {
                DocumentChunk::query()->create([
                    'workspace_id' => $document->workspace_id,
                    'agent_document_id' => $document->id,
                    'chunk_index' => $chunk['index'] ?? $index,
                    'content' => $chunk['content'],
                    'tokens' => $chunk['tokens'] ?? null,
                    'embedding_model' => $result['embedding_model'],
                    'embedding_dim' => $result['embedding_dim'],
                    'metadata' => $chunk['metadata'] ?? null,
                    'embedding' => $result['vectors'][$index],
                ]);
            }

            $document->update([
                'index_status' => AgentDocumentStatus::Indexed,
                'index_error' => null,
                'indexed_at' => now(),
                'chunks_count' => count($result['chunks']),
                'embedding_provider' => $result['embedding_provider'],
                'embedding_model' => $result['embedding_model'],
                'embedding_dim' => $result['embedding_dim'],
            ]);
        });
    }

    public function failed(Throwable $exception): void
    {
        AgentDocument::query()
            ->whereKey($this->agentDocumentId)
            ->update([
                'index_status' => AgentDocumentStatus::Failed->value,
                'index_error' => Str::limit($exception->getMessage(), 1000),
            ]);
    }
}
