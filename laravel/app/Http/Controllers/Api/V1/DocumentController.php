<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentCategory;
use App\Enums\UploadPurpose;
use App\Http\Controllers\Api\V1\Concerns\ConfirmsUploads;
use App\Http\Resources\Api\V1\StandaloneDocumentResource;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class DocumentController extends ApiController
{
    use ConfirmsUploads;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Document::query()
            ->where('workspace_id', $this->workspaceId())
            ->orderByDesc('id');

        if ($request->filled('category')) {
            $query->byCategory($request->string('category')->value());
        }

        return StandaloneDocumentResource::collection(
            $query->paginate($this->perPage($request->integer('per_page') ?: null))
        );
    }

    public function show(int $document): StandaloneDocumentResource
    {
        return new StandaloneDocumentResource($this->findInWorkspace(Document::class, $document));
    }

    /**
     * Create a standalone (customer-sendable) document from a presigned upload.
     */
    public function store(Request $request): StandaloneDocumentResource
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::in(DocumentCategory::sendableValues())],
            'original_filename' => ['nullable', 'string', 'max:255'],
        ]);

        $upload = $this->resolveConfirmedUpload($validated['upload_id'], UploadPurpose::Document, $this->workspaceId());

        $document = Document::query()->create([
            'workspace_id' => $this->workspaceId(),
            'category' => $validated['category'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'original_filename' => $validated['original_filename'] ?? $upload['original_filename'],
            'path' => $upload['storage_path'],
        ]);

        return new StandaloneDocumentResource($document);
    }

    public function destroy(int $document): Response
    {
        $this->findInWorkspace(Document::class, $document)->delete();

        return response()->noContent();
    }
}
