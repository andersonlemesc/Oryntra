<?php

declare(strict_types=1);

namespace App\Actions\AgentTools;

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\ApplyResolveConversationToChatwootJob;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Services\AgentTools\NativeTool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveConversation
{
    /**
     * @param  array{workspace_id:int,agent_id:int,agent_run_id:int,thread_id:string,conversation_id:int,specialist_id?:int|null,reason:string,resolution_summary:string,customer_message?:string|null,label_name?:string|null} $payload
     * @return array{status:string,resolution_id:int,message:string}
     */
    public function execute(array $payload): array
    {
        if (! filled($payload['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reason' => 'Reason is required to resolve the conversation.',
            ]);
        }

        if (! filled($payload['resolution_summary'] ?? null)) {
            throw ValidationException::withMessages([
                'resolution_summary' => 'Resolution summary is required to resolve the conversation.',
            ]);
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
                $specialist = $this->assertSpecialistCanResolve($payload, $specialistId);
            }

            $payload['customer_message'] = $this->resolveCustomerMessage($payload, $specialist);
            $payload['label_name'] = $this->resolveLabelName($payload, $specialist);

            $run->update([
                'status' => AgentRunStatus::Completed,
                'output' => $this->resolutionOutput($run, $payload, $specialistId),
                'finished_at' => Carbon::now(),
            ]);

            return $run->refresh();
        });

        ApplyResolveConversationToChatwootJob::dispatch($run->id)->afterCommit();

        return [
            'status' => 'resolution_dispatched',
            'resolution_id' => $run->id,
            'message' => 'Conversation resolution dispatched to Chatwoot.',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertSpecialistCanResolve(array $payload, int $specialistId): AgentSpecialist
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

        if (! in_array(NativeTool::ResolveConversation->value, $toolsAllowlist, true)) {
            throw ValidationException::withMessages([
                'specialist_id' => 'The specialist is not allowed to resolve conversations.',
            ]);
        }

        return $specialist;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveCustomerMessage(array $payload, ?AgentSpecialist $specialist): ?string
    {
        $payloadMessage = $payload['customer_message'] ?? null;

        if (is_string($payloadMessage) && filled($payloadMessage)) {
            return $payloadMessage;
        }

        $config = is_array($specialist?->resolution_config) ? $specialist->resolution_config : [];
        $configMessage = $config['customer_message'] ?? null;

        if (is_string($configMessage) && filled($configMessage)) {
            return $configMessage;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveLabelName(array $payload, ?AgentSpecialist $specialist): ?string
    {
        $payloadLabel = $payload['label_name'] ?? null;

        if (is_string($payloadLabel) && trim($payloadLabel) !== '') {
            return trim($payloadLabel);
        }

        $config = is_array($specialist?->resolution_config) ? $specialist->resolution_config : [];
        $configLabel = $config['label_name'] ?? null;

        if (is_string($configLabel) && trim($configLabel) !== '') {
            return trim($configLabel);
        }

        return null;
    }

    /**
     * @param  array{reason:string,resolution_summary:string,customer_message?:string|null,label_name?:string|null} $payload
     * @return array<string, mixed>
     */
    private function resolutionOutput(AgentRun $run, array $payload, ?int $specialistId): array
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
            'tool' => NativeTool::ResolveConversation->value,
            'input' => [
                'has_customer_message' => filled($payload['customer_message'] ?? null),
                'has_label' => filled($payload['label_name'] ?? null),
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
            'tool' => NativeTool::ResolveConversation->value,
            'input' => [],
            'output' => [
                'status' => 'resolution_dispatched',
                'resolution_id' => $run->id,
            ],
            'tokens' => ['input' => 0, 'output' => 0],
            'latency_ms' => 0,
            'ts' => $timestamp,
        ];

        $output['resolution'] = [
            'reason' => $payload['reason'],
            'resolution_summary' => $payload['resolution_summary'],
            'customer_message' => $payload['customer_message'] ?? null,
            'label_name' => $payload['label_name'] ?? null,
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
                    'label' => 'pending',
                    'resolve' => 'pending',
                ],
            ],
        ];
        $output['trace'] = $trace;

        return $output;
    }
}
