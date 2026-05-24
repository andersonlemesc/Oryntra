<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class ProductSearchService
{
    /**
     * Threshold for pg_trgm word_similarity (partial token match).
     * "bike" in "bicicletas" = 0.4 (matches), "moto" in "bicicletas" = 0 (no match).
     */
    private const WORD_SIMILARITY_THRESHOLD = 0.3;

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
            ->with('category', 'documents')
            ->where('workspace_id', $workspaceId);

        if ($activeOnly) {
            $q->where('active', true);
        }

        if ($category !== null && $category !== '') {
            $isPgsql = Product::query()->getConnection()->getDriverName() === 'pgsql';
            $tokens = $this->tokenize($category);

            $q->whereHas('category', function (Builder $categoryQuery) use ($category, $tokens, $isPgsql, $workspaceId): void {
                $categoryQuery
                    ->where('workspace_id', $workspaceId)
                    ->where(function (Builder $match) use ($category, $tokens, $isPgsql): void {
                        // exato
                        $match->where('name', $category)->orWhere('slug', $category);

                        // token-by-token OR
                        foreach ($tokens as $token) {
                            $like = '%'.$token.'%';
                            if ($isPgsql) {
                                $match->orWhereRaw('unaccent(lower(name)) ILIKE unaccent(lower(?))', [$like])
                                    ->orWhereRaw('unaccent(lower(slug)) ILIKE unaccent(lower(?))', [$like]);
                            } else {
                                $match->orWhere('name', 'like', $like)
                                    ->orWhere('slug', 'like', $like);
                            }
                        }

                        // pg_trgm word_similarity fallback (catches "bike" -> "bicicletas")
                        if ($isPgsql) {
                            $match->orWhereRaw('word_similarity(unaccent(lower(?)), unaccent(lower(name))) >= ?', [$category, self::WORD_SIMILARITY_THRESHOLD])
                                ->orWhereRaw('word_similarity(unaccent(lower(?)), unaccent(lower(slug))) >= ?', [$category, self::WORD_SIMILARITY_THRESHOLD]);
                        }
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
            $isPgsql = Product::query()->getConnection()->getDriverName() === 'pgsql';
            $tokens = $this->tokenize($query);
            $tokens[] = $query; // mantém match completo também

            $q->where(function (Builder $sub) use ($tokens, $isPgsql): void {
                foreach (array_unique($tokens) as $token) {
                    $search = '%'.$token.'%';
                    if ($isPgsql) {
                        $sub->orWhereRaw('unaccent(lower(name)) ILIKE unaccent(lower(?))', [$search])
                            ->orWhereRaw('unaccent(lower(description)) ILIKE unaccent(lower(?))', [$search])
                            ->orWhereRaw('unaccent(lower(sku)) ILIKE unaccent(lower(?))', [$search]);
                    } else {
                        $sub->orWhere('name', 'like', $search)
                            ->orWhere('description', 'like', $search)
                            ->orWhere('sku', 'like', $search);
                    }
                }
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
     * Quebra texto em tokens (>=3 chars) pra fuzzy match token-by-token.
     *
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $parts = preg_split('/\s+/', trim($text)) ?: [];

        return array_values(array_filter(
            $parts,
            fn (string $token): bool => mb_strlen($token) >= 3,
        ));
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
