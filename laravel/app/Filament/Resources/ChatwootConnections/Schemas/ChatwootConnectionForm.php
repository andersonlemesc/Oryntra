<?php

namespace App\Filament\Resources\ChatwootConnections\Schemas;

use App\Enums\ChatwootConnectionStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ChatwootConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('base_url')
                    ->label('URL base')
                    ->url()
                    ->required()
                    ->maxLength(2048)
                    ->dehydrateStateUsing(fn (string $state): string => Str::of($state)->trim()->rtrim('/')->toString()),
                TextInput::make('account_id')
                    ->label('Account ID')
                    ->integer()
                    ->minValue(1)
                    ->required(),
                TextInput::make('api_access_token')
                    ->label('API access token')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(4096),
                TextInput::make('webhook_secret')
                    ->label('Webhook secret')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(4096),
                Select::make('status')
                    ->label('Status')
                    ->options(self::statusOptions())
                    ->default(ChatwootConnectionStatus::Active->value)
                    ->required(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(ChatwootConnectionStatus::cases())
            ->mapWithKeys(fn (ChatwootConnectionStatus $status): array => [$status->value => $status->label()])
            ->all();
    }
}
