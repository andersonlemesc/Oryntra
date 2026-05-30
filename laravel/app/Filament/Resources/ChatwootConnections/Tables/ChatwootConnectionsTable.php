<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChatwootConnections\Tables;

use App\Enums\ChatwootConnectionStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChatwootConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('base_url')
                    ->label('URL base')
                    ->searchable()
                    ->limit(48)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('account_id')
                    ->label('Account ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('agent_bot_id')
                    ->label('Agent Bot')
                    ->placeholder('Pendente')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ChatwootConnectionStatus|string $state): string => $state instanceof ChatwootConnectionStatus
                        ? $state->label()
                        : ChatwootConnectionStatus::from($state)->label())
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('provisioning_error')
                    ->label('Erro')
                    ->limit(48)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('Nenhuma conexão Chatwoot configurada')
            ->emptyStateDescription('Crie uma conexão para provisionar o Agent Bot e ativar os agentes neste workspace.')
            ->emptyStateIcon(Heroicon::OutlinedChatBubbleLeftRight)
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
