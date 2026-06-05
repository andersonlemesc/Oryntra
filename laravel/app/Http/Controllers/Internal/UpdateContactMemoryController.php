<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\AgentTools\UpdateContactMemory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\UpdateContactMemoryRequest;
use Illuminate\Http\JsonResponse;

class UpdateContactMemoryController extends Controller
{
    public function __invoke(
        UpdateContactMemoryRequest $request,
        UpdateContactMemory $action,
    ): JsonResponse {
        /** @var array{workspace_id:int,agent_id:int,agent_run_id:int,specialist_id?:int|null,contact_id:int,type:string,content:string,confidence?:float|null,expires_at?:string|null} $payload */
        $payload = $request->validated();

        return response()->json($action->execute($payload));
    }
}
