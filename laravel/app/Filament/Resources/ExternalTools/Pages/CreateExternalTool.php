<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExternalTools\Pages;

use App\Filament\Resources\ExternalTools\ExternalToolResource;
use App\Filament\Resources\ExternalTools\Support\ExternalToolFormState;
use Filament\Resources\Pages\CreateRecord;

class CreateExternalTool extends CreateRecord
{
    protected static string $resource = ExternalToolResource::class;

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ExternalToolFormState::assemble($data);
    }
}
