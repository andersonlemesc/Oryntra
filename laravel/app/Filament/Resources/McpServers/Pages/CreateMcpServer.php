<?php

declare(strict_types=1);

namespace App\Filament\Resources\McpServers\Pages;

use App\Filament\Resources\McpServers\McpServerResource;
use App\Filament\Resources\McpServers\Support\McpServerFormState;
use Filament\Resources\Pages\CreateRecord;

class CreateMcpServer extends CreateRecord
{
    protected static string $resource = McpServerResource::class;

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return McpServerFormState::assemble($data);
    }
}
