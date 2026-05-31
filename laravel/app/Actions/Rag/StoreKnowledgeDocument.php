<?php

declare(strict_types=1);

namespace App\Actions\Rag;

use App\Enums\AgentDocumentStatus;
use App\Jobs\Rag\IndexKnowledgeDocumentJob;
use App\Models\AgentDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreKnowledgeDocument
{
    /**
     * Store an uploaded knowledge file, create the document row (deduped by
     * checksum within the workspace) and queue indexing.
     *
     * @param array<int, string>|null $tags
     */
    public function execute(
        UploadedFile $file,
        int $workspaceId,
        ?string $name = null,
        ?array $tags = null,
        ?int $extractorLlmKeyId = null,
        ?string $extractorModel = null,
        string $disk = 's3',
    ): AgentDocument {
        $checksum = (string) hash_file('sha256', $file->getRealPath());

        $existing = AgentDocument::query()
            ->where('workspace_id', $workspaceId)
            ->where('checksum', $checksum)
            ->first();

        if ($existing instanceof AgentDocument) {
            return $existing;
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $storagePath = sprintf('workspaces/%d/knowledge/%s.%s', $workspaceId, Str::uuid()->toString(), $extension);

        Storage::disk($disk)->put($storagePath, (string) file_get_contents($file->getRealPath()));

        $document = AgentDocument::query()->create([
            'workspace_id' => $workspaceId,
            'name' => $name ?: $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize() ?: 0,
            'storage_disk' => $disk,
            'storage_path' => $storagePath,
            'checksum' => $checksum,
            'tags' => $tags,
            'extractor_llm_key_id' => $extractorLlmKeyId,
            'extractor_model' => $extractorModel,
            'index_status' => AgentDocumentStatus::Pending,
        ]);

        IndexKnowledgeDocumentJob::dispatch($document->id);

        return $document;
    }
}
