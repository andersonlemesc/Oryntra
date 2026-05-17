# Visao: Supervisor + sub-agentes especialistas

Data: 2026-05-17
Status: Plano de longo prazo (referencia para fases V2+)

## Objetivo final

Permitir configurar via painel Oryntra um **agente supervisor** com **N sub-agentes (especialistas)** vinculados, cada um responsavel por um dominio ou tarefa, com suas proprias tools, prompts, modelo LLM e regras. Objetivo: cobrir 100% de um atendimento completo (texto, audio, imagem, documentos, RAG, consultas em sistemas externos, MCP) com IA, mantendo HITL onde necessario.

Cliente final ve um unico bot no Chatwoot. Internamente, o trabalho e dividido entre especialistas pelo supervisor.

## Arquitetura alvo

```
                    [ChatwootConnection]
                            |
                       [Agent (supervisor)]
                            |
            ----------------+----------------
            |          |         |          |
       [Vendas]   [Suporte]  [Financeiro]  [Tecnico]
            |          |         |          |
         tools:     tools:    tools:      tools:
          - CRM     - FAQ     - ERP        - docs
          - cotacao - KB RAG  - boleto     - troubleshoot
          - envia   - envia   - send PDF   - escalar humano
            doc       doc       boleto
```

### Como funciona em runtime

1. Webhook Chatwoot chega no Laravel
2. Laravel resolve `Agent` ativo + monta payload com config completa
3. Laravel chama Python (`/internal/chatwoot/messages`) com supervisor config + especialistas
4. Python LangGraph compila graph dinamicamente:
   - Node supervisor (LLM barato classifica intent)
   - Subgraphs ReAct por especialista (LLM forte, tools restritas)
5. Supervisor escolhe especialista (via `with_structured_output(SpecialistChoice)` Pydantic)
6. Especialista responde, possivelmente chamando tools (todas via Laravel gateway)
7. Resposta final volta tipada -> Laravel manda no Chatwoot

## Schema futuro proposto

### `agent_specialists`

Sub-agentes vinculados a um agente supervisor.

Campos:
- `id`
- `workspace_id` FK cascade
- `agent_id` FK cascade (parent)
- `name` (ex: "Vendas", "Suporte Tecnico")
- `description` text nullable
- `role_prompt` text (especializacao do papel)
- `intent_keywords` jsonb (ajuda supervisor a rotear)
- `llm_key_id` FK nullable (pode ter chave diferente do supervisor)
- `llm_model` text
- `llm_temperature` decimal
- `tools_allowlist` jsonb (lista de tool names permitidos)
- `priority` int (ordem/peso no supervisor)
- `confidence_threshold` decimal (abaixo disso, supervisor recusa rotear)
- `fallback_specialist_id` FK nullable (se este nao puder responder)
- `status` text (active/inactive)
- timestamps

Indices/constraints:
- FK `workspace_id`, `agent_id`, `llm_key_id`
- unique `(agent_id, name)`
- index `(agent_id, status, priority)`

### Mudancas em `agents`

Adicionar:
- `mode` text (single/supervisor) — default `single`
- `supervisor_prompt` text nullable (prompt do supervisor quando `mode = supervisor`)
- `supervisor_llm_key_id` FK nullable
- `supervisor_llm_model` text nullable

### `agent_tools`

Tools disponiveis pra serem atribuidas aos especialistas.

Campos:
- `id`
- `workspace_id` FK cascade
- `name` (slug unico, ex: `send_document`, `search_kb`, `query_orders`)
- `kind` text (`internal`, `rag`, `db_query`, `mcp`, `chatwoot`)
- `display_name`
- `description` text
- `config` jsonb (params especificos do kind)
- `status` text (active/inactive)
- timestamps

Kinds previstos:
- `internal` — tools nativos Oryntra (send_document, request_human_handoff, transcribe_audio)
- `rag` — busca em `document_chunks` via pgvector
- `db_query` — query parametrizada whitelisted em DB externo
- `mcp` — proxy pra ferramenta MCP cadastrada
- `chatwoot` — interacoes diretas (add_label, assign_conversation, send_typing)

### `agent_documents` + `document_chunks` (RAG)

`agent_documents`:
- `id`, `workspace_id`, `name`, `mime`, `minio_key`, `size_bytes`
- `indexed_at`, `index_status` (pending/indexing/indexed/failed)
- `tags` jsonb
- timestamps

`document_chunks`:
- `id`, `document_id` FK cascade
- `chunk_index` int
- `content` text
- `embedding` vector(1536) (pgvector)
- `metadata` jsonb
- index: `(document_id, chunk_index)`, IVFFlat em `embedding`

### `workspace_mcp_servers`

MCP por workspace.

Campos:
- `id`, `workspace_id` FK cascade
- `name`
- `transport` text (`stdio`, `http`, `websocket`)
- `url` text
- `auth_config` text encrypted
- `available_tools` jsonb cache (refresh periodico)
- `status` text
- `last_check_at` timestamp
- timestamps

### `agent_runs.trace`

Adicionar coluna `trace` jsonb append-only.

Cada step grava:
```json
{
  "step": 1,
  "type": "supervisor_route" | "llm_call" | "tool_call" | "tool_result" | "specialist_response" | "handoff",
  "specialist_id": 5,
  "tool": "search_kb",
  "input": {...},
  "output": {...},
  "tokens": {"input": 100, "output": 50},
  "latency_ms": 450,
  "ts": "2026-05-17T20:00:00Z"
}
```

## Filament UI futuro

### Pagina do `Agent` (modo supervisor)

Quando `mode = supervisor`, mostrar:

1. **Supervisor** section
   - LLM key + modelo (sugestao: gpt-4.1-nano pra classificacao rapida e barata)
   - Supervisor prompt (instrucao sobre como rotear)
   - Roteamento config (threshold confianca, fallback)

2. **Especialistas** relation manager
   - Tabela lista especialistas (nome, papel, tools, status)
   - Drag-and-drop pra ordenar prioridade
   - Acao "Adicionar especialista"
   - Edit inline ou drawer:
     - Nome + descricao
     - Role prompt (com helpers/templates: "Voce e especialista em X...")
     - LLM key + modelo
     - Tools picker (multi-select de `agent_tools` ativos do workspace)
     - Confidence threshold
     - Fallback specialist
     - Intent keywords (chips, ajuda supervisor)

3. **Tools** (recurso separado em "Agentes -> Tools")
   - Lista tools cadastradas
   - Form varia por `kind`:
     - `rag` -> selecionar collection/tags
     - `db_query` -> SQL whitelist + params schema
     - `mcp` -> selecionar server + tool
     - `internal` -> apenas habilitar/desabilitar

4. **Documentos** (recurso "Agentes -> Documentos")
   - Upload (PDF, DOCX, MD, TXT)
   - Status de indexacao
   - Tags pra scoping
   - Botao "Re-indexar"

5. **MCP Servers** (recurso "Agentes -> MCP")
   - Cadastra URL + auth
   - Testa conexao
   - Lista tools disponiveis
   - Habilita por agente

6. **Runs** (recurso "Agentes -> Execucoes")
   - Lista `agent_runs`
   - Filtros por status, especialista, conversa
   - Drill-down em `trace` (timeline visual)
   - Acao "Aprovar" pra runs em `waiting_human`
   - Re-executar com edicao

## Capacidades cobertas

### Texto
- Padrao. Especialista responde em texto puro.

### Imagem
- Tool `vision_describe(image_url)` (kind `internal`)
- Especialista "Triagem" decide se chama tool ou nao
- LLM multimodal (gpt-4o, claude-3.5) processa
- Retorna descricao -> usada como contexto

### Audio
- Tool `transcribe_audio(audio_url)` (kind `internal`)
- Whisper API
- Texto vira input do especialista

### Documentos via MinIO
- Tool `send_document(document_id, caption)` (kind `chatwoot`)
- Laravel: pre-assigna URL MinIO, upload multipart pro Chatwoot, retorna sucesso
- Especialista decide enviar baseado em prompt + contexto

### RAG
- Tool `search_knowledge_base(query, top_k, tags?)` (kind `rag`)
- Embedding query -> cosine similarity em pgvector
- Top-k chunks volta pro especialista como contexto
- Config `rag_config.answer_only_with_context = true` forca usar so contexto

### Banco/tabela externa
- Tool `query_*` whitelist por workspace (kind `db_query`)
- Ex: `query_orders(customer_email)`, `query_invoice(invoice_id)`
- Parametros tipados (Pydantic)
- Laravel valida + executa SELECT scoped + retorna rows

### MCP
- Tools dinamicamente carregadas de `workspace_mcp_servers`
- Cada tool vira `mcp_<server>_<tool>`
- Auth/permissionamento ja embutido no proxy Laravel

### HITL
- `agent_run.status = waiting_human` quando especialista chama `request_human_handoff` ou confidence baixa
- LangGraph `interrupt()` pausa graph
- Filament UI mostra run pendente, humano aprova/edita/rejeita
- Resume via `POST /internal/agent-runs/{id}/resume`

## Contrato Python (futuro)

### Request Laravel -> Python

```json
{
  "workspace_id": 1,
  "agent_id": 1,
  "agent_mode": "supervisor",
  "thread_id": "workspace:1:account:1:conversation:3",
  "supervisor": {
    "prompt": "Voce roteia mensagens entre especialistas...",
    "llm_key": "encrypted-handle",
    "llm_model": "gpt-4.1-nano"
  },
  "specialists": [
    {
      "id": 5,
      "name": "Vendas",
      "role_prompt": "Voce e especialista em vendas...",
      "llm_key": "encrypted-handle",
      "llm_model": "gpt-4.1-mini",
      "tools": ["search_kb", "send_document", "query_orders"],
      "intent_keywords": ["preco", "comprar", "cotacao"],
      "confidence_threshold": 0.6,
      "fallback_specialist_id": null
    }
  ],
  "tools": [
    {"name": "search_kb", "kind": "rag", "config": {...}},
    {"name": "send_document", "kind": "chatwoot", "config": {...}}
  ],
  "messages": [...],
  "contact": {...},
  "inbox": {...},
  "guard_config": {...},
  "rag_config": {...},
  "runtime_config": {...}
}
```

### Response Python -> Laravel

```json
{
  "status": "completed" | "waiting_human" | "failed",
  "response": {
    "type": "text" | "send_document" | "escalate" | "clarify" | "multi",
    "content": "...",
    "document_id": null,
    "handoff_reason": null,
    "confidence": 0.85
  },
  "specialist_id": 5,
  "trace": [...],
  "usage": {
    "supervisor": {"input_tokens": 50, "output_tokens": 20},
    "specialist": {"input_tokens": 800, "output_tokens": 200},
    "total_cost_cents": 5
  }
}
```

## Roadmap por etapas

| Etapa | Escopo | Pre-requisito |
|---|---|---|
| 5 | Cliente runtime Python + endpoint mock + Pydantic + trace basico | Etapa 4 (ja pronto) |
| 6 | LangGraph supervisor + 1 especialista demo + UI Filament minima | Etapa 5 |
| 7 | RAG: documentos + pgvector + tool `search_kb` | Etapa 6 |
| 8 | Vision + Audio tools | Etapa 7 |
| 9 | Send document via MinIO | Etapa 8 |
| 10 | MCP servers por workspace | Etapa 9 |
| 11 | DB query tools whitelisted | Etapa 10 |
| 12 | HITL completo com aprovacoes pendentes | qualquer fase |
| 13 | Roteamento por inbox/label/horario (Nivel 1) | Etapa 6+ |
| 14 | Trace UI rica (timeline visual, replay, edit) | Etapa 12 |
| 15 | Billing/custos por run + workspace | Etapa 11 |

## Decisoes arquiteturais fixas

1. **Tudo passa pelo Laravel** — Python nunca acessa Chatwoot/MinIO/DB direto. Laravel e o gateway unico de tools.
2. **Tenancy automatica em toda tool** — Laravel injeta `workspace_id` antes de executar qualquer query.
3. **Pydantic estrito** — resposta Python sempre tipada. Nunca string parsing.
4. **Trace append-only** — cada step do graph e auditavel.
5. **Permissionamento granular** — `agent_specialist.tools_allowlist` define o que cada especialista pode chamar.
6. **Storage durable** — `agent_runs`, `agent_specialists`, `agent_documents` em Postgres. Redis so pra cache, lock e queue.
7. **MinIO pra blobs** — sempre. URLs pre-assigned com TTL curto.
8. **pgvector pra RAG** — nao Qdrant/Weaviate inicialmente. Migracao se volume justificar.
9. **OpenTelemetry/Langfuse opcional** — instrumentar codigo desde inicio, ativar quando precisar.
10. **HITL = first-class citizen** — nao bolt-on. Schema (`waiting_human`), UI (aprovacoes pendentes), API (`/resume`) integrados.

## Riscos e mitigacoes

| Risco | Mitigacao |
|---|---|
| Roteamento errado do supervisor | `confidence_threshold` + `fallback_specialist` + UI mostra qual especialista atendeu |
| Custo alto (multiplos LLM calls) | Supervisor sempre modelo nano/barato; cache de respostas; debounce ja agrupa msgs |
| Latency com varios specialists | Supervisor 1 LLM + specialist 1 LLM = ~2-3s tipico. Streaming de resposta no V3 |
| Debug complexo (ReAct + supervisor) | Trace estruturado + UI timeline desde inicio |
| Vazamento entre workspaces | Tenancy enforced em toda tool. Test obrigatorio |
| MCP server malicioso | Sandbox: Laravel proxy + scopes + rate limit por server |
| Document leak via RAG | `agent_documents.tags` + `agent_specialist.allowed_tags`. Filtra antes do retrieval |
| Custos sem visibilidade | `agent_runs.usage` por run; dashboard por workspace; alertas de budget |

## Fora deste plano (descartado intencionalmente)

- Multi-LLM hosting/self-host de modelos
- Treinamento/fine-tuning customizado por workspace
- Voice calls (so atendimento texto/midia via Chatwoot)
- Marketplace publico de especialistas pre-prontos (so V99)
- Federated learning entre workspaces

## Referencias

- Plano Etapa 1-5 base: ver `2026-05-17-agent-configuration-runtime-plan.md`
- Post LangGraph que inspirou (LicitaCerta AI): supervisor + sub-graphs ReAct, HITL via `interrupt()`, reducers append-only, structured outputs
- Pydantic-first design: garantia de contrato Python <-> Laravel
- LangGraph docs: subgraphs, checkpointing, HITL patterns

## Estado atual (2026-05-17)

Implementado ate **Etapa 4** (ver plano base):
- Schema agents + bindings + llm_keys + agent_runs
- Resolver agente no webhook
- Debounce com janela + extensao
- Job mock de execucao
- Filament UI minima

Proximo passo: **Etapa 5** (runtime Python + Pydantic + trace basico). Tudo neste documento e V2+, construido em cima da base atual sem refatoracao destrutiva.
