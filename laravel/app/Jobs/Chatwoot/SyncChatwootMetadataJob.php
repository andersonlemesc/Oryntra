<?php

declare(strict_types=1);

namespace App\Jobs\Chatwoot;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class SyncChatwootMetadataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $chatwootConnectionId)
    {
        $this->onQueue('chatwoot-sync');
    }

    public function handle(): void
    {
        Bus::chain([
            new SyncChatwootTeamsJob($this->chatwootConnectionId),
            new SyncChatwootAgentsJob($this->chatwootConnectionId),
            new SyncChatwootTeamMembersJob($this->chatwootConnectionId),
        ])->onQueue('chatwoot-sync')->dispatch();
    }
}
