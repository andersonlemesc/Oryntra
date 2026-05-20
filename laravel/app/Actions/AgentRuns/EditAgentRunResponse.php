<?php

declare(strict_types=1);

namespace App\Actions\AgentRuns;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditAgentRunResponse
{
    public function __construct(private readonly ApproveAgentRun $approve) {}

    public function execute(
        AgentRun $run,
        ?int $actorId,
        string $newContent,
        bool $notifyRuntime = true,
    ): AgentRun {
        if (trim($newContent) === '') {
            throw ValidationException::withMessages([
                'response_content' => 'The edited response content cannot be empty.',
            ]);
        }

        if ($run->status !== AgentRunStatus::WaitingHuman) {
            throw ValidationException::withMessages([
                'status' => 'Only runs waiting for human review can be edited.',
            ]);
        }

        $original = (string) data_get($run->output, 'response.content', '');

        $updated = DB::transaction(function () use ($run, $newContent): AgentRun {
            $locked = AgentRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof AgentRun || $locked->status !== AgentRunStatus::WaitingHuman) {
                throw ValidationException::withMessages([
                    'status' => 'Only runs waiting for human review can be edited.',
                ]);
            }

            /** @var array<string, mixed> $output */
            $output = is_array($locked->output) ? $locked->output : [];
            $existingResponse = data_get($output, 'response');
            /** @var array<string, mixed> $response */
            $response = is_array($existingResponse) ? $existingResponse : [];
            $response['content'] = $newContent;
            $output['response'] = $response;

            $locked->update(['output' => $output]);

            return $locked->refresh();
        });

        return $this->approve->execute($updated, $actorId, 'edited', $original, $notifyRuntime);
    }
}
