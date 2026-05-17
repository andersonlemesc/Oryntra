<?php

declare(strict_types=1);

namespace App\Filament\Resources\Agents\Tables;

use App\Enums\AgentResponseMode;
use App\Enums\AgentStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AgentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (AgentStatus|string $state): string => $state instanceof AgentStatus
                        ? $state->label()
                        : AgentStatus::from($state)->label())
                    ->color(fn (AgentStatus|string $state): string => ($state instanceof AgentStatus ? $state : AgentStatus::from($state)) === AgentStatus::Active
                        ? 'success'
                        : 'gray')
                    ->sortable(),
                TextColumn::make('response_mode')
                    ->label('Modo')
                    ->formatStateUsing(fn (AgentResponseMode|string $state): string => $state instanceof AgentResponseMode
                        ? $state->label()
                        : AgentResponseMode::from($state)->label())
                    ->toggleable(),
                TextColumn::make('llm_provider')
                    ->label('Provider')
                    ->toggleable(),
                TextColumn::make('llm_model')
                    ->label('Modelo')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
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
