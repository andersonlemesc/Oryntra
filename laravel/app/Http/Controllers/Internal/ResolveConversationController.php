<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\AgentTools\ResolveConversation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\ResolveConversationRequest;
use Illuminate\Http\JsonResponse;

class ResolveConversationController extends Controller
{
    public function __invoke(
        ResolveConversationRequest $request,
        ResolveConversation $resolution,
    ): JsonResponse {
        /** @var array{workspace_id:int,agent_id:int,agent_run_id:int,thread_id:string,conversation_id:int,specialist_id?:int|null,reason:string,resolution_summary:string,customer_message?:string|null,label_name?:string|null} $payload */
        $payload = $request->validated();

        return response()->json($resolution->execute($payload));
    }
}
