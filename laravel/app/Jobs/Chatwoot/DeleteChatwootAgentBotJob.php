<?php

declare(strict_types=1);

namespace App\Jobs\Chatwoot;

use App\Actions\Chatwoot\DeleteChatwootAgentBot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteChatwootAgentBotJob implements ShouldQueue
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
    public function __construct(public int $agentBotId)
    {
        $this->onQueue('chatwoot-sync');
    }

    /**
     * Execute the job.
     */
    public function handle(DeleteChatwootAgentBot $deleteAgentBot): void
    {
        $deleteAgentBot->execute($this->agentBotId);
    }
}
