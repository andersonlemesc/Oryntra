<?php

declare(strict_types=1);

namespace App\Filament\Resources\Agents\RelationManagers;

use App\Enums\AgentChatwootBindingStatus;
use App\Models\ChatwootConnection;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChatwootBindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'chatwootBindings';

    protected static ?string $title = 'Vinculos Chatwoot';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        Select::make('chatwoot_connection_id')
                            ->label('Conexao Chatwoot')
                            ->options(fn (): array => self::workspaceConnectionOptions())
                            ->searchable()
                            ->required(),
                        Select::make('status')
                            ->label('Status')
                            ->options(self::statusOptions())
                            ->default(AgentChatwootBindingStatus::Active->value)
                            ->required(),
                        TagsInput::make('inbox_ids')
                            ->label('Inboxes (IDs)')
                            ->helperText('Vazio = todas as inboxes')
                            ->placeholder('1, 2, 3')
                            ->separator(','),
                        Toggle::make('ignore_assigned_conversations')
                            ->label('Ignorar conversas ja atribuidas a humano'),
                        TagsInput::make('ignore_label_names')
                            ->label('Labels ignoradas')
                            ->placeholder('spam, fora-horario')
                            ->separator(','),
                        TextInput::make('handoff_label_name')
                            ->label('Label de handoff')
                            ->maxLength(255),
                        Select::make('handoff_assign_strategy')
                            ->label('Destino do handoff')
                            ->options(self::handoffAssignStrategyOptions())
                            ->default('none')
                            ->live()
                            ->required(),
                        TextInput::make('handoff_team_id')
                            ->label('ID do time Chatwoot')
                            ->numeric()
                            ->visible(fn (Get $get): bool => in_array($get('handoff_assign_strategy'), ['team', 'team_then_agent'], true)),
                        TextInput::make('handoff_team_name')
                            ->label('Nome do time')
                            ->visible(fn (Get $get): bool => in_array($get('handoff_assign_strategy'), ['team', 'team_then_agent'], true)),
                        TextInput::make('handoff_agent_id')
                            ->label('ID do atendente Chatwoot')
                            ->numeric()
                            ->visible(fn (Get $get): bool => in_array($get('handoff_assign_strategy'), ['agent', 'team_then_agent'], true)),
                        TextInput::make('handoff_agent_name')
                            ->label('Nome do atendente')
                            ->visible(fn (Get $get): bool => in_array($get('handoff_assign_strategy'), ['agent', 'team_then_agent'], true)),
                        Textarea::make('handoff_private_note_template')
                            ->label('Nota interna para atendente')
                            ->rows(4)
                            ->helperText('Use {reason}, {priority}, {specialist_id}, {conversation_id} e {customer_message}.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('chatwoot_connection_id')
            ->columns([
                TextColumn::make('chatwootConnection.name')
                    ->label('Conexao'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (AgentChatwootBindingStatus|string $state): string => $state instanceof AgentChatwootBindingStatus
                        ? $state->label()
                        : AgentChatwootBindingStatus::from($state)->label())
                    ->color(fn (AgentChatwootBindingStatus|string $state): string => ($state instanceof AgentChatwootBindingStatus ? $state : AgentChatwootBindingStatus::from($state)) === AgentChatwootBindingStatus::Active
                        ? 'success'
                        : 'gray'),
                IconColumn::make('ignore_assigned_conversations')
                    ->label('Ignora atribuidas')
                    ->boolean(),
                TextColumn::make('handoff_label_name')
                    ->label('Label handoff')
                    ->placeholder('-'),
                TextColumn::make('handoff_assign_strategy')
                    ->label('Destino')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::handoffAssignStrategyOptions()[$state ?? 'none'] ?? 'Sem atribuicao automatica'),
                TextColumn::make('handoff_team_name')
                    ->label('Time')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('handoff_agent_name')
                    ->label('Atendente')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $tenant = Filament::getTenant();
                        $data['workspace_id'] = $tenant?->getKey();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function workspaceConnectionOptions(): array
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return [];
        }

        return ChatwootConnection::query()
            ->where('workspace_id', $tenant->getKey())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(AgentChatwootBindingStatus::cases())
            ->mapWithKeys(fn (AgentChatwootBindingStatus $s): array => [$s->value => $s->label()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function handoffAssignStrategyOptions(): array
    {
        return [
            'none' => 'Sem atribuicao automatica',
            'team' => 'Atribuir para time',
            'agent' => 'Atribuir para atendente',
            'team_then_agent' => 'Atribuir para time e atendente',
        ];
    }
}
