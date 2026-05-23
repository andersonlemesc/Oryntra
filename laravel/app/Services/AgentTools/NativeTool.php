<?php

declare(strict_types=1);

namespace App\Services\AgentTools;

enum NativeTool: string
{
    case RequestHumanHandoff = 'request_human_handoff';
    case RequestTeamHandoff = 'request_team_handoff';
    case ChatwootSendMessage = 'chatwoot_send_message';
    case ChatwootAddPrivateNote = 'chatwoot_add_private_note';
    case ChatwootAddLabel = 'chatwoot_add_label';
    case ChatwootAssignTeam = 'chatwoot_assign_team';
    case ChatwootAssignAgent = 'chatwoot_assign_agent';
    case ChatwootGetContact = 'chatwoot_get_contact';
    case ChatwootUpdateContact = 'chatwoot_update_contact';
    case UpdateContactMemory = 'update_contact_memory';
}
