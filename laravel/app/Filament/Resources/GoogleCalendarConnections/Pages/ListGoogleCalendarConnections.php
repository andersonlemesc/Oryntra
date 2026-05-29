<?php

declare(strict_types=1);

namespace App\Filament\Resources\GoogleCalendarConnections\Pages;

use App\Filament\Resources\GoogleCalendarConnections\GoogleCalendarConnectionResource;
use App\Services\GoogleCalendar\GoogleCalendarConfig;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListGoogleCalendarConnections extends ListRecords
{
    protected static string $resource = GoogleCalendarConnectionResource::class;

    protected function getHeaderActions(): array
    {
        $configured = (new GoogleCalendarConfig(
            clientId: '',
            clientSecret: '',
            redirectUri: '',
        ))->isConfigured();

        if (! $configured) {
            return [
                Action::make('not_configured')
                    ->label('Configurar OAuth no .env primeiro')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->disabled()
                    ->tooltip('Defina GOOGLE_CALENDAR_CLIENT_ID, GOOGLE_CALENDAR_CLIENT_SECRET e GOOGLE_CALENDAR_REDIRECT_URI no .env e reinicie.'),
            ];
        }

        return [
            Action::make('connect')
                ->label('Conectar nova conta Google')
                ->icon('heroicon-o-link')
                ->color('primary')
                ->url(fn (): string => route('oauth.google-calendar.initiate', [
                    'workspace' => Filament::getTenant()?->getKey(),
                ])),
        ];
    }
}
