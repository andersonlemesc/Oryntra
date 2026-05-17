# Glossário — Oryntra

## Domínio

### workspace
Tenant isolado da plataforma. Toda operação de negócio é escopada por `workspace_id`. Um cliente (agência ou empresa) pode ter um ou vários workspaces.

### organization
Agrupador de workspaces (revendedor, agência). UI mínima no MVP, infra preparada.

### agent
Configuração de agente LLM dentro de um workspace. Inclui: nome, model, prompt ativo, llm_key, regras (guards), settings (debounce window, temperature, etc).

### agent_run
Execução de um agente em uma conversa específica. Registra: tokens consumidos, custo estimado, status, duração, trace completo via `agent_logs`.

### thread_id
Identificador único de checkpoint do LangGraph. Formato: `workspace:{ws_id}:account:{account_id}:conversation:{conv_id}`. Garante que múltiplos workspaces em múltiplas contas Chatwoot não colidam.

### debounce
Janela de tempo (configurável por agente, default 8s) que agrupa mensagens picadas do cliente. Evita N chamadas LLM por uma frase quebrada em pedaços.

### guard
Validação executada antes ou depois do LLM. Exemplos: "conversa atribuída a humano → não responder", "fora horário comercial → fallback", "RAG com baixa confiança → pedir esclarecimento".

### HITL (Human-in-the-loop)
Pausa de execução do agente esperando aprovação humana via Filament. Caso típico: desconto acima do limite, transferência de conversa, ação destrutiva.

### BYOK (Bring Your Own Key)
Modelo de billing onde o workspace cadastra sua própria API key do provider LLM (OpenAI, Anthropic, Gemini). Oryntra não cobra LLM nem intermedia pagamento ao provider.

### RAG (Retrieval-Augmented Generation)
Padrão de injetar contexto recuperado (busca semântica em documentos) no prompt do LLM. No Oryntra: documentos são processados, divididos em chunks, embeddados, e buscados por similaridade no momento da resposta.

### embedding
Vetor numérico (1536d com `text-embedding-3-small`) que representa significado semântico de um trecho de texto. Armazenados em `document_chunks.embedding` (tipo `vector` do pgvector).

### chunk
Trecho de documento (~500-1000 tokens) gerado durante ingestão. Cada chunk vira uma linha em `document_chunks` com seu embedding.

### customer_memory
Memória persistente sobre um cliente Chatwoot. Dois tipos:
- **operacional** (JSON estruturado): nome, email, plano, preferências
- **resumo** (texto): histórico conversacional resumido pelo LLM

## Chatwoot

### Platform App Token
Token de super-admin Chatwoot. Gerado em "Super Admin Console → Platform Apps". Usado pela Oryntra pra sync de accounts/users via Platform API (`/platform/api/v1/...`). Configurado em `CHATWOOT_PLATFORM_TOKEN` na env.

### User Access Token
Token gerado em "Profile Settings" do user Chatwoot. Usado pra Application API (`/api/v1/accounts/{id}/...`). Ações executadas como aquele user. No Oryntra: cadastrado por workspace via `ChatwootConnectionResource`, criptografado.

### Contact Identifier
Identificador retornado ao criar contato via Client API. Usado pelo widget/cliente final. Não usado pelo Oryntra (atuamos do lado servidor).

### api_access_token (header)
Nome do header HTTP de autenticação tanto Application API quanto Platform API do Chatwoot.

### account (Chatwoot)
Inquilino do Chatwoot. Não confundir com `account_user` (relação user ↔ account) ou com nosso `workspace`. Um `chatwoot_connections.account_id` aponta pra account Chatwoot.

### inbox (Chatwoot)
Canal de atendimento (WhatsApp, Email, Webchat, etc.) dentro de uma account Chatwoot.

### conversation (Chatwoot)
Conversa entre cliente e atendentes/bot. Tem `uuid` (público) e `display_id` (sequencial por account).

## Tecnologias

### Filament
Framework de admin painel sobre Livewire. CRUD declarativo via "Resources".

### Fortify
Backend headless de auth do Laravel: 2FA, recovery codes, password confirmation, email verification, update password.

### Shield (Filament Shield)
Plugin Filament pra geração automática de policies/roles/permissions a partir de Resources.

### Horizon
Dashboard e supervisor de queues Laravel sobre Redis. Permite múltiplas filas com weights.

### Reverb
Servidor WebSocket nativo Laravel (substituto Pusher self-host).

### LangGraph
Framework Python pra orquestração de grafo de agentes LLM. Tem `State`, `Nodes`, `Edges`, `Checkpoints`, `Tools`, `HITL`.

### pgvector
Extensão Postgres pra armazenar e indexar vetores. Suporta busca por similaridade cosseno/L2 via índices HNSW/IVFFlat.

### MinIO
Storage S3-compatible self-host. Usado pra arquivos enviados (PDFs, audios, imagens).

### Mailpit
Captura local de e-mails em dev. UI em `:8025` mostra inbox de teste.

### Pail
CLI tail de logs Laravel em tempo real (`php artisan pail`).

### Telescope
Dashboard web de debug Laravel: requests, queries, jobs, mails, cache, log entries. Só dev.

### Spatie ActivityLog
Audit log automático em models via trait `LogsActivity`.

### Pest
Framework de teste PHP com DSL declarativo. Mesma engine do PHPUnit.

### uv
Gerenciador de pacotes Python rápido (substituto pip/poetry).

### ruff
Linter + formatter Python rápido (substituto black + isort + flake8).
