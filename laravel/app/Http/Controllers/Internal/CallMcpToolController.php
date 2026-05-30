<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\AgentTools\CallMcpTool;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\CallMcpToolRequest;
use Illuminate\Http\JsonResponse;

class CallMcpToolController extends Controller
{
    public function __invoke(
        CallMcpToolRequest $request,
        CallMcpTool $action,
    ): JsonResponse {
        $result = $action->execute($request->validated());

        return response()->json($result);
    }
}
