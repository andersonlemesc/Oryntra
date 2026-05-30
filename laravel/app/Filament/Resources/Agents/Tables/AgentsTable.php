<?php

declare(strict_types=1);

namespace App\Filament\Resources\Agents\Tables;

use App\Enums\AgentLlmProvider;
use App\Enums\AgentMode;
use App\Enums\AgentResponseMode;
use App\Enums\AgentStatus;
use App\Models\Agent;
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
                    ->label('Resposta')
                    ->formatStateUsing(fn (AgentResponseMode|string $state): string => $state instanceof AgentResponseMode
                        ? $state->label()
                        : AgentResponseMode::from($state)->label())
                    ->toggleable(),
                TextColumn::make('mode')
                    ->label('Modo')
                    ->badge()
                    ->formatStateUsing(fn (AgentMode|string $state): string => $state instanceof AgentMode
                        ? $state->label()
                        : AgentMode::from($state)->label())
                    ->toggleable(),
                TextColumn::make('runtime_provider')
                    ->label('Provider runtime')
                    ->getStateUsing(fn (Agent $record): ?string => self::runtimeProvider($record))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('runtime_model')
                    ->label('Modelo runtime')
                    ->getStateUsing(fn (Agent $record): ?string => self::runtimeModel($record))
                    ->placeholder('-')
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

    private static function runtimeProvider(Agent $agent): ?string
    {
        if (self::isSupervisor($agent)) {
            return self::providerValue($agent->supervisorLlmKey?->provider);
        }

        return self::providerValue($agent->specialists()->with('llmKey')->first()?->llmKey?->provider);
    }

    private static function runtimeModel(Agent $agent): ?string
    {
        if (self::isSupervisor($agent)) {
            return $agent->supervisor_llm_model;
        }

        return $agent->specialists()->first()?->llm_model;
    }

    private static function isSupervisor(Agent $agent): bool
    {
        $mode = $agent->mode;

        return $mode instanceof AgentMode
            ? $mode === AgentMode::Supervisor
            : $mode === AgentMode::Supervisor->value;
    }

    private static function providerValue(AgentLlmProvider|string|null $provider): ?string
    {
        return $provider instanceof AgentLlmProvider ? $provider->value : $provider;
    }
}
