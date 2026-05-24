<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChatwootConnections\Pages;

use App\Filament\Resources\ChatwootConnections\ChatwootConnectionResource;
use App\Jobs\Chatwoot\SyncChatwootMetadataJob;
use App\Models\ChatwootConnection;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditChatwootConnection extends EditRecord
{
    protected static string $resource = ChatwootConnectionResource::class;

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $save = ['status' => $data['status']];

        if (filled($data['admin_api_token'] ?? null)) {
            $save['admin_api_token'] = $data['admin_api_token'];
        }

        return $save;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncMetadata')
                ->label('Sincronizar agora')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord() instanceof ChatwootConnection
                    && $this->getRecord()->hasAdminApiToken())
                ->action(function (): void {
                    /** @var ChatwootConnection $record */
                    $record = $this->getRecord();
                    SyncChatwootMetadataJob::dispatch($record->id);

                    Notification::make()
                        ->title('Sincronizacao enfileirada')
                        ->body('Times, agentes, membros e labels do Chatwoot serao atualizados em background.')
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
