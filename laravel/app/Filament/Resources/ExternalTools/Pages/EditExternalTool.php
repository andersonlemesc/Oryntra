<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExternalTools\Pages;

use App\Filament\Resources\ExternalTools\ExternalToolResource;
use App\Filament\Resources\ExternalTools\Support\ExternalToolFormState;
use App\Models\ExternalTool;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExternalTool extends EditRecord
{
    protected static string $resource = ExternalToolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ExternalToolFormState::hydrate($data);
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var ExternalTool $record */
        $record = $this->record;

        return ExternalToolFormState::assemble($data, $record);
    }
}
