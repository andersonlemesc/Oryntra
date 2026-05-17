<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentLlmKeys\Tables;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AgentLlmKeysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->formatStateUsing(fn (AgentLlmProvider|string $state): string => $state instanceof AgentLlmProvider
                        ? $state->label()
                        : AgentLlmProvider::from($state)->label())
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (AgentLlmKeyStatus|string $state): string => $state instanceof AgentLlmKeyStatus
                        ? $state->label()
                        : AgentLlmKeyStatus::from($state)->label())
                    ->color(fn (AgentLlmKeyStatus|string $state): string => ($state instanceof AgentLlmKeyStatus ? $state : AgentLlmKeyStatus::from($state)) === AgentLlmKeyStatus::Active
                        ? 'success'
                        : 'gray')
                    ->sortable(),
                TextColumn::make('agents_count')
                    ->label('Agentes')
                    ->counts('agents')
                    ->sortable(),
                TextColumn::make('last_used_at')
                    ->label('Ultimo uso')
                    ->dateTime()
                    ->placeholder('Nunca usada')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
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
