<?php

declare(strict_types=1);

namespace App\Filament\Resources\Agents\RelationManagers;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use App\Enums\AgentMode;
use App\Enums\AgentSpecialistStatus;
use App\Enums\DocumentCategory;
use App\Enums\ExternalToolKind;
use App\Filament\Support\LlmModelField;
use App\Models\Agent;
use App\Models\AgentLlmKey;
use App\Models\AgentSpecialist;
use App\Models\ExternalTool;
use App\Models\GoogleCalendarConnection;
use App\Services\AgentTools\NativeTool;
use App\Services\AgentTools\NativeToolRegistry;
use App\Services\GoogleCalendar\GoogleCalendarClient;
use App\Services\GoogleCalendar\GoogleCalendarConfig;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class SpecialistsRelationManager extends RelationManager
{
    protected static string $relationship = 'specialists';

    protected static ?string $title = 'Especialistas';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return $ownerRecord instanceof Agent && $ownerRecord->mode === AgentMode::Single
            ? 'Configuracao'
            : 'Especialistas';
    }

    /**
     * Single-mode agents carry exactly one specialist and never route, so the
     * routing fields and the add/delete affordances are hidden.
     */
    private function isSingleMode(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Agent && $owner->mode === AgentMode::Single;
    }

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
                                            ->splitKeys([',', 'Enter'])
                                            ->required(fn (): bool => ! $this->isSingleMode())
                                            ->visible(fn (): bool => ! $this->isSingleMode())
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
                                            ->live()
                                            ->required(),
                                        ...LlmModelField::components(
                                            'llm_model',
                                            'llm_key_id',
                                            required: true,
                                            label: 'Modelo',
                                        ),
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
                                            ->splitKeys([',', 'Enter'])
                                            ->suggestions(fn (): array => app(NativeToolRegistry::class)->options())
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Lista das ferramentas que este especialista pode chamar (enviar mensagem, transferir para humano, atribuir time, etc.). Sem isso, o especialista so consegue responder em texto.'),
                                        TextInput::make('priority')
                                            ->label('Prioridade')
                                            ->numeric()
                                            ->default(100)
                                            ->required(fn (): bool => ! $this->isSingleMode())
                                            ->visible(fn (): bool => ! $this->isSingleMode())
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Em caso de empate de intencao, o especialista com menor numero atende primeiro. Use 100 como padrao e ajuste somente se precisar forcar ordem.'),
                                        TextInput::make('confidence_threshold')
                                            ->label('Threshold confianca')
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(1)
                                            ->step(0.01)
                                            ->default(0.6)
                                            ->required(fn (): bool => ! $this->isSingleMode())
                                            ->visible(fn (): bool => ! $this->isSingleMode())
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
                                        Select::make('handoff_config.label_name')
                                            ->label('Label Chatwoot')
                                            ->options(fn (): array => self::chatwootLabelOptions())
                                            ->searchable()
                                            ->visible(fn (Get $get): bool => (bool) $get('handoff_config.enabled'))
                                            ->placeholder('Herda do bot quando vazio')
                                            ->helperText('Lista das labels do Chatwoot (sincronizadas via job). Sobrescreve a label do bot. Crie novas em Chatwoot > Settings > Labels.'),
                                        Textarea::make('handoff_config.private_note_template')
                                            ->label('Template da nota privada')
                                            ->rows(3)
                                            ->visible(fn (Get $get): bool => (bool) $get('handoff_config.enabled'))
                                            ->placeholder('Herda do bot quando vazio')
                                            ->helperText('Opcional. Placeholders: {reason}, {priority}, {customer_message}, {agent_name}, {conversation_summary}, {key_fact}, {recent_messages}, {conversation_id}, {specialist_id}.')
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

                        Tab::make('Produtos')
                            ->icon('heroicon-o-shopping-bag')
                            ->schema([
                                Section::make('Catalogo')
                                    ->description('Permite a IA consultar produtos ativos cadastrados neste workspace.')
                                    ->schema([
                                        Toggle::make('product_tools_config.query_enabled')
                                            ->label('Permitir consultar produtos')
                                            ->default(false)
                                            ->helperText('Quando habilitado, a tool query_products sera adicionada automaticamente ao especialista.'),
                                    ]),
                            ]),

                        Tab::make('Documentos')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Section::make('Biblioteca de documentos')
                                    ->description('Permite a IA buscar e enviar documentos (PDFs, imagens) ao cliente via Chatwoot. Documentos de produto sao descobertos junto da consulta de produtos.')
                                    ->schema([
                                        Toggle::make('document_tools_config.query_enabled')
                                            ->label('Permitir consultar documentos avulsos')
                                            ->live()
                                            ->default(false)
                                            ->helperText('Quando habilitado, a tool query_documents sera adicionada automaticamente ao especialista.'),
                                        Toggle::make('document_tools_config.send_enabled')
                                            ->label('Permitir enviar documentos')
                                            ->live()
                                            ->default(false)
                                            ->helperText('Quando habilitado, a tool send_document sera adicionada automaticamente ao especialista.'),
                                        CheckboxList::make('document_tools_config.allowed_categories')
                                            ->label('Categorias que a IA pode enviar')
                                            ->options(DocumentCategory::sendableOptions())
                                            ->columns(2)
                                            ->visible(fn (Get $get): bool => (bool) $get('document_tools_config.send_enabled'))
                                            ->helperText('Restringe quais categorias de documentos avulsos a IA pode enviar. Vazio = todas as categorias enviaveis. "Conhecimento IA" nunca e enviado.'),
                                    ]),
                            ]),

                        Tab::make('APIs externas')
                            ->icon('heroicon-o-globe-alt')
                            ->schema([
                                Section::make('Connectors HTTP')
                                    ->description('Habilita as APIs externas (connectors) que este especialista pode chamar. Cadastre os connectors em "APIs externas" no menu lateral.')
                                    ->schema([
                                        Select::make('external_tool_slugs')
                                            ->label('Connectors habilitados')
                                            ->multiple()
                                            ->options(fn (): array => self::externalToolOptions())
                                            ->afterStateHydrated(function (Select $component, ?AgentSpecialist $record): void {
                                                if ($record === null) {
                                                    return;
                                                }

                                                $allowlist = is_array($record->tools_allowlist) ? $record->tools_allowlist : [];
                                                $component->state(array_values(array_intersect($allowlist, array_keys(self::externalToolOptions()))));
                                            })
                                            ->helperText('A IA so pode chamar connectors marcados aqui. A descricao cadastrada no connector orienta quando usa-lo.'),
                                    ]),
                            ]),

                        Tab::make('Servidores MCP')
                            ->icon('heroicon-o-server-stack')
                            ->schema([
                                Section::make('Servidores MCP habilitados')
                                    ->description('Habilita os servidores MCP que este especialista pode usar. Cada servidor expoe suas tools automaticamente a cada execucao via tools/list. Cadastre os servidores em "Servidores MCP" no menu lateral.')
                                    ->schema([
                                        Select::make('mcp_server_slugs')
                                            ->label('Servidores habilitados')
                                            ->multiple()
                                            ->options(fn (): array => self::mcpServerOptions())
                                            ->afterStateHydrated(function (Select $component, ?AgentSpecialist $record): void {
                                                if ($record === null) {
                                                    return;
                                                }

                                                $allowlist = is_array($record->tools_allowlist) ? $record->tools_allowlist : [];
                                                $component->state(array_values(array_intersect($allowlist, array_keys(self::mcpServerOptions()))));
                                            })
                                            ->helperText('A IA recebe todas as tools expostas pelo servidor. O slug do servidor vai para o allowlist.'),
                                    ]),
                            ]),

                        Tab::make('Google Calendar')
                            ->icon('heroicon-o-calendar-days')
                            ->schema([
                                Section::make('Acesso à agenda')
                                    ->description('Permite o especialista listar, criar, editar, deletar eventos e buscar horários livres no Google Calendar. Cadastre conexões em "Integrações > Google Calendar" no menu lateral.')
                                    ->schema([
                                        Toggle::make('google_calendar_config.enabled')
                                            ->label('Habilitar Google Calendar')
                                            ->live()
                                            ->default(false)
                                            ->helperText('Adiciona as 5 tools gcal_* ao especialista.'),
                                        Select::make('google_calendar_config.connection_id')
                                            ->label('Conexão')
                                            ->options(fn (): array => self::googleCalendarConnectionOptions())
                                            ->visible(fn (Get $get): bool => (bool) $get('google_calendar_config.enabled'))
                                            ->required(fn (Get $get): bool => (bool) $get('google_calendar_config.enabled'))
                                            ->live()
                                            ->helperText('Apenas conexões ativas do workspace atual.'),
                                        Select::make('google_calendar_config.calendar_id')
                                            ->label('Calendário')
                                            ->options(fn (Get $get): array => self::googleCalendarCalendarOptions($get('google_calendar_config.connection_id')))
                                            ->visible(fn (Get $get): bool => (bool) $get('google_calendar_config.enabled')
                                                && filled($get('google_calendar_config.connection_id')))
                                            ->required(fn (Get $get): bool => (bool) $get('google_calendar_config.enabled')
                                                && filled($get('google_calendar_config.connection_id')))
                                            ->searchable()
                                            ->helperText('Qual calendário da conexão escolhida. Carregado em tempo real do Google.'),
                                        Toggle::make('google_calendar_config.notify_attendees_default')
                                            ->label('Notificar convidados por padrão')
                                            ->default(true)
                                            ->visible(fn (Get $get): bool => (bool) $get('google_calendar_config.enabled'))
                                            ->helperText('Default p/ sendUpdates ao criar/editar/deletar eventos. O agente pode sobrescrever por chamada.'),
                                        Toggle::make('google_calendar_config.allow_conflicts')
                                            ->label('Permitir sobreposição de eventos')
                                            ->default(false)
                                            ->visible(fn (Get $get): bool => (bool) $get('google_calendar_config.enabled'))
                                            ->helperText('Quando desligado (padrão), a IA é bloqueada de criar eventos em cima de compromissos existentes. Ligue só para agendas que aceitam paralelismo (plantão, open house).'),
                                    ]),
                            ]),

                        Tab::make('Encerramento')
                            ->icon('heroicon-o-check-circle')
                            ->schema([
                                Section::make('Configuracao')
                                    ->description('Permite o especialista encerrar a conversa marcando como resolvida no Chatwoot quando a duvida do cliente foi solucionada.')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('resolution_config.enabled')
                                            ->label('Permitir encerrar conversa')
                                            ->live()
                                            ->default(false)
                                            ->helperText('Quando habilitado, a tool resolve_conversation sera adicionada automaticamente ao especialista.'),
                                        Select::make('resolution_config.label_name')
                                            ->label('Label Chatwoot')
                                            ->options(fn (): array => self::chatwootLabelOptions())
                                            ->searchable()
                                            ->visible(fn (Get $get): bool => (bool) $get('resolution_config.enabled'))
                                            ->placeholder('Sem label')
                                            ->helperText('Lista das labels do Chatwoot (sincronizadas via job). Aplicada antes do toggle_status=resolved. Crie novas em Chatwoot > Settings > Labels.'),
                                        Textarea::make('resolution_config.customer_message')
                                            ->label('Mensagem de despedida')
                                            ->rows(2)
                                            ->visible(fn (Get $get): bool => (bool) $get('resolution_config.enabled'))
                                            ->helperText('Opcional. Enviada antes do encerramento. Pode ser sobrescrita por regra ou pelo LLM.')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('Situacoes de encerramento')
                                    ->description('Cada regra dispara um encerramento automatico quando o cliente menciona palavras-chave especificas.')
                                    ->schema([
                                        Repeater::make('resolution_config.rules')
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
                                                    ->required()
                                                    ->helperText('Ex: obrigado, resolveu, era so isso, ja entendi.'),
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
                                                Select::make('label_name')
                                                    ->label('Label especifica')
                                                    ->options(fn (): array => self::chatwootLabelOptions())
                                                    ->searchable()
                                                    ->placeholder('Usa label geral')
                                                    ->helperText('Opcional. Sobrescreve a label geral so para esta situacao.'),
                                            ])
                                            ->columns(2)
                                            ->defaultItems(0)
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Memoria longo prazo')
                            ->icon('heroicon-o-bookmark')
                            ->schema([
                                Section::make('Extracao automatica')
                                    ->description('Apos cada turno completo, dispara um job que pede ao LLM novos fatos sobre o cliente.')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('memory_config.extraction_enabled')
                                            ->label('Extrair memoria via LLM')
                                            ->live()
                                            ->default(false)
                                            ->helperText('Custo de tokens adicional por turno. Use so quando o ganho de contexto valer.'),
                                        CheckboxList::make('memory_config.extraction_types')
                                            ->label('Tipos extraidos')
                                            ->options([
                                                'preference' => 'Preferencias',
                                                'fact' => 'Fatos',
                                                'constraint' => 'Restricoes',
                                                'history' => 'Historico',
                                                'custom' => 'Personalizados',
                                            ])
                                            ->default(['preference', 'fact', 'constraint'])
                                            ->visible(fn (Get $get): bool => (bool) $get('memory_config.extraction_enabled'))
                                            ->columns(2),
                                    ]),
                                Section::make('Injecao no prompt')
                                    ->description('Antes de cada resposta, as memorias mais recentes do contato sao adicionadas ao system message do especialista.')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('memory_config.injection_enabled')
                                            ->label('Incluir memorias no prompt')
                                            ->live()
                                            ->default(false)
                                            ->helperText('Quando ativo, o especialista enxerga o que o contato ja disse em conversas anteriores.'),
                                        TextInput::make('memory_config.injection_limit')
                                            ->label('Limite de memorias')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(200)
                                            ->placeholder('Vazio = todas')
                                            ->visible(fn (Get $get): bool => (bool) $get('memory_config.injection_enabled'))
                                            ->helperText('Top N por data de criacao desc. Vazio = sem limite.'),
                                    ]),
                                Section::make('Tool calling')
                                    ->description('Quantas iteracoes de tool calling o especialista pode executar num mesmo turno (chamar tool, ler resultado, decidir proximo passo).')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('memory_config.max_tool_iterations')
                                            ->label('Maximo de iteracoes')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(20)
                                            ->default(4)
                                            ->helperText('Padrao 4. Cada iteracao = 1 chamada extra ao LLM. Subir so se o especialista precisa encadear muitas tools.'),
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
                    ->visible(fn (): bool => ! $this->isSingleMode() || ! $this->getOwnerRecord()->specialists()->exists())
                    ->mutateFormDataUsing(function (array $data): array {
                        $tenant = Filament::getTenant();
                        $data['workspace_id'] = $tenant?->getKey();

                        return self::normalizeSpecialistFormData($data);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => self::normalizeSpecialistFormData($data)),
                DeleteAction::make()
                    ->visible(fn (): bool => ! $this->isSingleMode()),
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
            ->get()
            ->mapWithKeys(function (AgentLlmKey $key): array {
                $provider = $key->provider instanceof AgentLlmProvider
                    ? $key->provider->label()
                    : (string) $key->provider;

                return [$key->getKey() => "{$key->name} ({$provider})"];
            })
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
     * Enabled connectors of the current workspace, keyed by slug.
     *
     * @return array<string, string>
     */
    private static function externalToolOptions(): array
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return [];
        }

        return ExternalTool::query()
            ->where('workspace_id', $tenant->getKey())
            ->where('kind', ExternalToolKind::HttpConnector)
            ->enabled()
            ->orderBy('label')
            ->pluck('label', 'slug')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function externalToolSlugs(): array
    {
        return array_keys(self::externalToolOptions());
    }

    /**
     * Enabled MCP servers of the current workspace, keyed by slug.
     *
     * @return array<string, string>
     */
    private static function mcpServerOptions(): array
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return [];
        }

        return ExternalTool::query()
            ->where('workspace_id', $tenant->getKey())
            ->where('kind', ExternalToolKind::Mcp)
            ->enabled()
            ->orderBy('label')
            ->pluck('label', 'slug')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function mcpServerSlugs(): array
    {
        return array_keys(self::mcpServerOptions());
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
        $data = LlmModelField::resolve($data, 'llm_model');

        // Routing fields are hidden in single mode — backfill safe defaults so
        // the NOT NULL columns are always populated.
        $data['intent_keywords'] = is_array($data['intent_keywords'] ?? null) ? $data['intent_keywords'] : [];
        $data['priority'] = is_numeric($data['priority'] ?? null) ? (int) $data['priority'] : 100;
        $data['confidence_threshold'] = is_numeric($data['confidence_threshold'] ?? null)
            ? (float) $data['confidence_threshold']
            : 0.0;

        $handoffConfig = is_array($data['handoff_config'] ?? null)
            ? $data['handoff_config']
            : [];
        $contactConfig = is_array($data['contact_tools_config'] ?? null)
            ? $data['contact_tools_config']
            : [];
        $productConfig = is_array($data['product_tools_config'] ?? null)
            ? $data['product_tools_config']
            : [];
        $documentConfig = is_array($data['document_tools_config'] ?? null)
            ? $data['document_tools_config']
            : [];
        $memoryConfig = is_array($data['memory_config'] ?? null)
            ? $data['memory_config']
            : [];
        $resolutionConfig = is_array($data['resolution_config'] ?? null)
            ? $data['resolution_config']
            : [];

        $humanEnabled = (bool) ($handoffConfig['enabled'] ?? false);
        $teamEnabled = (bool) ($handoffConfig['team_enabled'] ?? false);
        $contactUpdateEnabled = (bool) ($contactConfig['update_enabled'] ?? false);
        $memoryExtractionEnabled = (bool) ($memoryConfig['extraction_enabled'] ?? false);
        $memoryInjectionEnabled = (bool) ($memoryConfig['injection_enabled'] ?? false);
        $resolutionEnabled = (bool) ($resolutionConfig['enabled'] ?? false);

        $toolsAllowlist = is_array($data['tools_allowlist'] ?? null)
            ? array_values($data['tools_allowlist'])
            : [];

        $productQueryEnabled = (bool) ($productConfig['query_enabled'] ?? in_array(NativeTool::QueryProducts->value, $toolsAllowlist, true));
        $documentQueryEnabled = (bool) ($documentConfig['query_enabled'] ?? in_array(NativeTool::QueryDocuments->value, $toolsAllowlist, true));
        $documentSendEnabled = (bool) ($documentConfig['send_enabled'] ?? in_array(NativeTool::SendDocument->value, $toolsAllowlist, true));

        $toolsAllowlist = self::reconcileTool($toolsAllowlist, NativeTool::RequestHumanHandoff->value, $humanEnabled);
        $toolsAllowlist = self::reconcileTool($toolsAllowlist, NativeTool::RequestTeamHandoff->value, $teamEnabled);
        $toolsAllowlist = self::reconcileTool($toolsAllowlist, NativeTool::ChatwootGetContact->value, $contactUpdateEnabled);
        $toolsAllowlist = self::reconcileTool($toolsAllowlist, NativeTool::ChatwootUpdateContact->value, $contactUpdateEnabled);
        $toolsAllowlist = self::reconcileTool($toolsAllowlist, NativeTool::QueryProducts->value, $productQueryEnabled);
        $toolsAllowlist = self::reconcileTool($toolsAllowlist, NativeTool::QueryDocuments->value, $documentQueryEnabled);
        $toolsAllowlist = self::reconcileTool($toolsAllowlist, NativeTool::SendDocument->value, $documentSendEnabled);
        $toolsAllowlist = self::reconcileTool($toolsAllowlist, NativeTool::ResolveConversation->value, $resolutionEnabled);

        $selectedConnectors = is_array($data['external_tool_slugs'] ?? null)
            ? $data['external_tool_slugs']
            : [];

        foreach (self::externalToolSlugs() as $slug) {
            $toolsAllowlist = self::reconcileTool($toolsAllowlist, $slug, in_array($slug, $selectedConnectors, true));
        }

        unset($data['external_tool_slugs']);

        $selectedMcpServers = is_array($data['mcp_server_slugs'] ?? null)
            ? $data['mcp_server_slugs']
            : [];

        foreach (self::mcpServerSlugs() as $slug) {
            $toolsAllowlist = self::reconcileTool($toolsAllowlist, $slug, in_array($slug, $selectedMcpServers, true));
        }

        unset($data['mcp_server_slugs']);

        $gcalConfig = is_array($data['google_calendar_config'] ?? null)
            ? $data['google_calendar_config']
            : [];
        $gcalEnabled = (bool) ($gcalConfig['enabled'] ?? false);
        $gcalConnectionId = $gcalEnabled && filled($gcalConfig['connection_id'] ?? null)
            ? (int) $gcalConfig['connection_id']
            : null;
        $gcalCalendarId = $gcalEnabled && filled($gcalConfig['calendar_id'] ?? null)
            ? (string) $gcalConfig['calendar_id']
            : null;
        $gcalReady = $gcalEnabled && $gcalConnectionId !== null && $gcalCalendarId !== null;

        foreach (self::googleCalendarToolSlugs() as $slug) {
            $toolsAllowlist = self::reconcileTool($toolsAllowlist, $slug, $gcalReady);
        }

        $gcalConfig['enabled'] = $gcalEnabled;
        $gcalConfig['connection_id'] = $gcalConnectionId;
        $gcalConfig['calendar_id'] = $gcalCalendarId;
        $gcalConfig['notify_attendees_default'] = (bool) ($gcalConfig['notify_attendees_default'] ?? true);
        $gcalConfig['allow_conflicts'] = (bool) ($gcalConfig['allow_conflicts'] ?? false);
        $data['google_calendar_config'] = $gcalConfig;

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
        $handoffConfig['label_name'] = $humanEnabled && filled($handoffConfig['label_name'] ?? null)
            ? trim((string) $handoffConfig['label_name'])
            : null;
        $handoffConfig['private_note_template'] = $humanEnabled && filled($handoffConfig['private_note_template'] ?? null)
            ? (string) $handoffConfig['private_note_template']
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

        $productConfig['query_enabled'] = $productQueryEnabled;

        $documentConfig['query_enabled'] = $documentQueryEnabled;
        $documentConfig['send_enabled'] = $documentSendEnabled;
        $documentConfig['allowed_categories'] = $documentSendEnabled && is_array($documentConfig['allowed_categories'] ?? null)
            ? array_values(array_filter(
                $documentConfig['allowed_categories'],
                fn (mixed $value): bool => is_string($value) && (DocumentCategory::tryFrom($value)?->isSendable() ?? false),
            ))
            : [];

        $memoryConfig['extraction_enabled'] = $memoryExtractionEnabled;
        $memoryConfig['injection_enabled'] = $memoryInjectionEnabled;
        $memoryConfig['extraction_types'] = $memoryExtractionEnabled && is_array($memoryConfig['extraction_types'] ?? null)
            ? array_values(array_filter(
                $memoryConfig['extraction_types'],
                fn (mixed $type): bool => in_array($type, ['preference', 'fact', 'constraint', 'history', 'custom'], true),
            ))
            : ($memoryExtractionEnabled ? ['preference', 'fact', 'constraint'] : []);

        if ($memoryInjectionEnabled && filled($memoryConfig['injection_limit'] ?? null)) {
            $memoryConfig['injection_limit'] = max(1, (int) $memoryConfig['injection_limit']);
        } else {
            $memoryConfig['injection_limit'] = null;
        }

        $memoryConfig['max_tool_iterations'] = isset($memoryConfig['max_tool_iterations']) && is_numeric($memoryConfig['max_tool_iterations'])
            ? max(1, min(20, (int) $memoryConfig['max_tool_iterations']))
            : 4;

        $resolutionConfig['enabled'] = $resolutionEnabled;
        $resolutionConfig['customer_message'] = $resolutionEnabled && filled($resolutionConfig['customer_message'] ?? null)
            ? (string) $resolutionConfig['customer_message']
            : null;
        $resolutionConfig['label_name'] = $resolutionEnabled && filled($resolutionConfig['label_name'] ?? null)
            ? trim((string) $resolutionConfig['label_name'])
            : null;
        $resolutionConfig['rules'] = is_array($resolutionConfig['rules'] ?? null)
            ? array_values($resolutionConfig['rules'])
            : [];
        $resolutionConfig['rules'] = array_map(
            fn (mixed $rule): array => self::normalizeResolutionRule($rule),
            $resolutionConfig['rules'],
        );

        $data['tools_allowlist'] = $toolsAllowlist;
        $data['handoff_config'] = $handoffConfig;
        $data['contact_tools_config'] = $contactConfig;
        $data['product_tools_config'] = $productConfig;
        $data['document_tools_config'] = $documentConfig;
        $data['memory_config'] = $memoryConfig;
        $data['resolution_config'] = $resolutionConfig;
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
     * @return list<string>
     */
    public static function googleCalendarToolSlugs(): array
    {
        return [
            NativeTool::GcalListEvents->value,
            NativeTool::GcalCreateEvent->value,
            NativeTool::GcalUpdateEvent->value,
            NativeTool::GcalDeleteEvent->value,
            NativeTool::GcalFindFreeSlots->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function googleCalendarConnectionOptions(): array
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return [];
        }

        return GoogleCalendarConnection::query()
            ->where('workspace_id', $tenant->getKey())
            ->where('is_active', true)
            ->orderBy('label')
            ->pluck('label', 'id')
            ->map(fn (mixed $label): string => (string) $label)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function googleCalendarCalendarOptions(mixed $connectionId): array
    {
        if (! is_numeric($connectionId)) {
            return [];
        }

        $tenant = Filament::getTenant();

        $connection = GoogleCalendarConnection::query()
            ->when($tenant !== null, fn ($q) => $q->where('workspace_id', $tenant->getKey()))
            ->find((int) $connectionId);

        if ($connection === null) {
            return [];
        }

        try {
            $client = new GoogleCalendarClient($connection, GoogleCalendarConfig::fromConfig());
            $calendars = $client->listCalendars();
        } catch (Throwable) {
            $fallback = $connection->default_calendar_id ?? 'primary';

            return [$fallback => $fallback . ' (não foi possível listar agora)'];
        }

        $options = [];
        foreach ($calendars as $calendar) {
            $options[$calendar['id']] = $calendar['summary'] . ($calendar['primary'] ? ' (primary)' : '');
        }

        return $options;
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
     * @return array<string, string>
     */
    private static function chatwootLabelOptions(): array
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return [];
        }

        return DB::table('chatwoot_labels')
            ->where('workspace_id', $tenant->getKey())
            ->orderBy('title')
            ->pluck('title', 'title')
            ->map(fn (mixed $title): string => (string) $title)
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

    /**
     * @return array<string, mixed>
     */
    private static function normalizeResolutionRule(mixed $rule): array
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

        $rule['customer_message'] = isset($rule['customer_message']) && is_string($rule['customer_message']) && trim($rule['customer_message']) !== ''
            ? (string) $rule['customer_message']
            : null;

        $rule['label_name'] = isset($rule['label_name']) && is_string($rule['label_name']) && trim($rule['label_name']) !== ''
            ? trim((string) $rule['label_name'])
            : null;

        return $rule;
    }
}
