<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\Products\QueryProducts;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class QueryProductsController extends Controller
{
    public function __invoke(
        QueryProductsRequest $request,
        QueryProducts $query,
    ): JsonResponse {
        $validated = $request->validated();

        $result = $query->execute(
            workspaceId: $validated['workspace_id'],
            query: $validated['query'] ?? null,
            category: $validated['category'] ?? null,
            minPrice: $validated['min_price'] ?? null,
            maxPrice: $validated['max_price'] ?? null,
            limit: $validated['limit'] ?? 20,
        );

        return response()->json($result);
    }
}