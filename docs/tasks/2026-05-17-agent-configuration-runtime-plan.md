# Plano de execucao - Agent configuration + runtime dispatch

Data: 2026-05-17
Branch base sugerida: `feature/agent-configuration-runtime`

## Contexto atual

Oryntra ja possui:

- Auth Fortify + painel Filament.
- Tenancy por `workspace_id`.
- Sync Chatwoot Platform criando workspaces, usuarios e membros.
- Convites para usuarios sincronizados definirem senha.
- `ChatwootConnection` por workspace.
- Criacao automatica de Agent Bot no Chatwoot via Platform API.
- Token do Agent Bot salvo criptografado em `chatwoot_connections.api_access_token`.
- Webhook receiver publico por `connection_uuid`.
- Middleware de webhook resolvendo conexao e validando assinatura quando houver `webhook_secret`.
- Persistencia de payload bruto em `chatwoot_webhook_events`.
- Idempotencia por `chatwoot_message_id`.
- Lock por `conversation_id`.
- Classificacao inicial:
  - `outgoing` -> `ignored`
  - mensagens privadas -> `ignored`
  - `incoming` de contato com texto/midia -> `processed` por enquanto.

Decisoes arquiteturais ja estabelecidas:

- Laravel = produto, painel, auth, tenancy, webhooks, jobs, configuracao e envio ao Chatwoot.
- Python/FastAPI + LangGraph = runtime privado de agente, RAG, midia, embeddings, memoria, checkpoints.
- Python nunca e publico; Laravel chama `http://agent-python:8000` via rede interna.
- Toda chamada Laravel -> Python exige `X-Internal-Token`.
- `thread_id` LangGraph:

```text
workspace:{workspace_id}:account:{account_id}:conversation:{conversation_id}
```

## Objetivo da proxima fase

Criar a base de configuracao de agentes no Laravel antes de acoplar execucao real no Python.

A fase deve permitir:

- Criar agentes por workspace via Filament.
- Configurar comportamento essencial do agente.
- Vincular agente a uma `ChatwootConnection`.
- Fazer webhook processavel localizar agente ativo.
- Ignorar webhook quando nao houver agente ativo.
- Preparar debounce por conversa.
- Definir contrato Laravel -> Python, mas sem precisar implementar LangGraph completo ainda.

## Fora de escopo desta fase

- RAG completo.
- Upload/processamento de documentos.
- LangGraph robusto final.
- Transcricao real de audio.
- Vision real para imagem.
- Resposta automatica ao Chatwoot em producao.
- HITL completo.
- Billing/custos completos.

Esses pontos devem ser preparados no schema/config, mas nao precisam estar funcionais.

## Modelagem inicial recomendada

### `agents`

Tenant-scoped por `workspace_id`.

Campos sugeridos:

- `id`
- `workspace_id` FK cascade
- `name` text
- `description` text nullable
- `status` text default `inactive`
  - `active`
  - `inactive`
- `locale` text default `pt_BR`
- `timezone` text default workspace/app timezone
- `response_mode` text default `automatic`
  - `automatic`
  - `suggestion_only`
  - `human_approval`
- `llm_provider` text nullable
  - `openai`
  - `anthropic`
  - `gemini`
  - `local`
- `llm_model` text nullable
- `llm_temperature` numeric nullable
- `llm_max_tokens` integer nullable
- `system_prompt` text nullable
- `behavior_prompt` text nullable
- `fallback_message` text nullable
- `debounce_config` jsonb not null default object
- `media_policy` jsonb not null default object
- `guard_config` jsonb not null default object
- `rag_config` jsonb not null default object
- `runtime_config` jsonb not null default object
- timestamps

Indices/constraints:

- FK `workspace_id`
- index `workspace_id, status`
- unique `workspace_id, name`
- check `status IN ('active', 'inactive')`
- check `response_mode IN ('automatic', 'suggestion_only', 'human_approval')`

Observacao:

- Usar `jsonb` agora para politicas ainda instaveis.
- Normalizar depois o que virar consulta frequente.

### `agent_chatwoot_bindings`

Vincula agente a conexao Chatwoot.

Campos sugeridos:

- `id`
- `workspace_id` FK cascade
- `agent_id` FK cascade
- `chatwoot_connection_id` FK cascade
- `status` text default `active`
  - `active`
  - `inactive`
- `inbox_ids` jsonb nullable
  - lista de inboxes habilitadas; null/array vazio pode significar todas.
- `ignore_assigned_conversations` boolean default false
- `ignore_label_names` jsonb not null default array
- `handoff_label_name` text nullable
- timestamps

Indices/constraints:

- FK `workspace_id`
- FK `agent_id`
- FK `chatwoot_connection_id`
- index `workspace_id, status`
- unique `chatwoot_connection_id` onde status ativo, se a regra for 1 agente ativo por conexao.

Decisao inicial:

- Para MVP, usar 1 agente ativo por `ChatwootConnection`.
- Suporte multi-agente por inbox pode vir depois usando `inbox_ids`.

### `agent_llm_keys`

BYOK por workspace.

Campos sugeridos:

- `id`
- `workspace_id` FK cascade
- `name` text
- `provider` text
- `encrypted_api_key` text cast encrypted
- `status` text default `active`
- timestamps

Pode ser implementado nesta fase ou deixado como TODO se usarmos config/env em dev.

### `agent_runs`

Registro de execucao futura.

Campos sugeridos:

- `id`
- `workspace_id`
- `agent_id`
- `chatwoot_connection_id`
- `chatwoot_webhook_event_id` nullable
- `chatwoot_account_id`
- `conversation_id`
- `chatwoot_message_id` nullable
- `thread_id` text
- `status`
  - `queued`
  - `debouncing`
  - `running`
  - `completed`
  - `failed`
  - `ignored`
  - `waiting_human`
- `input` jsonb nullable
- `output` jsonb nullable
- `error_message` text nullable
- `started_at` timestamptz nullable
- `finished_at` timestamptz nullable
- timestamps

Pode ser criado nesta fase se o escopo permitir. Caso contrario, criar na fase de runtime dispatch.

## Configuracoes do agente para mapear no front

### Identidade

- Nome
- Descricao
- Status
- Idioma
- Timezone

### Vinculo Chatwoot

- Conexao Chatwoot
- Inboxes habilitadas
- Modo de resposta:
  - automatico
  - sugestao
  - aprovacao humana
- Ignorar conversas atribuidas a humano
- Ignorar labels
- Label de handoff

### LLM

- Provider
- Chave BYOK
- Modelo
- Temperature
- Max tokens
- Timeout
- Retry count
- Fallback model

### Prompts

- System prompt
- Prompt de comportamento
- Tom de voz
- Regras de atendimento
- Politica de escalonamento
- Mensagens proibidas

### Debounce

`debounce_config` sugerido:

```json
{
  "enabled": true,
  "window_seconds": 8,
  "max_wait_seconds": 20,
  "max_messages": 10
}
```

Chave Redis sugerida:

```text
chatwoot:debounce:workspace:{workspace_id}:connection:{connection_id}:conversation:{conversation_id}
```

### Midia

`media_policy` sugerido:

```json
{
  "audio": {
    "mode": "transcribe",
    "fallback_message": "Recebi seu audio, mas ainda nao consigo processar este formato."
  },
  "image": {
    "mode": "vision",
    "fallback_message": "Recebi sua imagem, mas ainda nao consigo analisar imagens."
  },
  "video": {
    "mode": "fallback",
    "fallback_message": "Recebi seu video, mas ainda nao consigo analisar videos. Pode me enviar em texto ou imagem?"
  },
  "file": {
    "mode": "fallback",
    "allowed_mime_types": ["application/pdf"],
    "fallback_message": "Recebi seu arquivo, mas ainda nao consigo processar esse tipo de anexo."
  },
  "max_attachment_mb": 20
}
```

Modos:

- `ignore`
- `fallback`
- `transcribe` para audio
- `vision` para imagem
- `extract_text` para PDF/documento futuro

### Guards

`guard_config` sugerido:

```json
{
  "block_sensitive_data": true,
  "block_prompt_injection": true,
  "require_rag_for_answers": false,
  "handoff_on_low_confidence": true,
  "low_confidence_threshold": 0.4
}
```

### RAG

`rag_config` sugerido:

```json
{
  "enabled": false,
  "top_k": 5,
  "min_score": 0.7,
  "answer_only_with_context": false,
  "fallback_when_no_context": "Nao encontrei informacoes suficientes na base para responder com seguranca."
}
```

### Runtime LangGraph

`runtime_config` sugerido:

```json
{
  "graph": "default_support_agent",
  "streaming": false,
  "stream_modes": ["updates"],
  "checkpointing": true,
  "long_term_memory": false,
  "human_in_the_loop": false,
  "tool_call_limit": 8
}
```

## Fluxo esperado apos esta fase

1. Chatwoot envia webhook.
2. Middleware resolve conexao.
3. Controller salva payload bruto.
4. Job classifica evento.
5. Se evento nao for processavel, marca `ignored`.
6. Se for processavel, localiza binding ativo da conexao.
7. Se nao houver agente ativo, marca `ignored` com `no_active_agent`.
8. Se houver agente ativo:
   - monta `thread_id`
   - aplica debounce configurado
   - por enquanto marca como `processed` ou cria `agent_run` em `debouncing`.
9. Fase seguinte chama Python.

## Contrato Laravel -> Python planejado

Endpoint interno sugerido:

```text
POST /internal/chatwoot/messages
```

Headers:

```text
X-Internal-Token: <token>
Content-Type: application/json
```

Payload sugerido:

```json
{
  "workspace_id": 6,
  "agent_id": 1,
  "chatwoot_connection_id": 2,
  "account_id": 1,
  "conversation_id": 3,
  "message_ids": ["21"],
  "thread_id": "workspace:6:account:1:conversation:3",
  "locale": "pt_BR",
  "timezone": "America/Sao_Paulo",
  "agent_config": {
    "response_mode": "automatic",
    "llm_provider": "openai",
    "llm_model": "gpt-4.1-mini",
    "media_policy": {},
    "guard_config": {},
    "rag_config": {},
    "runtime_config": {}
  },
  "messages": [
    {
      "id": "21",
      "content": "parafusadeira",
      "content_type": "text",
      "attachments": []
    }
  ],
  "contact": {
    "id": 4,
    "name": "Iaah02",
    "phone_number": "+554192518053"
  },
  "inbox": {
    "id": 1,
    "name": "Oryndra"
  }
}
```

Resposta Python planejada:

```json
{
  "status": "completed",
  "response": {
    "type": "text",
    "content": "Resposta gerada..."
  },
  "usage": {
    "input_tokens": 0,
    "output_tokens": 0,
    "cost": 0
  },
  "trace": {
    "thread_id": "workspace:6:account:1:conversation:3"
  }
}
```

## Ordem de implementacao recomendada

### Etapa 1 - Schema e models

- Criar migration/model/factory para `Agent`.
- Criar migration/model/factory para `AgentChatwootBinding`.
- Opcional: criar `AgentRun`.
- Adicionar relacoes em `Workspace`, `ChatwootConnection` e `Agent`.
- Testar FK, tenancy e casts JSON.

### Etapa 2 - Filament UI

- Criar `AgentResource`.
- Form com campos essenciais:
  - nome
  - status
  - prompts
  - LLM basico
  - debounce
  - media policy
  - guards basicos
- Criar tela/secao para vincular agente a `ChatwootConnection`.

### Etapa 3 - Resolver agente no webhook job

- Criar action `ResolveAgentForChatwootEvent`.
- Localizar binding ativo por `workspace_id + chatwoot_connection_id`.
- Considerar `inbox_ids` quando configurado.
- Se nao houver agente, marcar evento `ignored` com `no_active_agent`.

### Etapa 4 - Debounce base

- Criar action/job para debounce.
- Para MVP, pode armazenar mensagens no Redis e disparar job final apos janela.
- Ainda nao chamar Python.
- Registrar status claro em `agent_runs` se a tabela existir.

### Etapa 5 - Contrato Python

- Criar `AgentRuntimeClient` no Laravel.
- Criar endpoint mock no FastAPI.
- Testes de contrato Laravel -> Python.
- Validar header `X-Internal-Token`.

## Criterios de pronto da proxima fase

- Agente pode ser criado no Filament por workspace.
- Agente pode ser vinculado a uma conexao Chatwoot.
- Webhook incoming sem agente ativo vira `ignored:no_active_agent`.
- Webhook incoming com agente ativo segue para debounce/processamento inicial.
- Config de debounce/midia/guards fica salva no Laravel.
- Tests/Pint/PHPStan passam.
- Python ainda pode ser mockado.

## Comandos de verificacao

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
docker compose exec laravel-app php artisan test --compact
docker compose exec laravel-app ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
docker compose exec laravel-app php artisan migrate --force
```
