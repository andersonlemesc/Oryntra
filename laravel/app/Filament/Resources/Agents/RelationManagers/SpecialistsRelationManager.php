<?php

declare(strict_types=1);

namespace App\Filament\Resources\Agents\RelationManagers;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentSpecialistStatus;
use App\Models\AgentLlmKey;
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
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SpecialistsRelationManager extends RelationManager
{
    protected static string $relationship = 'specialists';

    protected static ?string $title = 'Especialistas';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->heading('Identidade')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        Select::make('status')
                            ->label('Status')
                            ->options(self::statusOptions())
                            ->default(AgentSpecialistStatus::Active->value)
                            ->required(),
                        Textarea::make('description')
                            ->label('Descricao')
                            ->rows(2)
                            ->columnSpanFull(),
                        Textarea::make('role_prompt')
                            ->label('Prompt do papel')
                            ->required()
                            ->rows(5)
                            ->columnSpanFull()
                            ->helperText('Defina a responsabilidade deste especialista. O supervisor usa isso para rotear e o LLM usa para responder.'),
                        TagsInput::make('intent_keywords')
                            ->label('Palavras-chave de intencao')
                            ->required()
                            ->separator(',')
                            ->helperText('Ajuda o roteamento deterministico quando o supervisor LLM nao estiver disponivel.'),
                    ]),

                Section::make('LLM')
                    ->description('Credenciais e modelo usados para gerar a resposta final deste especialista.')
                    ->columns(2)
                    ->schema([
                        Select::make('llm_key_id')
                            ->label('Chave LLM')
                            ->options(fn (): array => self::llmKeyOptions())
                            ->searchable()
                            ->required(),
                        TextInput::make('llm_model')
                            ->label('Modelo')
                            ->required()
                            ->maxLength(128),
                        TextInput::make('llm_temperature')
                            ->label('Temperature')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(2)
                            ->step(0.01)
                            ->default(0.2),
                    ]),

                Section::make('Roteamento e ferramentas')
                    ->columns(2)
                    ->schema([
                        TagsInput::make('tools_allowlist')
                            ->label('Tools permitidas')
                            ->separator(','),
                        TextInput::make('priority')
                            ->label('Prioridade')
                            ->numeric()
                            ->default(100)
                            ->required(),
                        TextInput::make('confidence_threshold')
                            ->label('Threshold confianca')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1)
                            ->step(0.01)
                            ->default(0.6)
                            ->required(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (AgentSpecialistStatus|string $state): string => $state instanceof AgentSpecialistStatus
                        ? $state->label()
                        : AgentSpecialistStatus::from($state)->label())
                    ->color(fn (AgentSpecialistStatus|string $state): string => ($state instanceof AgentSpecialistStatus ? $state : AgentSpecialistStatus::from($state)) === AgentSpecialistStatus::Active
                        ? 'success'
                        : 'gray'),
                TextColumn::make('priority')
                    ->label('Prioridade')
                    ->sortable(),
                TextColumn::make('llmKey.name')
                    ->label('Chave LLM')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('llm_model')
                    ->label('Modelo')
                    ->placeholder('-'),
                TextColumn::make('confidence_threshold')
                    ->label('Threshold')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar especialista')
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
    private static function llmKeyOptions(): array
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return [];
        }

        return AgentLlmKey::query()
            ->where('workspace_id', $tenant->getKey())
            ->where('status', AgentLlmKeyStatus::Active->value)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(AgentSpecialistStatus::cases())
            ->mapWithKeys(fn (AgentSpecialistStatus $s): array => [$s->value => $s->label()])
            ->all();
    }
}
