<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Services\Products\ProductSearchService;

class QueryProducts
{
    public function __construct(
        private ProductSearchService $searchService,
    ) {}

    /**
     * @return array{products: array<int, array<string, mixed>>, total: int}
     */
    public function execute(
        int $workspaceId,
        ?string $query = null,
        ?string $category = null,
        ?float $minPrice = null,
        ?float $maxPrice = null,
        int $limit = 20,
    ): array {
        return $this->searchService->search(
            workspaceId: $workspaceId,
            query: $query,
            category: $category,
            minPrice: $minPrice,
            maxPrice: $maxPrice,
            activeOnly: true,
            limit: $limit,
        );
    }
}