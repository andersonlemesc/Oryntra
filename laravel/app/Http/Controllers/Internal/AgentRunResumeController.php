<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\AgentRuns\ApproveAgentRun;
use App\Actions\AgentRuns\EditAgentRunResponse;
use App\Actions\AgentRuns\RejectAgentRun;
use App\Enums\AgentRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\AgentRunResumeRequest;
use App\Models\AgentRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AgentRunResumeController extends Controller
{
    public function __invoke(
        AgentRunResumeRequest $request,
        AgentRun $agentRun,
        ApproveAgentRun $approve,
        RejectAgentRun $reject,
        EditAgentRunResponse $edit,
    ): JsonResponse {
        /** @var array{decision:string,response_content?:string|null,reason?:string|null,actor_id?:int|null} $data */
        $data = $request->validated();

        if ($this->isTerminal($agentRun->status)) {
            return response()->json([
                'idempotent' => true,
                'status' => $this->statusValue($agentRun->status),
                'run_id' => $agentRun->id,
            ], Response::HTTP_OK);
        }

        try {
            $resolved = match ($data['decision']) {
                'approved' => $approve->execute(
                    $agentRun,
                    $data['actor_id'] ?? null,
                    notifyRuntime: false,
                ),
                'rejected' => $reject->execute(
                    $agentRun,
                    $data['actor_id'] ?? null,
                    (string) ($data['reason'] ?? ''),
                ),
                'edited' => $edit->execute(
                    $agentRun,
                    $data['actor_id'] ?? null,
                    (string) ($data['response_content'] ?? ''),
                    notifyRuntime: false,
                ),
                default => throw ValidationException::withMessages([
                    'decision' => 'Unsupported decision.',
                ]),
            };
        } catch (ValidationException $exception) {
            throw $exception;
        }

        return response()->json([
            'idempotent' => false,
            'status' => $this->statusValue($resolved->status),
            'run_id' => $resolved->id,
        ], Response::HTTP_OK);
    }

    private function isTerminal(AgentRunStatus|string $status): bool
    {
        $enum = $status instanceof AgentRunStatus ? $status : AgentRunStatus::from($status);

        return in_array($enum, [
            AgentRunStatus::Completed,
            AgentRunStatus::Failed,
            AgentRunStatus::Ignored,
        ], true);
    }

    private function statusValue(AgentRunStatus|string $status): string
    {
        return ($status instanceof AgentRunStatus ? $status : AgentRunStatus::from($status))->value;
    }
}
