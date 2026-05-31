<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UploadPurpose;
use App\Http\Controllers\Api\V1\Concerns\ConfirmsUploads;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\Api\V1\DocumentSummaryResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends ApiController
{
    use ConfirmsUploads;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->where('workspace_id', $this->workspaceId())
            ->with('category')
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $query->bySearch($request->string('search')->value());
        }

        if ($request->filled('category')) {
            $query->inCategory($request->string('category')->value());
        }

        if ($request->filled('active')) {
            $query->where('active', $request->boolean('active'));
        }

        $query->priceRange(
            $request->has('min_price') ? (float) $request->input('min_price') : null,
            $request->has('max_price') ? (float) $request->input('max_price') : null,
        );

        return ProductResource::collection(
            $query->paginate($this->perPage($request->integer('per_page') ?: null))
        );
    }

    public function store(StoreProductRequest $request): ProductResource
    {
        $product = Product::query()->create([
            ...$request->validated(),
            'workspace_id' => $this->workspaceId(),
        ]);

        return new ProductResource($product->load('category'));
    }

    public function show(int $product): ProductResource
    {
        $model = $this->findInWorkspace(Product::class, $product);

        return new ProductResource($model->load(['category', 'documents']));
    }

    public function update(UpdateProductRequest $request, int $product): ProductResource
    {
        $model = $this->findInWorkspace(Product::class, $product);
        $model->update($request->validated());

        return new ProductResource($model->load('category'));
    }

    public function destroy(int $product): Response
    {
        $this->findInWorkspace(Product::class, $product)->delete();

        return response()->noContent();
    }

    public function documents(int $product): AnonymousResourceCollection
    {
        $model = $this->findInWorkspace(Product::class, $product);

        return DocumentSummaryResource::collection($model->documents()->orderByDesc('id')->get());
    }

    /**
     * Attach a previously uploaded file (via presigned upload) to the product.
     */
    public function attachDocument(Request $request, int $product): DocumentSummaryResource
    {
        $model = $this->findInWorkspace(Product::class, $product);

        $validated = $request->validate([
            'upload_id' => ['required', 'string'],
            'original_filename' => ['nullable', 'string', 'max:255'],
        ]);

        $upload = $this->resolveConfirmedUpload($validated['upload_id'], UploadPurpose::ProductDocument, $this->workspaceId());

        $document = $model->documents()->create([
            'workspace_id' => $this->workspaceId(),
            'original_filename' => $validated['original_filename'] ?? $upload['original_filename'],
            'path' => $upload['storage_path'],
        ]);

        return new DocumentSummaryResource($document);
    }

    public function destroyDocument(int $product, int $document): Response
    {
        $model = $this->findInWorkspace(Product::class, $product);
        $doc = $model->documents()->where('id', $document)->firstOrFail();
        $doc->delete();

        return response()->noContent();
    }
}
