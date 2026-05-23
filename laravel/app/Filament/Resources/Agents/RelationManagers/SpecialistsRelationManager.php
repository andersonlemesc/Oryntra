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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

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

                        Tab::make('Transferencia para time')
                            ->icon('heroicon-o-user-group')
                            ->schema([
                                Section::make('Configuracao')
                                    ->description('Permite a IA transferir a conversa para um time Chatwoot. Se um time for selecionado, a aba "Transferencia humana" so listara atendentes desse time.')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('handoff_config.team_enabled')
                                            ->label('Permitir transferencia para time')
                                            ->live()
                                            ->default(false)
                                            ->helperText('Quando habilitado, a tool request_team_handoff sera adicionada automaticamente ao especialista.'),
                                        Select::make('handoff_config.team_id')
                                            ->label('Time destino')
                                            ->options(fn (): array => self::chatwootTeamOptions())
                                            ->searchable()
                                            ->live()
                                            ->placeholder('Nenhum - apenas abrir conversa')
                                            ->visible(fn (Get $get): bool => (bool) $get('handoff_config.team_enabled'))
                                            ->helperText('Opcional. Se vazio, a conversa abre mas nao e atribuida a nenhum time.'),
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
                                        Toggle::make('handoff_config.summary_llm_enabled')
                                            ->label('Gerar resumo LLM no handoff')
                                            ->default(false)
                                            ->visible(fn (Get $get): bool => (bool) $get('handoff_config.enabled'))
                                            ->helperText('Quando ativo, gera resumo + fato relevante da conversa via LLM antes de criar a nota privada. Custo de tokens adicional.'),
                                        Select::make('handoff_config.default_priority')
                                            ->label('Prioridade padrao')
                                            ->options(self::handoffPriorityOptions())
                                            ->default('normal')
                                            ->required(),
                                        Select::make('handoff_config.agent_id')
                                            ->label('Atendente destino')
                                            ->options(fn (Get $get): array => self::chatwootAgentOptions(
                                                $get('handoff_config.team_enabled') ? $get('handoff_config.team_id') : null,
                                            ))
                                            ->searchable()
                                            ->placeholder('Nenhum - apenas abrir conversa')
                                            ->visible(fn (Get $get): bool => (bool) $get('handoff_config.enabled'))
                                            ->helperText('Opcional. Se um time foi selecionado, a lista filtra so membros desse time. Se vazio, a conversa abre mas nao e atribuida a ninguem.'),
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

                        Tab::make('Contatos Chatwoot')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Section::make('Edicao de contato')
                                    ->description('Permite a IA ler e atualizar dados de contato no Chatwoot.')
                                    ->schema([
                                        Toggle::make('contact_tools_config.update_enabled')
                                            ->label('Permitir editar contato')
                                            ->default(false)
                                            ->helperText('Quando habilitado, a IA pode chamar chatwoot_get_contact e chatwoot_update_contact. So edita nome, email e telefone. Nao mexe em custom attributes.'),
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
        $contactConfig = is_array($data['contact_tools_config'] ?? null)
            ? $data['contact_tools_config']
            : [];

        $humanEnabled = (bool) ($handoffConfig['enabled'] ?? false);
        $teamEnabled = (bool) ($handoffConfig['team_enabled'] ?? false);
        $contactUpdateEnabled = (bool) ($contactConfig['update_enabled'] ?? false);

        $toolsAllowlist = is_array($data['tools_allowlist'] ?? null)
            ? array_values($data['tools_allowlist'])
            : [];

        $toolsAllowlist = self::reconcileTool($toolsAllowlist, NativeTool::RequestHumanHandoff->value, $humanEnabled);
        $toolsAllowlist = self::reconcileTool($toolsAllowlist, NativeTool::RequestTeamHandoff->value, $teamEnabled);
        $toolsAllowlist = self::reconcileTool($toolsAllowlist, NativeTool::ChatwootGetContact->value, $contactUpdateEnabled);
        $toolsAllowlist = self::reconcileTool($toolsAllowlist, NativeTool::ChatwootUpdateContact->value, $contactUpdateEnabled);

        $handoffConfig['summary_llm_enabled'] = (bool) ($handoffConfig['summary_llm_enabled'] ?? false);
        $handoffConfig['default_priority'] = $handoffConfig['default_priority'] ?? 'normal';
        $handoffConfig['customer_message'] = $handoffConfig['customer_message']
            ?? 'Vou transferir voce para um atendente.';
        $handoffConfig['agent_id'] = $humanEnabled && filled($handoffConfig['agent_id'] ?? null)
            ? (int) $handoffConfig['agent_id']
            : null;
        $handoffConfig['team_enabled'] = $teamEnabled;
        $handoffConfig['team_id'] = $teamEnabled && filled($handoffConfig['team_id'] ?? null)
            ? (int) $handoffConfig['team_id']
            : null;
        $handoffConfig['rules'] = is_array($handoffConfig['rules'] ?? null)
            ? array_values($handoffConfig['rules'])
            : [];
        $handoffConfig['rules'] = array_map(
            fn (mixed $rule): array => self::normalizeHandoffRule($rule),
            $handoffConfig['rules'],
        );

        $contactConfig['update_enabled'] = $contactUpdateEnabled;
        $contactConfig['update_fields'] = ['name', 'email', 'phone_number'];

        $data['tools_allowlist'] = $toolsAllowlist;
        $data['handoff_config'] = $handoffConfig;
        $data['contact_tools_config'] = $contactConfig;
        $data['intent_keywords'] = self::normalizeKeywordList($data['intent_keywords'] ?? null);

        return $data;
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeKeywordList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), fn (string $item): bool => $item !== ''));
    }

    /**
     * @param  array<int, mixed> $toolsAllowlist
     * @return array<int, mixed>
     */
    private static function reconcileTool(array $toolsAllowlist, string $tool, bool $enabled): array
    {
        if ($enabled && ! in_array($tool, $toolsAllowlist, true)) {
            $toolsAllowlist[] = $tool;
        }

        if (! $enabled) {
            $toolsAllowlist = array_values(array_filter(
                $toolsAllowlist,
                fn (mixed $candidate): bool => $candidate !== $tool,
            ));
        }

        return $toolsAllowlist;
    }

    /**
     * @return array<int, string>
     */
    private static function chatwootAgentOptions(mixed $teamId = null): array
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return [];
        }

        $query = DB::table('workspace_members')
            ->join('users', 'users.id', '=', 'workspace_members.user_id')
            ->where('workspace_members.workspace_id', $tenant->getKey())
            ->whereNotNull('workspace_members.chatwoot_user_id');

        if (is_numeric($teamId) && (int) $teamId > 0) {
            $query->whereIn(
                'workspace_members.chatwoot_user_id',
                DB::table('chatwoot_team_members')
                    ->where('workspace_id', $tenant->getKey())
                    ->where('chatwoot_team_id', (int) $teamId)
                    ->pluck('chatwoot_user_id'),
            );
        }

        return $query
            ->orderBy('users.name')
            ->pluck('users.name', 'workspace_members.chatwoot_user_id')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function chatwootTeamOptions(): array
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return [];
        }

        return DB::table('chatwoot_teams')
            ->where('workspace_id', $tenant->getKey())
            ->orderBy('name')
            ->pluck('name', 'chatwoot_team_id')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();
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
