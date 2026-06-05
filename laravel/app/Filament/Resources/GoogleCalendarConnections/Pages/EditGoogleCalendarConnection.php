<?php

declare(strict_types=1);

namespace App\Filament\Resources\GoogleCalendarConnections\Pages;

use App\Filament\Resources\GoogleCalendarConnections\GoogleCalendarConnectionResource;
use App\Models\GoogleCalendarConnection;
use App\Services\GoogleCalendar\GoogleCalendarClient;
use App\Services\GoogleCalendar\GoogleCalendarConfig;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditGoogleCalendarConnection extends EditRecord
{
    protected static string $resource = GoogleCalendarConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reconnect')
                ->label('Reconectar')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->getRecord() instanceof GoogleCalendarConnection)
                ->url(fn (): string => route('oauth.google-calendar.initiate', [
                    'workspace' => Filament::getTenant()?->getKey(),
                ])),

            Action::make('revoke')
                ->label('Desconectar (revogar)')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Revogar acesso ao Google Calendar?')
                ->modalDescription('Vai apagar tokens daqui e revogar a autorização no Google. A conexão fica inativa até reconectar.')
                ->visible(fn (): bool => $this->getRecord() instanceof GoogleCalendarConnection
                    && filled($this->getRecord()->access_token))
                ->action(function (): void {
                    /** @var GoogleCalendarConnection $record */
                    $record = $this->getRecord();

                    try {
                        $client = new GoogleCalendarClient($record, GoogleCalendarConfig::fromConfig());
                        $client->revoke();

                        Notification::make()
                            ->title('Conexão revogada')
                            ->body('Tokens removidos. Reconecte se quiser usar de novo.')
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Falha ao revogar')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            DeleteAction::make(),
        ];
    }
}
