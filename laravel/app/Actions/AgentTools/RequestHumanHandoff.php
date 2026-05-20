<?php

declare(strict_types=1);

namespace App\Actions\AgentTools;

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\ApplyHumanHandoffToChatwootJob;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestHumanHandoff
{
    /**
     * @param  array{workspace_id:int,agent_id:int,agent_run_id:int,thread_id:string,conversation_id:int,specialist_id?:int|null,reason:string,priority:string,suggested_team?:string|null,customer_message?:string|null} $payload
     * @return array{status:string,handoff_id:int,message:string}
     */
    public function execute(array $payload): array
    {
        $run = DB::transaction(function () use ($payload): AgentRun {
            $run = AgentRun::query()
                ->where('id', $payload['agent_run_id'])
                ->where('workspace_id', $payload['workspace_id'])
                ->where('agent_id', $payload['agent_id'])
                ->where('thread_id', $payload['thread_id'])
                ->where('conversation_id', $payload['conversation_id'])
                ->lockForUpdate()
                ->first();

            if (! $run instanceof AgentRun) {
                throw ValidationException::withMessages([
                    'agent_run_id' => 'The agent run does not match the workspace, agent, thread, and conversation.',
                ]);
            }

            $specialistId = $payload['specialist_id'] ?? null;

            if ($specialistId !== null) {
                $this->assertSpecialistCanRequestHandoff($payload, $specialistId);
            }

            $run->update([
                'status' => AgentRunStatus::WaitingHuman,
                'output' => $this->handoffOutput($run, $payload, $specialistId),
                'finished_at' => null,
            ]);

            return $run->refresh();
        });

        ApplyHumanHandoffToChatwootJob::dispatch($run->id)->afterCommit();

        return [
            'status' => 'waiting_human',
            'handoff_id' => $run->id,
            'message' => 'Human handoff requested.',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertSpecialistCanRequestHandoff(array $payload, int $specialistId): void
    {
        $specialist = AgentSpecialist::query()
            ->where('id', $specialistId)
            ->where('workspace_id', $payload['workspace_id'])
            ->where('agent_id', $payload['agent_id'])
            ->first();

        if (! $specialist instanceof AgentSpecialist) {
            throw ValidationException::withMessages([
                'specialist_id' => 'The specialist does not belong to this workspace and agent.',
            ]);
        }

        $toolsAllowlist = $specialist->tools_allowlist;

        if (! is_array($toolsAllowlist)) {
            $toolsAllowlist = [];
        }

        if (! in_array('request_human_handoff', $toolsAllowlist, true)) {
            throw ValidationException::withMessages([
                'specialist_id' => 'The specialist is not allowed to request human handoff.',
            ]);
        }
    }

    /**
     * @param  array{reason:string,priority:string,suggested_team?:string|null,customer_message?:string|null} $payload
     * @return array<string, mixed>
     */
    private function handoffOutput(AgentRun $run, array $payload, ?int $specialistId): array
    {
        $rawOutput = $run->getAttribute('output');
        $output = is_array($rawOutput) ? $rawOutput : [];
        $trace = is_array($output['trace'] ?? null) ? $output['trace'] : [];
        $nextStep = count($trace) + 1;
        $timestamp = Carbon::now()->toISOString();

        $trace[] = [
            'step' => $nextStep,
            'type' => 'tool_call',
            'specialist_id' => $specialistId,
            'tool' => 'request_human_handoff',
            'input' => [
                'priority' => $payload['priority'],
                'suggested_team' => $payload['suggested_team'] ?? null,
                'has_customer_message' => filled($payload['customer_message'] ?? null),
            ],
            'output' => [],
            'tokens' => ['input' => 0, 'output' => 0],
            'latency_ms' => 0,
            'ts' => $timestamp,
        ];

        $trace[] = [
            'step' => $nextStep + 1,
            'type' => 'tool_result',
            'specialist_id' => $specialistId,
            'tool' => 'request_human_handoff',
            'input' => [],
            'output' => [
                'status' => 'waiting_human',
                'handoff_id' => $run->id,
            ],
            'tokens' => ['input' => 0, 'output' => 0],
            'latency_ms' => 0,
            'ts' => $timestamp,
        ];

        $output['handoff'] = [
            'reason' => $payload['reason'],
            'priority' => $payload['priority'],
            'suggested_team' => $payload['suggested_team'] ?? null,
            'customer_message' => $payload['customer_message'] ?? null,
            'private_note' => null,
            'requested_at' => $timestamp,
            'side_effects' => [
                'status' => 'queued',
                'job_id' => null,
                'attempted_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'error' => null,
                'actions' => [
                    'customer_message' => 'pending',
                    'private_note' => 'pending',
                    'label' => 'pending',
                    'team_assignment' => 'pending',
                    'agent_assignment' => 'pending',
                ],
            ],
        ];
        $output['trace'] = $trace;

        return $output;
    }
}
