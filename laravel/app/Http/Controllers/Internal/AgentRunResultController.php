<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Enums\AgentRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\AgentRunResultRequest;
use App\Jobs\Agent\FinalizeAgentRunJob;
use App\Models\AgentRun;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal callback hit by the Python runtime once an agent run finishes. It
 * acknowledges fast and hands the heavy finalization (Chatwoot delivery, status
 * transition) to {@see FinalizeAgentRunJob} on the queue. Idempotent: a run
 * that is already terminal is acknowledged without re-queuing.
 */
class AgentRunResultController extends Controller
{
    public function __invoke(AgentRunResultRequest $request, AgentRun $agentRun): JsonResponse
    {
        $status = $agentRun->status;

        if ($status instanceof AgentRunStatus && $status->isTerminal()) {
            return response()->json([
                'idempotent' => true,
                'status' => $status->value,
                'run_id' => $agentRun->id,
            ], Response::HTTP_OK);
        }

        FinalizeAgentRunJob::dispatch($agentRun->id, $request->runtimeResult());

        return response()->json([
            'idempotent' => false,
            'accepted' => true,
            'run_id' => $agentRun->id,
        ], Response::HTTP_ACCEPTED);
    }
}
