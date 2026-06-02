<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Rag\StoreKnowledgeDocument;
use App\Enums\UploadPurpose;
use App\Http\Controllers\Api\V1\Concerns\ConfirmsUploads;
use App\Http\Resources\Api\V1\KnowledgeDocumentResource;
use App\Models\AgentDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class KnowledgeDocumentController extends ApiController
{
    use ConfirmsUploads;

    public function index(Request $request): AnonymousResourceCollection
    {
        $documents = AgentDocument::query()
            ->where('workspace_id', $this->workspaceId())
            ->when($request->filled('agent_id'), fn ($q) => $q->forAgent($request->integer('agent_id')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request->integer('per_page') ?: null));

        return KnowledgeDocumentResource::collection($documents);
    }

    public function show(int $knowledgeDocument): KnowledgeDocumentResource
    {
        return new KnowledgeDocumentResource($this->findInWorkspace(AgentDocument::class, $knowledgeDocument));
    }

    /**
     * Ingest knowledge from raw markdown/text. The document is queued for
     * indexing and starts in `pending`.
     */
    public function fromText(Request $request, StoreKnowledgeDocument $action): KnowledgeDocumentResource
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'agent_ids' => ['nullable', 'array'],
            'agent_ids.*' => [Rule::exists('agents', 'id')->where('workspace_id', $this->workspaceId())],
        ]);

        $document = $action->fromText(
            content: $validated['content'],
            workspaceId: $this->workspaceId(),
            name: $validated['name'],
            tags: $validated['tags'] ?? null,
        );

        if (! empty($validated['agent_ids'])) {
            $document->agents()->sync($validated['agent_ids']);
        }

        return new KnowledgeDocumentResource($document);
    }

    /**
     * Ingest knowledge from a file previously uploaded via presigned upload.
     */
    public function confirmUpload(Request $request, StoreKnowledgeDocument $action): KnowledgeDocumentResource
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'agent_ids' => ['nullable', 'array'],
            'agent_ids.*' => [Rule::exists('agents', 'id')->where('workspace_id', $this->workspaceId())],
        ]);

        $upload = $this->resolveConfirmedUpload($validated['upload_id'], UploadPurpose::Knowledge, $this->workspaceId());

        $document = $action->fromStoredPath(
            workspaceId: $this->workspaceId(),
            storagePath: $upload['storage_path'],
            name: $validated['name'] ?? $upload['original_filename'],
            mimeType: $upload['mime'],
            sizeBytes: $upload['size'],
            tags: $validated['tags'] ?? null,
        );

        if (! empty($validated['agent_ids'])) {
            $document->agents()->sync($validated['agent_ids']);
        }

        return new KnowledgeDocumentResource($document);
    }

    public function destroy(int $knowledgeDocument): Response
    {
        $this->findInWorkspace(AgentDocument::class, $knowledgeDocument)->delete();

        return response()->noContent();
    }
}
