<?php

declare(strict_types=1);

namespace App\Jobs\Chatwoot;

use App\Actions\Chatwoot\ProvisionChatwootAgentBot;
use App\Models\ChatwootConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProvisionChatwootAgentBotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $chatwootConnectionId)
    {
        $this->onQueue('chatwoot-sync');
    }

    /**
     * Execute the job.
     */
    public function handle(ProvisionChatwootAgentBot $provisionAgentBot): void
    {
        $connection = ChatwootConnection::query()->findOrFail($this->chatwootConnectionId);

        $connection->forceFill([
            'provisioning_started_at' => now(),
            'provisioning_error' => null,
        ])->save();

        try {
            $provisionAgentBot->execute($connection);
        } catch (Throwable $exception) {
            $connection->forceFill([
                'provisioning_error' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }
}
