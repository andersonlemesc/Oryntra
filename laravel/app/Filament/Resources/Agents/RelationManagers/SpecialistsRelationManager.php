<?php

declare(strict_types=1);

namespace App\Filament\Resources\Agents\RelationManagers;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentSpecialistStatus;
use App\Models\AgentLlmKey;
use App\Services\AgentTools\NativeTool;
use App\Services\AgentTools\NativeToolRegistry;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
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
                Tabs::make('specialist')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Identidade')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Section::make()
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
                                            ->helperText('Defina a responsabilidade deste especialista. O supervisor usa isso para rotear e o LLM usa para responder.')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Instrucao em texto que define o "papel" do especialista. Exemplo: "Voce e especialista em vendas. Tire duvidas sobre precos, planos e prazos. Nunca fale de suporte tecnico."'),
                                        TagsInput::make('intent_keywords')
                                            ->label('Palavras-chave de intencao')
                                            ->required()
                                            ->separator(',')
                                            ->helperText('Ajuda o roteamento deterministico quando o supervisor LLM nao estiver disponivel.')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Lista de palavras que o cliente pode usar para indicar essa intencao. Ex.: para Vendas use "preco, comprar, cotacao, plano". Funciona como fallback se o supervisor LLM falhar.'),
                                    ]),
                            ]),

                        Tab::make('Modelo')
                            ->icon('heroicon-o-cpu-chip')
                            ->schema([
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
                            ]),

                        Tab::make('Roteamento')
                            ->icon('heroicon-o-arrows-right-left')
                            ->schema([
                                Section::make('Roteamento e ferramentas')
                                    ->columns(2)
                                    ->schema([
                                        TagsInput::make('tools_allowlist')
                                            ->label('Tools permitidas')
                                            ->suggestions(fn (): array => app(NativeToolRegistry::class)->options())
                                            ->separator(',')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Lista das ferramentas que este especialista pode chamar (enviar mensagem, transferir para humano, atribuir time, etc.). Sem isso, o especialista so consegue responder em texto.'),
                                        TextInput::make('priority')
                                            ->label('Prioridade')
                                            ->numeric()
                                            ->default(100)
                                            ->required()
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Em caso de empate de intencao, o especialista com menor numero atende primeiro. Use 100 como padrao e ajuste somente se precisar forcar ordem.'),
                                        TextInput::make('confidence_threshold')
                                            ->label('Threshold confianca')
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(1)
                                            ->step(0.01)
                                            ->default(0.6)
                                            ->required()
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Confianca minima (0 a 1) para o supervisor rotear esta conversa para o especialista. 0.6 = so envia se tiver 60%+ de certeza de que e esta intencao.'),
                                    ]),
                            ]),

                        Tab::make('Transferencia humana')
                            ->icon('heroicon-o-arrow-uturn-right')
                            ->schema([
                                Section::make('Configuracao')
                                    ->description('Regras explicitas para pausar a automacao e transferir a conversa para atendimento humano.')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('handoff_config.enabled')
                                            ->label('Permitir transferencia humana')
                                            ->live()
                                            ->default(false)
                                            ->helperText('Quando habilitado, a tool request_human_handoff sera adicionada automaticamente ao especialista.')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Permite que este especialista escale a conversa para um atendente humano em situacoes definidas nas regras abaixo (ou quando a IA achar que nao consegue resolver).'),
                                        Select::make('handoff_config.default_priority')
                                            ->label('Prioridade padrao')
                                            ->options(self::handoffPriorityOptions())
                                            ->default('normal')
                                            ->required(),
                                        Textarea::make('handoff_config.customer_message')
                                            ->label('Mensagem ao cliente')
                                            ->rows(2)
                                            ->default('Vou transferir voce para um atendente.')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('Situacoes de transferencia')
                                    ->description('Cada regra dispara um handoff quando o cliente menciona palavras-chave especificas.')
                                    ->schema([
                                        Repeater::make('handoff_config.rules')
                                            ->hiddenLabel()
                                            ->collapsible()
                                            ->collapsed()
                                            ->itemLabel(fn (array $state): string => self::ruleItemLabel($state))
                                            ->addActionLabel('Adicionar regra')
                                            ->schema([
                                                TextInput::make('name')
                                                    ->label('Nome')
                                                    ->required()
                                                    ->maxLength(120),
                                                Toggle::make('enabled')
                                                    ->label('Ativa')
                                                    ->default(true),
                                                TagsInput::make('keywords')
                                                    ->label('Palavras-chave')
                                                    ->separator(',')
                                                    ->required()
                                                    ->helperText('Ex: humano, atendente, cancelar, reembolso.'),
                                                Select::make('priority')
                                                    ->label('Prioridade')
                                                    ->options(self::handoffPriorityOptions())
                                                    ->default('normal')
                                                    ->required(),
                                                Textarea::make('reason')
                                                    ->label('Motivo interno')
                                                    ->rows(2)
                                                    ->required()
                                                    ->columnSpanFull(),
                                                Textarea::make('customer_message')
                                                    ->label('Mensagem especifica ao cliente')
                                                    ->rows(2)
                                                    ->columnSpanFull()
                                                    ->helperText('Opcional. Sobrescreve a mensagem geral so para esta situacao.'),
                                            ])
                                            ->columns(2)
                                            ->defaultItems(0)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function ruleItemLabel(array $state): string
    {
        $name = $state['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            return 'Nova regra';
        }

        return $name;
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

                        return self::normalizeSpecialistFormData($data);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => self::normalizeSpecialistFormData($data)),
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

    /**
     * @return array<string, string>
     */
    private static function handoffPriorityOptions(): array
    {
        return [
            'low' => 'Baixa',
            'normal' => 'Normal',
            'high' => 'Alta',
            'urgent' => 'Urgente',
        ];
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function normalizeSpecialistFormData(array $data): array
    {
        $handoffConfig = is_array($data['handoff_config'] ?? null)
            ? $data['handoff_config']
            : [];
        $handoffEnabled = (bool) ($handoffConfig['enabled'] ?? false);
        $toolsAllowlist = is_array($data['tools_allowlist'] ?? null)
            ? array_values($data['tools_allowlist'])
            : [];

        if ($handoffEnabled && ! in_array(NativeTool::RequestHumanHandoff->value, $toolsAllowlist, true)) {
            $toolsAllowlist[] = NativeTool::RequestHumanHandoff->value;
        }

        if (! $handoffEnabled) {
            $toolsAllowlist = array_values(array_filter(
                $toolsAllowlist,
                fn (mixed $tool): bool => $tool !== NativeTool::RequestHumanHandoff->value,
            ));
        }

        $handoffConfig['default_priority'] = $handoffConfig['default_priority'] ?? 'normal';
        $handoffConfig['customer_message'] = $handoffConfig['customer_message']
            ?? 'Vou transferir voce para um atendente.';
        $handoffConfig['rules'] = is_array($handoffConfig['rules'] ?? null)
            ? array_values($handoffConfig['rules'])
            : [];
        $handoffConfig['rules'] = array_map(
            fn (mixed $rule): array => self::normalizeHandoffRule($rule),
            $handoffConfig['rules'],
        );

        $data['tools_allowlist'] = $toolsAllowlist;
        $data['handoff_config'] = $handoffConfig;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeHandoffRule(mixed $rule): array
    {
        if (! is_array($rule)) {
            return [];
        }

        $keywords = $rule['keywords'] ?? [];

        if (is_string($keywords)) {
            $keywords = array_values(array_filter(array_map(
                fn (string $keyword): string => trim($keyword),
                explode(',', $keywords),
            )));
        }

        if (! is_array($keywords)) {
            $keywords = [];
        }

        $rule['keywords'] = array_values($keywords);
        $rule['enabled'] = (bool) ($rule['enabled'] ?? true);
        $rule['priority'] = $rule['priority'] ?? 'normal';

        return $rule;
    }
}
