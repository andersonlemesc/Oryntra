<?php

namespace App\Filament\Resources\ChatwootConnections\Pages;

use App\Filament\Resources\ChatwootConnections\ChatwootConnectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditChatwootConnection extends EditRecord
{
    protected static string $resource = ChatwootConnectionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [
            'status' => $data['status'],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
