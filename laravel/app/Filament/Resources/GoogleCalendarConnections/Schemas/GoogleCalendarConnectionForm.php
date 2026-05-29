<?php

declare(strict_types=1);

namespace App\Filament\Resources\GoogleCalendarConnections\Schemas;

use App\Models\GoogleCalendarConnection;
use App\Services\GoogleCalendar\Exceptions\GoogleCalendarException;
use App\Services\GoogleCalendar\GoogleCalendarClient;
use App\Services\GoogleCalendar\GoogleCalendarConfig;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Throwable;

class GoogleCalendarConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identidade')
                    ->description('Dados vindos do Google após o consent. Apenas o label é editável.')
                    ->schema([
                        TextInput::make('google_email')
                            ->label('Conta Google')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('label')
                            ->label('Apelido (interno)')
                            ->helperText('Como esse calendário aparece pros admins na hora de escolher no agente.')
                            ->required()
                            ->maxLength(120),
                    ])
                    ->columns(2),

                Section::make('Configuração de uso')
                    ->schema([
                        Select::make('default_calendar_id')
                            ->label('Calendário padrão')
                            ->options(fn (?GoogleCalendarConnection $record): array => self::calendarOptions($record))
                            ->helperText('Quando o agente não especificar um calendário, este é usado.')
                            ->searchable()
                            ->required(),
                        Toggle::make('default_notify_attendees')
                            ->label('Notificar convidados por email')
                            ->helperText('Default global pra `sendUpdates`. O agente pode sobrescrever por chamada.')
                            ->default(true),
                        Toggle::make('is_active')
                            ->label('Ativa')
                            ->helperText('Desativada não responde a chamadas das tools. Útil pra suspender sem deletar.')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Diagnóstico')
                    ->visible(fn (?GoogleCalendarConnection $record): bool => filled($record?->last_error))
                    ->schema([
                        TextInput::make('last_error')
                            ->label('Último erro')
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function calendarOptions(?GoogleCalendarConnection $record): array
    {
        if (! $record) {
            return ['primary' => 'Primary'];
        }

        try {
            $client = new GoogleCalendarClient($record, GoogleCalendarConfig::fromConfig());
            $calendars = $client->listCalendars();
        } catch (GoogleCalendarException|Throwable) {
            return [
                $record->default_calendar_id ?? 'primary' => ($record->default_calendar_id ?? 'primary') . ' (não foi possível listar)',
            ];
        }

        $options = [];
        foreach ($calendars as $calendar) {
            $suffix = $calendar['primary'] ? ' (primary)' : '';
            $options[$calendar['id']] = $calendar['summary'] . $suffix;
        }

        return $options;
    }
}
