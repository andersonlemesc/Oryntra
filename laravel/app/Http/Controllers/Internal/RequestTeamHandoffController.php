<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\AgentTools\RequestTeamHandoff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\RequestTeamHandoffRequest;
use Illuminate\Http\JsonResponse;

class RequestTeamHandoffController extends Controller
{
    public function __invoke(
        RequestTeamHandoffRequest $request,
        RequestTeamHandoff $handoff,
    ): JsonResponse {
        /** @var array{workspace_id:int,agent_id:int,agent_run_id:int,thread_id:string,conversation_id:int,specialist_id?:int|null,reason:string,priority:string,customer_message?:string|null} $payload */
        $payload = $request->validated();

        return response()->json($handoff->execute($payload));
    }
}
