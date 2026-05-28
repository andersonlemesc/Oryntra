<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\AgentTools\CallExternalTool;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\CallExternalToolRequest;
use Illuminate\Http\JsonResponse;

class CallExternalToolController extends Controller
{
    public function __invoke(
        CallExternalToolRequest $request,
        CallExternalTool $action,
    ): JsonResponse {
        $result = $action->execute($request->validated());

        return response()->json($result);
    }
}
