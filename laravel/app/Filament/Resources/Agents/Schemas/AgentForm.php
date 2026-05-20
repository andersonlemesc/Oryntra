<?php

declare(strict_types=1);

namespace App\Filament\Resources\Agents\Schemas;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use App\Enums\AgentMode;
use App\Enums\AgentResponseMode;
use App\Enums\AgentStatus;
use App\Models\AgentLlmKey;
use Filament\Facades\Filament;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AgentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('config')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Geral')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Section::make('Identidade')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Nome')
                                            ->required()
                                            ->maxLength(255),
                                        Select::make('status')
                                            ->label('Status')
                                            ->options(self::statusOptions())
                                            ->default(AgentStatus::Inactive->value)
                                            ->required(),
                                        Select::make('mode')
                                            ->label('Modo')
                                            ->options(self::modeOptions())
                                            ->default(AgentMode::Single->value)
                                            ->live()
                                            ->helperText('Use Supervisor para rotear conversas entre especialistas configurados dentro deste agente.')
                                            ->required(),
                                        Textarea::make('description')
                                            ->label('Descricao')
                                            ->columnSpanFull()
                                            ->rows(2),
                                        TextInput::make('locale')
                                            ->label('Idioma')
                                            ->default('en')
                                            ->required()
                                            ->maxLength(16),
                                        TextInput::make('timezone')
                                            ->label('Timezone')
                                            ->default('UTC')
                                            ->required()
                                            ->maxLength(64),
                                        Select::make('response_mode')
                                            ->label('Modo de resposta')
                                            ->options(self::responseModeOptions())
                                            ->default(AgentResponseMode::Automatic->value)
                                            ->required(),
                                    ]),

                                Section::make('Prompts')
                                    ->schema([
                                        Textarea::make('system_prompt')
                                            ->label('System prompt')
                                            ->rows(6)
                                            ->columnSpanFull(),
                                        Textarea::make('behavior_prompt')
                                            ->label('Prompt de comportamento')
                                            ->rows(4)
                                            ->columnSpanFull(),
                                        Textarea::make('fallback_message')
                                            ->label('Mensagem de fallback')
                                            ->rows(2)
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Modelo')
                            ->icon('heroicon-o-cpu-chip')
                            ->schema([
                                Section::make('Supervisor')
                                    ->description('Configura o roteador que escolhe qual especialista atende cada conversa.')
                                    ->columns(2)
                                    ->visible(fn (Get $get): bool => $get('mode') === AgentMode::Supervisor->value)
                                    ->schema([
                                        Select::make('supervisor_llm_key_id')
                                            ->label('Chave LLM do supervisor')
                                            ->options(fn (): array => self::llmKeyOptions(null))
                                            ->searchable()
                                            ->required(fn (Get $get): bool => $get('mode') === AgentMode::Supervisor->value),
                                        TextInput::make('supervisor_llm_model')
                                            ->label('Modelo do supervisor')
                                            ->required(fn (Get $get): bool => $get('mode') === AgentMode::Supervisor->value)
                                            ->maxLength(128)
                                            ->helperText('Use um modelo barato/rapido para classificacao.'),
                                        Textarea::make('supervisor_prompt')
                                            ->label('Prompt do supervisor')
                                            ->required(fn (Get $get): bool => $get('mode') === AgentMode::Supervisor->value)
                                            ->rows(4)
                                            ->columnSpanFull()
                                            ->helperText('Instrua como escolher entre os especialistas. As respostas finais ficam com os especialistas.'),
                                    ]),

                                Section::make('LLM do agente unico')
                                    ->description('Usado quando este agente responde diretamente sem especialistas.')
                                    ->columns(2)
                                    ->visible(fn (Get $get): bool => $get('mode') !== AgentMode::Supervisor->value)
                                    ->schema([
                                        Select::make('llm_provider')
                                            ->label('Provider')
                                            ->options(self::llmProviderOptions())
                                            ->live()
                                            ->nullable(),
                                        Select::make('llm_key_id')
                                            ->label('Chave LLM')
                                            ->options(fn (callable $get): array => self::llmKeyOptions($get('llm_provider')))
                                            ->searchable()
                                            ->nullable()
                                            ->helperText('Nao tem chave? Cadastre em Agentes -> Chaves LLM.'),
                                        TextInput::make('llm_model')
                                            ->label('Modelo')
                                            ->maxLength(128),
                                        TextInput::make('llm_temperature')
                                            ->label('Temperature')
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(2)
                                            ->step(0.01),
                                        TextInput::make('llm_max_tokens')
                                            ->label('Max tokens')
                                            ->numeric()
                                            ->minValue(1),
                                    ]),
                            ]),

                        Tab::make('Comportamento')
                            ->icon('heroicon-o-shield-check')
                            ->schema([
                                Section::make('Guards')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('guard_config.block_sensitive_data')
                                            ->label('Bloquear dados sensiveis')
                                            ->default(true),
                                        Toggle::make('guard_config.block_prompt_injection')
                                            ->label('Bloquear prompt injection')
                                            ->default(true),
                                        Toggle::make('guard_config.require_rag_for_answers')
                                            ->label('Requer RAG'),
                                        Toggle::make('guard_config.handoff_on_low_confidence')
                                            ->label('Handoff baixa confianca')
                                            ->default(true),
                                        TextInput::make('guard_config.low_confidence_threshold')
                                            ->label('Threshold baixa confianca')
                                            ->numeric()
                                            ->step(0.01)
                                            ->default(0.4),
                                    ]),

                                Section::make('RAG')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('rag_config.enabled')
                                            ->label('Habilitado'),
                                        TextInput::make('rag_config.top_k')
                                            ->label('Top K')
                                            ->numeric()
                                            ->default(5),
                                        TextInput::make('rag_config.min_score')
                                            ->label('Score minimo')
                                            ->numeric()
                                            ->step(0.01)
                                            ->default(0.7),
                                        Toggle::make('rag_config.answer_only_with_context')
                                            ->label('Responder so com contexto'),
                                    ]),

                                Section::make('Politica de midia')
                                    ->schema([
                                        KeyValue::make('media_policy')
                                            ->label('Configuracao')
                                            ->keyLabel('Chave')
                                            ->valueLabel('Valor')
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Execucao')
                            ->icon('heroicon-o-bolt')
                            ->schema([
                                Section::make('Debounce')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('debounce_config.enabled')
                                            ->label('Habilitado')
                                            ->default(true),
                                        TextInput::make('debounce_config.window_seconds')
                                            ->label('Janela (s)')
                                            ->numeric()
                                            ->default(8),
                                        TextInput::make('debounce_config.max_wait_seconds')
                                            ->label('Espera maxima (s)')
                                            ->numeric()
                                            ->default(20),
                                        TextInput::make('debounce_config.max_messages')
                                            ->label('Mensagens maximas')
                                            ->numeric()
                                            ->default(10),
                                    ]),

                                Section::make('Runtime')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('runtime_config.graph')
                                            ->label('Graph')
                                            ->default('default_support_agent'),
                                        Toggle::make('runtime_config.streaming')
                                            ->label('Streaming'),
                                        Toggle::make('runtime_config.checkpointing')
                                            ->label('Checkpointing')
                                            ->default(true),
                                        Toggle::make('runtime_config.long_term_memory')
                                            ->label('Memoria longo prazo'),
                                        Toggle::make('runtime_config.human_in_the_loop')
                                            ->label('Human in the loop'),
                                        TextInput::make('runtime_config.tool_call_limit')
                                            ->label('Limite tool calls')
                                            ->numeric()
                                            ->default(8),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(AgentStatus::cases())
            ->mapWithKeys(fn (AgentStatus $s): array => [$s->value => $s->label()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function modeOptions(): array
    {
        return collect(AgentMode::cases())
            ->mapWithKeys(fn (AgentMode $m): array => [$m->value => $m->label()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function responseModeOptions(): array
    {
        return collect(AgentResponseMode::cases())
            ->mapWithKeys(fn (AgentResponseMode $m): array => [$m->value => $m->label()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function llmProviderOptions(): array
    {
        return collect(AgentLlmProvider::cases())
            ->mapWithKeys(fn (AgentLlmProvider $p): array => [$p->value => $p->label()])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function llmKeyOptions(?string $provider): array
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return [];
        }

        $query = AgentLlmKey::query()
            ->where('workspace_id', $tenant->getKey())
            ->where('status', AgentLlmKeyStatus::Active->value)
            ->orderBy('name');

        if (filled($provider)) {
            $query->where('provider', $provider);
        }

        return $query->pluck('name', 'id')->all();
    }
}
