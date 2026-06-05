<?php

declare(strict_types=1);

namespace App\Actions\Chatwoot;

use App\Models\ChatwootPlatformConnection;
use App\Services\Chatwoot\ChatwootPlatformClient;
use RuntimeException;

class DeleteChatwootAgentBot
{
    public function execute(int $agentBotId): void
    {
        if ($agentBotId <= 0) {
            return;
        }

        $platformConnection = ChatwootPlatformConnection::current();

        if (! $platformConnection->exists || ! $platformConnection->isConfigured()) {
            throw new RuntimeException('Chatwoot Platform connection is not configured.');
        }

        (new ChatwootPlatformClient($platformConnection))->deleteAgentBot($agentBotId);
    }
}
