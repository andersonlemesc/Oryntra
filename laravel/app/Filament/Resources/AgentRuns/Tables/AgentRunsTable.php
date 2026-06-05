<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentRuns\Tables;

use App\Enums\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AgentRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('agent.name')
                    ->label('Agente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (AgentRunStatus|string $state): string => self::statusEnum($state)->label())
                    ->color(fn (AgentRunStatus|string $state): string => match (self::statusEnum($state)) {
                        AgentRunStatus::Completed => 'success',
                        AgentRunStatus::Failed => 'danger',
                        AgentRunStatus::Running => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('conversation_id')
                    ->label('Conversa')
                    ->searchable(),
                TextColumn::make('started_at')
                    ->label('Iniciou')
                    ->dateTime()
                    ->since()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label('Finalizou')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('total_tokens')
                    ->label('Tokens')
                    ->state(fn ($record): int => self::totalTokens($record))
                    ->toggleable(),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions()),
                SelectFilter::make('agent_id')
                    ->label('Agente')
                    ->options(fn (): array => self::agentOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    private static function statusEnum(AgentRunStatus|string $state): AgentRunStatus
    {
        return $state instanceof AgentRunStatus ? $state : AgentRunStatus::from($state);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(AgentRunStatus::cases())
            ->mapWithKeys(fn (AgentRunStatus $s): array => [$s->value => $s->label()])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function agentOptions(): array
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return [];
        }

        return Agent::query()
            ->where('workspace_id', $tenant->getKey())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function totalTokens(AgentRun $record): int
    {
        $usage = $record->output['usage'] ?? [];

        $supervisorInput = $usage['supervisor']['input_tokens'] ?? 0;
        $supervisorOutput = $usage['supervisor']['output_tokens'] ?? 0;
        $specialistInput = $usage['specialist']['input_tokens'] ?? 0;
        $specialistOutput = $usage['specialist']['output_tokens'] ?? 0;

        return $supervisorInput + $supervisorOutput + $specialistInput + $specialistOutput;
    }
}
