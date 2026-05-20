<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentRuns\Schemas;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class AgentRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('run')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Resumo')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Section::make('Identidade')
                                    ->columns(2)
                                    ->schema([
                                        TextEntry::make('agent.name')->label('Agente'),
                                        TextEntry::make('status')
                                            ->label('Status')
                                            ->badge()
                                            ->formatStateUsing(fn (AgentRunStatus|string $state): string => self::statusEnum($state)->label())
                                            ->color(fn (AgentRunStatus|string $state): string => self::statusColor(self::statusEnum($state))),
                                        TextEntry::make('thread_id')->label('Thread')->copyable(),
                                        TextEntry::make('conversation_id')->label('Conversa'),
                                        TextEntry::make('chatwoot_account_id')->label('Conta Chatwoot'),
                                        TextEntry::make('chatwoot_message_id')->label('Mensagem Chatwoot'),
                                    ]),
                                Section::make('Tempo')
                                    ->columns(2)
                                    ->schema([
                                        TextEntry::make('debounce_started_at')->label('Debounce iniciou')->dateTime()->placeholder('-'),
                                        TextEntry::make('debounce_until')->label('Debounce ate')->dateTime()->placeholder('-'),
                                        TextEntry::make('started_at')->label('Iniciou')->dateTime()->placeholder('-'),
                                        TextEntry::make('finished_at')->label('Finalizou')->dateTime()->placeholder('-'),
                                        TextEntry::make('duration')
                                            ->label('Duracao')
                                            ->state(fn (AgentRun $record): string => self::duration($record))
                                            ->placeholder('-'),
                                    ]),
                            ]),

                        Tab::make('Handoff')
                            ->icon('heroicon-o-arrow-uturn-right')
                            ->visible(fn (AgentRun $record): bool => filled(data_get($record->output, 'handoff')))
                            ->schema([
                                Section::make('Pedido')
                                    ->columns(2)
                                    ->schema([
                                        TextEntry::make('handoff_reason')
                                            ->label('Motivo')
                                            ->state(fn (AgentRun $record): ?string => data_get($record->output, 'handoff.reason'))
                                            ->placeholder('-'),
                                        TextEntry::make('handoff_priority')
                                            ->label('Prioridade')
                                            ->badge()
                                            ->state(fn (AgentRun $record): ?string => data_get($record->output, 'handoff.priority'))
                                            ->color(fn (?string $state): string => match ($state) {
                                                'urgent' => 'danger',
                                                'high' => 'warning',
                                                'normal' => 'info',
                                                'low' => 'gray',
                                                default => 'gray',
                                            })
                                            ->placeholder('-'),
                                        TextEntry::make('handoff_team')
                                            ->label('Time sugerido')
                                            ->state(fn (AgentRun $record): ?string => data_get($record->output, 'handoff.suggested_team'))
                                            ->placeholder('-'),
                                        TextEntry::make('handoff_customer_message')
                                            ->label('Mensagem ao cliente')
                                            ->state(fn (AgentRun $record): ?string => data_get($record->output, 'handoff.customer_message'))
                                            ->columnSpanFull()
                                            ->placeholder('-'),
                                        TextEntry::make('handoff_private_note')
                                            ->label('Nota interna')
                                            ->state(fn (AgentRun $record): ?string => data_get($record->output, 'handoff.private_note'))
                                            ->columnSpanFull()
                                            ->placeholder('-'),
                                    ]),
                                Section::make('Side effects')
                                    ->columns(2)
                                    ->schema([
                                        TextEntry::make('side_effects_status')
                                            ->label('Status do job')
                                            ->badge()
                                            ->state(fn (AgentRun $record): ?string => data_get($record->output, 'handoff.side_effects.status'))
                                            ->color(fn (?string $state): string => self::actionColor($state))
                                            ->placeholder('-'),
                                        TextEntry::make('side_effects_job_id')
                                            ->label('Job ID')
                                            ->state(fn (AgentRun $record): mixed => data_get($record->output, 'handoff.side_effects.job_id'))
                                            ->placeholder('-'),
                                        TextEntry::make('action_customer_message')
                                            ->label('Mensagem ao cliente')
                                            ->badge()
                                            ->state(fn (AgentRun $record): ?string => data_get($record->output, 'handoff.side_effects.actions.customer_message'))
                                            ->color(fn (?string $state): string => self::actionColor($state))
                                            ->placeholder('-'),
                                        TextEntry::make('action_private_note')
                                            ->label('Nota interna')
                                            ->badge()
                                            ->state(fn (AgentRun $record): ?string => data_get($record->output, 'handoff.side_effects.actions.private_note'))
                                            ->color(fn (?string $state): string => self::actionColor($state))
                                            ->placeholder('-'),
                                        TextEntry::make('action_label')
                                            ->label('Label')
                                            ->badge()
                                            ->state(fn (AgentRun $record): ?string => data_get($record->output, 'handoff.side_effects.actions.label'))
                                            ->color(fn (?string $state): string => self::actionColor($state))
                                            ->placeholder('-'),
                                        TextEntry::make('action_team_assignment')
                                            ->label('Atribuir time')
                                            ->badge()
                                            ->state(fn (AgentRun $record): ?string => data_get($record->output, 'handoff.side_effects.actions.team_assignment'))
                                            ->color(fn (?string $state): string => self::actionColor($state))
                                            ->placeholder('-'),
                                        TextEntry::make('action_agent_assignment')
                                            ->label('Atribuir atendente')
                                            ->badge()
                                            ->state(fn (AgentRun $record): ?string => data_get($record->output, 'handoff.side_effects.actions.agent_assignment'))
                                            ->color(fn (?string $state): string => self::actionColor($state))
                                            ->placeholder('-'),
                                        TextEntry::make('side_effects_error')
                                            ->label('Erro')
                                            ->state(fn (AgentRun $record): ?string => data_get($record->output, 'handoff.side_effects.error'))
                                            ->columnSpanFull()
                                            ->placeholder('-'),
                                    ]),
                            ]),

                        Tab::make('Trace bruto')
                            ->icon('heroicon-o-code-bracket-square')
                            ->schema([
                                TextEntry::make('output_json')
                                    ->label('Output')
                                    ->state(fn (AgentRun $record): string => self::prettyJson($record->output))
                                    ->columnSpanFull()
                                    ->copyable()
                                    ->placeholder('-'),
                                TextEntry::make('input_json')
                                    ->label('Input')
                                    ->state(fn (AgentRun $record): string => self::prettyJson($record->input))
                                    ->columnSpanFull()
                                    ->copyable()
                                    ->placeholder('-'),
                            ]),

                        Tab::make('Erros')
                            ->icon('heroicon-o-exclamation-triangle')
                            ->visible(fn (AgentRun $record): bool => self::statusEnum($record->status) === AgentRunStatus::Failed)
                            ->schema([
                                Section::make('Falha')
                                    ->schema([
                                        TextEntry::make('error_message')->label('Erro')->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    private static function statusEnum(AgentRunStatus|string $state): AgentRunStatus
    {
        return $state instanceof AgentRunStatus ? $state : AgentRunStatus::from($state);
    }

    private static function statusColor(AgentRunStatus $status): string
    {
        return match ($status) {
            AgentRunStatus::Completed => 'success',
            AgentRunStatus::WaitingHuman => 'warning',
            AgentRunStatus::Failed => 'danger',
            AgentRunStatus::Running => 'info',
            default => 'gray',
        };
    }

    private static function actionColor(?string $state): string
    {
        return match ($state) {
            'completed' => 'success',
            'queued' => 'info',
            'failed' => 'danger',
            'skipped' => 'warning',
            'pending' => 'gray',
            default => 'gray',
        };
    }

    private static function prettyJson(mixed $value): string
    {
        if ($value === null || $value === [] || $value === '') {
            return '-';
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '-' : $encoded;
    }

    private static function duration(AgentRun $record): string
    {
        $start = $record->started_at;
        $end = $record->finished_at;

        if (! $start instanceof CarbonInterface || ! $end instanceof CarbonInterface) {
            return '-';
        }

        $seconds = $end->diffInSeconds($start);

        return sprintf('%ds', max(0, (int) $seconds));
    }
}
