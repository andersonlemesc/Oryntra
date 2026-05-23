<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Jobs\Chatwoot\SyncChatwootContactsJob;
use App\Models\ChatwootConnection;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListContacts extends ListRecords
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_chatwoot_contacts')
                ->label('Sincronizar com Chatwoot')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Dispara um job que importa todos os contatos das conexoes Chatwoot deste workspace. Pode levar alguns minutos.')
                ->action(function (): void {
                    $tenant = Filament::getTenant();

                    if ($tenant === null) {
                        return;
                    }

                    $count = ChatwootConnection::query()
                        ->where('workspace_id', $tenant->getKey())
                        ->whereNotNull('admin_api_token')
                        ->whereNotNull('base_url')
                        ->pluck('id')
                        ->each(fn (int $connectionId) => SyncChatwootContactsJob::dispatch($connectionId))
                        ->count();

                    Notification::make()
                        ->title($count > 0 ? "Sincronizacao disparada para {$count} conexao(oes)." : 'Nenhuma conexao Chatwoot com admin_api_token configurado.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
