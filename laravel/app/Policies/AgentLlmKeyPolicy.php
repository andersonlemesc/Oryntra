<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\AuthorizesWorkspaceRoles;

class AgentLlmKeyPolicy
{
    use AuthorizesWorkspaceRoles;
}
