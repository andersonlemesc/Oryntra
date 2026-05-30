<?php

declare(strict_types=1);

use App\Models\PlaygroundConversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'playground.conversation.{conversation}',
    function (User $user, int $conversation): bool {
        $playgroundConversation = PlaygroundConversation::query()->find($conversation);

        if ($playgroundConversation === null) {
            return false;
        }

        return $user->isSuperAdmin()
            || $user->workspaces()->whereKey($playgroundConversation->workspace_id)->exists();
    },
);
