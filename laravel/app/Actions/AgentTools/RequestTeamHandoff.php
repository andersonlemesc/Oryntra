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

class RequestTeamHandoff
{
    private const VALID_PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /**
     * @param  array{workspace_id:int,agent_id:int,agent_run_id:int,thread_id:string,conversation_id:int,specialist_id?:int|null,reason:string,priority:string,customer_message?:string|null} $payload
     * @return array{status:string,handoff_id:int,message:string}
     */
    public function execute(array $payload): array
    {
        if (! filled($payload['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reason' => 'Reason is required for team handoff.',
            ]);
        }

        if (! in_array($payload['priority'] ?? null, self::VALID_PRIORITIES, true)) {
            $payload['priority'] = 'normal';
        }

        $run = DB::transaction(function () use (&$payload): AgentRun {
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
            $specialist = null;

            if ($specialistId !== null) {
                $specialist = $this->assertSpecialistCanRequestTeamHandoff($payload, $specialistId);
            }

            if (! filled($payload['customer_message'] ?? null)) {
                $payload['customer_message'] = $this->resolveDefaultCustomerMessage($specialist);
            }

            $run->update([
                'status' => AgentRunStatus::Completed,
                'output' => $this->handoffOutput($run, $payload, $specialistId),
                'finished_at' => Carbon::now(),
            ]);

            return $run->refresh();
        });

        ApplyHumanHandoffToChatwootJob::dispatch($run->id)->afterCommit();

        return [
            'status' => 'handoff_dispatched',
            'handoff_id' => $run->id,
            'message' => 'Team handoff dispatched to Chatwoot.',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertSpecialistCanRequestTeamHandoff(array $payload, int $specialistId): AgentSpecialist
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

        if (! in_array('request_team_handoff', $toolsAllowlist, true)) {
            throw ValidationException::withMessages([
                'specialist_id' => 'The specialist is not allowed to request team handoff.',
            ]);
        }

        return $specialist;
    }

    private function resolveDefaultCustomerMessage(?AgentSpecialist $specialist): string
    {
        $config = is_array($specialist?->handoff_config) ? $specialist->handoff_config : [];
        $message = $config['customer_message'] ?? null;

        if (is_string($message) && filled($message)) {
            return $message;
        }

        return 'Vou transferir voce para um time de atendimento.';
    }

    /**
     * @param  array{reason:string,priority:string,customer_message?:string|null} $payload
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
            'tool' => 'request_team_handoff',
            'input' => [
                'priority' => $payload['priority'],
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
            'tool' => 'request_team_handoff',
            'input' => [],
            'output' => [
                'status' => 'handoff_dispatched',
                'handoff_id' => $run->id,
            ],
            'tokens' => ['input' => 0, 'output' => 0],
            'latency_ms' => 0,
            'ts' => $timestamp,
        ];

        $output['handoff'] = [
            'target_type' => 'team',
            'reason' => $payload['reason'],
            'priority' => $payload['priority'],
            'suggested_team' => null,
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
                    'open_conversation' => 'pending',
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
