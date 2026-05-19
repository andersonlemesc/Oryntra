<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\AgentTools\RequestHumanHandoff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\RequestHumanHandoffRequest;
use Illuminate\Http\JsonResponse;

class RequestHumanHandoffController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        RequestHumanHandoffRequest $request,
        RequestHumanHandoff $handoff,
    ): JsonResponse {
        /** @var array{workspace_id:int,agent_id:int,agent_run_id:int,thread_id:string,conversation_id:int,specialist_id?:int|null,reason:string,priority:string,suggested_team?:string|null,customer_message?:string|null} $payload */
        $payload = $request->validated();

        return response()->json($handoff->execute($payload));
    }
}
