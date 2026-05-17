<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChatwootConnections\Schemas;

use App\Enums\ChatwootConnectionStatus;
use App\Models\ChatwootConnection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ChatwootConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->dehydrated(fn (string $operation): bool => $operation === 'create')
                    ->maxLength(255),
                TextInput::make('agent_bot_id')
                    ->label('Agent Bot')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit'),
                TextInput::make('agent_bot_outgoing_url')
                    ->label('Webhook do Agent Bot')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit'),
                TextInput::make('webhook_secret_status')
                    ->label('Webhook secret')
                    ->default(fn (?ChatwootConnection $record): string => filled($record?->webhook_secret)
                        ? 'Configurado'
                        : 'Não configurado')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit'),
                TextInput::make('provisioning_error')
                    ->label('Erro de provisionamento')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation, ?ChatwootConnection $record): bool => $operation === 'edit'
                        && filled($record?->provisioning_error)),
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
