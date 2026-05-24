<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class ProductSearchService
{
    /**
     * @return array{products: array<int, array<string, mixed>>, total: int}
     */
    public function search(
        int $workspaceId,
        ?string $query = null,
        ?string $category = null,
        ?float $minPrice = null,
        ?float $maxPrice = null,
        bool $activeOnly = true,
        int $limit = 20,
    ): array {
        $q = Product::query()
            ->with('category')
            ->where('workspace_id', $workspaceId);

        if ($activeOnly) {
            $q->where('active', true);
        }

        if ($category !== null && $category !== '') {
            $q->whereHas('category', function (Builder $categoryQuery) use ($category, $workspaceId): void {
                $categoryQuery
                    ->where('workspace_id', $workspaceId)
                    ->where(function (Builder $match) use ($category): void {
                        $match->where('name', $category)
                            ->orWhere('slug', $category);
                    });
            });
        }

        if ($minPrice !== null) {
            $q->where('price', '>=', $minPrice);
        }

        if ($maxPrice !== null) {
            $q->where('price', '<=', $maxPrice);
        }

        if ($query !== null && $query !== '') {
            $operator = Product::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $q->where(function (Builder $sub) use ($operator, $query): void {
                $search = "%{$query}%";
                $sub->where('name', $operator, $search)
                    ->orWhere('description', $operator, $search)
                    ->orWhere('sku', $operator, $search);
            });
        }

        $total = $q->count();
        $products = $q->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Product $p): array => $p->toAgentPayload())
            ->all();

        return ['products' => $products, 'total' => $total];
    }

    /**
     * @return array<int, string>
     */
    public function categoriesForWorkspace(int $workspaceId): array
    {
        return Category::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('products', fn (Builder $query): Builder => $query->where('active', true))
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }
}
