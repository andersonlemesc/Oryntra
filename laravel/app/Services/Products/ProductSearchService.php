<?php

declare(strict_types=1);

namespace App\Services\Products;

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
            ->where('workspace_id', $workspaceId);

        if ($activeOnly) {
            $q->where('active', true);
        }

        if ($category !== null && $category !== '') {
            $q->where('category', $category);
        }

        if ($minPrice !== null) {
            $q->where('price', '>=', $minPrice);
        }

        if ($maxPrice !== null) {
            $q->where('price', '<=', $maxPrice);
        }

        if ($query !== null && $query !== '') {
            $q->where(function (Builder $sub) use ($query): void {
                $search = "%{$query}%";
                $sub->where('name', 'ilike', $search)
                    ->orWhere('description', 'ilike', $search)
                    ->orWhere('sku', 'ilike', $search);
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
        return Product::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('category')
            ->where('active', true)
            ->distinct()
            ->pluck('category')
            ->sort()
            ->values()
            ->all();
    }
}