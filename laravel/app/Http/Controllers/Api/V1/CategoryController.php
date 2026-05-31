<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreCategoryRequest;
use App\Http\Requests\Api\V1\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CategoryController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->where('workspace_id', $this->workspaceId())
            ->withCount('products')
            ->orderBy('name')
            ->paginate($this->perPage($request->integer('per_page') ?: null));

        return CategoryResource::collection($categories);
    }

    public function store(StoreCategoryRequest $request): CategoryResource
    {
        $category = Category::query()->create([
            ...$request->validated(),
            'workspace_id' => $this->workspaceId(),
        ]);

        return new CategoryResource($category);
    }

    public function show(int $category): CategoryResource
    {
        return new CategoryResource(
            $this->findInWorkspace(Category::class, $category)->loadCount('products')
        );
    }

    public function update(UpdateCategoryRequest $request, int $category): CategoryResource
    {
        $model = $this->findInWorkspace(Category::class, $category);
        $model->update($request->validated());

        return new CategoryResource($model);
    }

    public function destroy(int $category): Response
    {
        $this->findInWorkspace(Category::class, $category)->delete();

        return response()->noContent();
    }
}
