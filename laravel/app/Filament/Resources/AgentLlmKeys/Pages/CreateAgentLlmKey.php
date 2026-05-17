<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentLlmKeys\Pages;

use App\Filament\Resources\AgentLlmKeys\AgentLlmKeyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAgentLlmKey extends CreateRecord
{
    protected static string $resource = AgentLlmKeyResource::class;
}
