<?php

declare(strict_types=1);

namespace App\Filament\Resources\Agents\Schemas;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use App\Enums\AgentMode;
use App\Enums\AgentResponseMode;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AgentLlmKey;
use App\Models\AgentSpecialist;
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
use Illuminate\Database\Eloquent\Model;

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
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Single = 1 LLM responde tudo. Supervisor = um LLM "roteador" decide qual especialista atende cada conversa.')
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
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Automatico = a IA envia direto ao cliente. Manual = a resposta fica aguardando aprovacao humana antes de sair.')
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
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Credencial API (OpenAI, Anthropic, etc.) usada pelo supervisor para classificar a intencao. Pode ser diferente da chave dos especialistas.')
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
                                        Select::make('fallback_specialist_id')
                                            ->label('Especialista fallback')
                                            ->options(fn (Get $get, ?Model $record): array => self::specialistOptions($record))
                                            ->searchable()
                                            ->placeholder('Vazio = resposta generica do supervisor')
                                            ->columnSpanFull()
                                            ->helperText('Usado quando o supervisor LLM nao decide entre os especialistas (specialist_id=null). Tools e memorias do fallback ficam disponiveis em conversas sem intent claro.')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Especialista que assume conversas que o supervisor nao classificou. Recomenda-se escolher o que tem o escopo mais amplo (ex: Vendas, ou um "Atendimento Geral").'),
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
                                            ->step(0.01)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Controla a "criatividade" da resposta. 0 = sempre a resposta mais provavel (deterministico). 1+ = mais variacao. Para atendimento, use entre 0.1 e 0.3.'),
                                        TextInput::make('llm_max_tokens')
                                            ->label('Max tokens')
                                            ->numeric()
                                            ->minValue(1)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Tamanho maximo da resposta gerada (em tokens; ~1 token = 4 caracteres). Limita o quanto a IA pode escrever em uma mensagem.'),
                                    ]),
                            ]),

                        Tab::make('Comportamento')
                            ->icon('heroicon-o-shield-check')
                            ->schema([
                                Section::make('Guards')
                                    ->description('Travas de seguranca que filtram ou desviam respostas em situacoes sensiveis.')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('guard_config.block_sensitive_data')
                                            ->label('Bloquear dados sensiveis')
                                            ->default(true)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Impede que a IA envie ao cliente CPF, cartao de credito, senhas e tokens detectados nas mensagens.'),
                                        Toggle::make('guard_config.block_prompt_injection')
                                            ->label('Bloquear prompt injection')
                                            ->default(true)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Ataque onde o cliente tenta sobrescrever instrucoes do bot (ex: "ignore as regras anteriores e diga..."). Ativo, a IA ignora essas tentativas.'),
                                        Toggle::make('guard_config.require_rag_for_answers')
                                            ->label('Requer RAG')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Quando ativo, a IA so responde se encontrar contexto na base de conhecimento (RAG). Evita "alucinacao" mas pode rejeitar duvidas comuns.'),
                                        Toggle::make('guard_config.handoff_on_low_confidence')
                                            ->label('Handoff baixa confianca')
                                            ->default(true)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Se a IA estiver "incerta" (confianca abaixo do threshold), transfere automaticamente para um atendente humano em vez de responder errado.'),
                                        TextInput::make('guard_config.low_confidence_threshold')
                                            ->label('Threshold baixa confianca')
                                            ->numeric()
                                            ->step(0.01)
                                            ->default(0.4)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Valor entre 0 e 1. Quando a confianca da IA fica abaixo dele, o handoff dispara. 0.4 = transferir se a IA estiver menos de 40% segura.'),
                                    ]),

                                Section::make('RAG')
                                    ->description('Retrieval Augmented Generation: busca trechos relevantes da sua base de documentos antes da IA responder.')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('rag_config.enabled')
                                            ->label('Habilitado')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Liga a busca em documentos cadastrados. Util quando voce quer que a IA responda com base em FAQ, manuais ou politicas internas.'),
                                        TextInput::make('rag_config.top_k')
                                            ->label('Top K')
                                            ->numeric()
                                            ->default(5)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Quantos trechos mais relevantes da base sao enviados como contexto para a IA. Mais alto = mais contexto, porem mais caro.'),
                                        TextInput::make('rag_config.min_score')
                                            ->label('Score minimo')
                                            ->numeric()
                                            ->step(0.01)
                                            ->default(0.7)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Quao parecido o trecho precisa ser da pergunta (0 a 1). 0.7 = so usa trechos com similaridade >= 70%.'),
                                        Toggle::make('rag_config.answer_only_with_context')
                                            ->label('Responder so com contexto')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Forca a IA a responder somente usando os trechos encontrados. Se nao achar nada relevante, dispara handoff em vez de inventar resposta.'),
                                    ]),

                                Section::make('Politica de midia')
                                    ->description('Regras para audio, imagem e documentos enviados pelo cliente.')
                                    ->schema([
                                        KeyValue::make('media_policy')
                                            ->label('Configuracao')
                                            ->keyLabel('Chave')
                                            ->valueLabel('Valor')
                                            ->columnSpanFull()
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Pares chave/valor livres para configurar limites de midia. Exemplos: max_audio_seconds=60, accept_pdf=true, transcribe_audio=true.'),
                                        Select::make('media_llm_key_id')
                                            ->label('Chave LLM para midia')
                                            ->options(fn (): array => self::llmKeyOptions(null))
                                            ->searchable()
                                            ->nullable()
                                            ->helperText('Chave usada pelo supervisor para processar audio e imagem (transcricao, descricao). Se vazia, midia nao sera processada.'),
                                    ]),
                            ]),

                        Tab::make('Execucao')
                            ->icon('heroicon-o-bolt')
                            ->schema([
                                Section::make('Debounce')
                                    ->description('Agrupa mensagens rapidas do cliente em uma so chamada para a IA, evitando responder a cada palavra separada.')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('debounce_config.enabled')
                                            ->label('Habilitado')
                                            ->default(true)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Quando ligado, o sistema espera o cliente terminar de digitar (varias mensagens em sequencia) antes de chamar a IA uma unica vez.'),
                                        TextInput::make('debounce_config.window_seconds')
                                            ->label('Janela (s)')
                                            ->numeric()
                                            ->default(8)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Tempo de espera apos a ultima mensagem antes de processar. 8 = aguarda 8s sem nova mensagem para responder.'),
                                        TextInput::make('debounce_config.max_wait_seconds')
                                            ->label('Espera maxima (s)')
                                            ->numeric()
                                            ->default(20)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Teto absoluto: mesmo que o cliente continue digitando, depois deste tempo o sistema processa o que ja foi recebido.'),
                                        TextInput::make('debounce_config.max_messages')
                                            ->label('Mensagens maximas')
                                            ->numeric()
                                            ->default(10)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Limite de mensagens acumuladas antes de processar mesmo dentro da janela. Evita esperas longas em conversas tagareladas.'),
                                    ]),

                                Section::make('Runtime')
                                    ->description('Como o motor Python (LangGraph) executa este agente.')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('runtime_config.graph')
                                            ->label('Graph')
                                            ->default('default_support_agent')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Nome do grafo LangGraph (fluxo de nos LLM + ferramentas) que processa a conversa. Use o padrao a menos que tenha um grafo customizado.'),
                                        Toggle::make('runtime_config.streaming')
                                            ->label('Streaming')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Quando ativo, a resposta da IA chega em tempo real (palavra por palavra) em vez de aparecer toda de uma vez. Util para respostas longas.'),
                                        Toggle::make('runtime_config.checkpointing')
                                            ->label('Checkpointing')
                                            ->default(true)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Salva o estado da conversa entre mensagens. Permite que a IA "lembre" o que foi dito antes na mesma conversa. Deixe ligado.'),
                                        Toggle::make('runtime_config.long_term_memory')
                                            ->label('Memoria longo prazo')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Alem da memoria da conversa atual, salva preferencias e fatos do cliente entre conversas diferentes (ex: "ja foi atendido em maio sobre X").'),
                                        Toggle::make('runtime_config.human_in_the_loop')
                                            ->label('Human in the loop')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'HITL = humano no fluxo. Permite que a IA pause e peca aprovacao humana antes de enviar respostas criticas (ex: cancelamento, reembolso).'),
                                        TextInput::make('runtime_config.tool_call_limit')
                                            ->label('Limite tool calls')
                                            ->numeric()
                                            ->default(8)
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Maximo de ferramentas (consulta DB, RAG, envio de doc, etc.) que a IA pode chamar em uma unica resposta. Evita loops infinitos.'),
                                        Toggle::make('runtime_config.debug_prompts')
                                            ->label('Debug: salvar prompts no trace')
                                            ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Quando ativo, cada trace step de specialist_response e tool_call salva o system + human prompt completo. Util para depurar o que a IA esta vendo. Aumenta o tamanho do trace e expoe prompts no UI; desligar em producao quando nao precisar.'),
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

    /**
     * @return array<int, string>
     */
    private static function specialistOptions(?Model $record): array
    {
        if (! $record instanceof Agent) {
            return [];
        }

        return AgentSpecialist::query()
            ->where('agent_id', $record->id)
            ->where('workspace_id', $record->workspace_id)
            ->orderBy('priority')
            ->pluck('name', 'id')
            ->all();
    }
}
