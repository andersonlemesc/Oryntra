# Oryntra Roadmap — Resumo de Fases

> Indice vivo do que ja foi entregue e o que esta pendente.
> Atualize ao concluir ou re-priorizar uma fase. Detalhes completos ficam em cada plano `YYYY-MM-DD-*.md` neste diretorio.

## Estado atual (2026-05-27)

Branch ativa: `develop` com fases 4–15 entregues. Pronta para testar em Chatwoot real.

## Fases entregues

| Fase | Plano | Resumo do entregue |
|---|---|---|
| 4 | base | Schema `agents` + `agent_chatwoot_bindings` + `agent_llm_keys` + `agent_runs`. Resolver agente no webhook. Debounce com janela e extensao. Job mock de execucao. Filament minimo. |
| 5 | `2026-05-17-agent-runtime-python-contract-phase-5.md` | Runtime Python (FastAPI) + Pydantic. Cliente Laravel `AgentRuntimeClient`. Trace basico em `agent_runs.output.trace`. |
| 6 | `2026-05-17-agent-supervisor-phase-6-plan.md` | LangGraph supervisor + 1 especialista demo. Roteamento deterministico via `intent_keywords` quando supervisor LLM indisponivel. |
| 6.1 | `2026-05-17-supervisor-admin-ux-phase-6-1.md` | Filament UI para supervisor (mode select, supervisor LLM, prompt). Visibility rules entre Single e Supervisor. |
| 7 | `2026-05-19-human-handoff-tool-phase-7.md` | Tool `request_human_handoff`. Endpoint interno `/api/internal/agent-tools/request-human-handoff`. `agent_run.status = waiting_human`. |
| 7.1 | `2026-05-19-human-handoff-admin-rules-phase-7-1.md` | UI das regras de handoff por especialista (palavras-chave, prioridade, mensagem). |
| 8 | `2026-05-19-chatwoot-native-tools-phase-8.md` | Tools Chatwoot nativas: send_message, add_private_note, add_label, assign_team, assign_agent. Job `ApplyHumanHandoffToChatwootJob` com retry e idempotencia. `handoff_assign_strategy` na binding. |
| 9 | `2026-05-19-hitl-dashboard-filament-ux-phase-9.md` | `AgentRunResource` (lista + view com Tabs: Resumo, Handoff, Trace bruto, Erros). HITL Approve/Reject/Edit com lock for update. Endpoint `/api/internal/agent-runs/{id}/resume` idempotente. Dashboard widgets (stats 24h, waiting humano, throughput chart, falhas recentes). AgentForm em 4 tabs (Geral / Modelo / Comportamento / Execucao). Specialists em 4 tabs com repeater colapsavel. Bindings em 2 secoes nomeadas. Brand `Oryntra` + paleta Indigo + nav groups fixos + sidebar collapsible. 26 hint tooltips em campos tecnicos. Aba Trace visual com RepeatableEntry (badge colorido por tipo de step). |
| Memory | `2026-05-20-langgraph-short-term-memory-routing.md` | LangGraph persiste `conversation_messages` por `thread_id`. Especialista ativo permanece entre turnos (`active_specialist_continuation`) — supervisor so re-rotea quando mensagem sai do escopo. Prompts de especialista e supervisor recebem historico recente. Validado em conversa Chatwoot real (13 mensagens preservadas, IA nao repete perguntas). |
| 7.2 | `2026-05-20-handoff-auto-execute-and-contact-tools-phase-7-2.md` | Handoff dispara side effects direto (sem gate HITL): abre conversa via `toggle_status`, manda mensagem ao cliente, nota privada (com resumo LLM opcional), label, atribuicao team/agent. Migrations `chatwoot_teams` + `chatwoot_team_members` + `workspace_members.chatwoot_user_id` + `chatwoot_connections.admin_api_token`. Sync via `ChatwootAdminApiClient`. Tools `request_team_handoff`, `chatwoot_get_contact`, `chatwoot_update_contact`. Filament: 3 tabs novas no especialista. Bugfix: `DispatchAgentRunJob` preserva handoff payload no merge. |
| 7.3 | `2026-05-21-specialist-first-handoff-config.md` | `label_name` + `private_note_template` viraram campos por especialista. Binding mantem como fallback default. Hints Filament esclarecem que binding sao defaults. |
| 11 | `2026-05-23-contacts-and-long-term-memory-phase-11.md` | Contacts criados automaticamente do webhook (`agent_runs.contact_id`). `contact_memories` (preferencia/fato/restricao/historico) por type+source. Tool `update_contact_memory` para a IA registrar fatos. Job `ExtractContactMemoryJob` que pede ao LLM novos fatos pos-run (endpoint Python `/internal/memory/extract`). Injecao das memorias no system prompt do especialista (top N por recencia, configuravel). `chatwoot_get_contact` usa cache local (5min) e `chatwoot_update_contact` sincroniza linha local. Filament `ContactResource` com tabs Resumo/Memorias/Chatwoot raw, badges de lead_status, bulk actions de pipeline, widgets de leads no dashboard. Sync horario via `SyncChatwootContactsJob` + botao manual no Filament. |
| 12 | `2026-05-24-resolve-conversation-tool-phase-12.md` | Tool `resolve_conversation` permite IA encerrar conversa Chatwoot quando resolve sozinha. Nova coluna `agent_specialists.resolution_config` (jsonb) com `enabled`, `customer_message`, `label_name` e `rules` (keyword-triggered). Action + Job idempotente (no-op se conversa ja `resolved`), ordem `msg -> label -> toggle_status=resolved`. Supervisor Python: `matching_resolution_rule` + acao `resolve_conversation` no `SpecialistDecision`. Filament tab "Encerramento" com toggle, label, mensagem padrao e repeater de regras. Allowlist obrigatoria (`resolve_conversation` no `tools_allowlist`). |
| 12.1 | mesmo plano | `resolve_conversation` virou StructuredTool real em `EXECUTABLE_TOOLS`. `build_specialist_tools` aceita `terminal_state` opcional; `run_specialist_tool_loop` curto-circuita o loop quando a tool dispara. Permite IA invocar resolve sem depender de regras de keyword e sem precisar de `contact_id`. Supervisor monta `resolution_response_from_tool_call` quando o loop sinaliza `resolved`. `ToolRuntimeContext.thread_id` adicionado pra payload Laravel. |
| 12.2 | mesmo plano | Sync de labels Chatwoot. Tabela `chatwoot_labels`, `ChatwootAdminApiClient::listLabels()`, `SyncChatwootLabelsJob` (upsert + remove stale, no-op sem admin token), schedule horario `chatwoot:sync-labels-hourly` + chain `SyncChatwootMetadataJob`. Filament `handoff_config.label_name`, `resolution_config.label_name` e `rules.*.label_name` viraram Select alimentado por `chatwootLabelOptions()`. Elimina erro de digitar label inexistente. |
| 12.3 | mesmo plano | Fix em `DispatchAgentRunJob`: preserva `output.resolution` escrito pela Action no merge da resposta do Python, simetrico ao `handoff` que ja era preservado. Sem o fix, `reason`/`resolution_summary`/`customer_message`/`label_name` desapareciam e o Job de side effects marcava label e customer_message como `skipped`. |
| 12.4 | mesmo plano | System prompt agora carrega `Dados do cliente em atendimento:` (name/email/phone_number/lead_status) + `Data e hora atuais:` (ISO + dia da semana em pt-BR, no `workspace.timezone`). `AgentRuntimeClient::contactPayload` expoe os campos basicos; `runtimeConfig` injeta `workspace_timezone`. Filament ganha `EditWorkspaceProfile` (nome/TZ/locale). `supervisor_opening_messages` tambem recebe os blocos injetados, e a instrucao "pergunte o nome" virou "use o nome ja injetado, so pergunte se nao houver". Resolve o caso em que IA pedia nome a cada conversa. |
| 12.5 | mesmo plano | Aplicacao de label da conversa via Admin API. Chatwoot bloqueia agent_bot do `POST /conversations/{id}/labels` (`Access to this endpoint is not authorized for bots`). `ChatwootAdminApiClient::addConversationLabel` faz GET das labels existentes + POST com merge. `ApplyResolveConversationToChatwootJob` e `ApplyHumanHandoffToChatwootJob` usam admin client pro label step; sem admin token marcam `label=failed/skipped` com error claro. |
| 12.6 | mesmo plano | Timeline Filament mostra nome do especialista (`Nome (#id)`) em vez do id puro. `AgentRunInfolist::traceSteps` faz pluck de `agent_specialists` e injeta `specialist_label` em cada step, com fallback pro `output.specialist_name` ja existente. |
| 15 | `jaunty-rolling-mango` (plano local) | Connectors de API externa (HTTP genérico). Registro unificado `external_tools` (coluna `kind`, pensado pra MCP reusar) + log genérico `external_tool_call_logs`. Admin cadastra connector via Filament `ExternalToolResource` (slug snake_case, método CRUD, auth none/api_key/bearer/basic com `credentials` encrypted, params via Repeater tipado **ou** JSON avançado, extração jsonpath/template + truncamento). `ExternalToolExecutor` valida args contra schema, injeta auth+base_url, guarda scheme http/https (modelo confia-no-admin, permite IP interno), retry só GET, grava log. Endpoint `POST /api/internal/agent-tools/call-external-tool` + Action `CallExternalTool` (tenancy + allowlist do especialista). `AgentRuntimeClient` injeta `external_tools[]` (slug/description/param_schema, sem segredo). Python: `ExternalToolConfig` no `SpecialistConfig`, `build_external_tool` monta StructuredTool dinâmico via `create_model`, supervisor roda o loop mesmo com só connector. Aba "APIs externas" no especialista reconcilia slugs no `tools_allowlist`. Auto-executa (sem gate HITL). 297 Pest verdes; 7 pytest novos verdes (9 falhas pré-existentes de isolamento do checkpointer LangGraph, não relacionadas). |
| API+MCP | `2026-05-31-public-api-mcp-server.md` | **MCP server próprio do Oryntra** (inverso da Fase 16). Sanctum PAT escopado a 1 workspace (`ApiToken` + `workspace_id` + `ApiTokenAbilities`). REST público `/api/v1` (auth:sanctum + throttle:mcp por token + `ResolveApiWorkspace`/`WorkspaceContext`; binding 404 cross-workspace; abilities por rota). CRUD de agents (+ auto-specialist reusado do Filament), specialists, llm-keys (+ models), categories, products (+ docs), standalone documents, knowledge RAG (`from-text` inline + confirm de upload), HTTP connectors e MCP servers (segredos write-only/encrypted + discovery `tools`). Upload presigned MinIO (`temporaryUploadUrl` + `upload_id` HMAC + `ConfirmsUploads`). Perfil no avatar: `ProfilePage`, `SecurityPage` (trocar senha + 2FA TOTP + recovery; `User` ganhou `TwoFactorAuthenticatable`), `ApiTokensPage`. Pacote npm `packages/oryntra-mcp` (stdio, 30 tools Zod). 15 testes novos (Api/Profile) verdes; suíte 374 verde (1 falha pré-existente `FortifyViewsTest`). Pendente: UI de passkeys, publicar npm. |

## Fases pendentes

| 14.1 | `2026-05-24-vision-audio-phase-14-1.md` | Pipeline de mídia: audio → Whisper/Gemini, imagem → GPT-4o/Claude/Gemini Vision. `media_policy` por tipo (enabled + fallback_message). `audio_llm_key_id` + `vision_llm_key_id` no Agent. Filament: tab Mídia com 4 seções. `preprocess_media` async → short-circuit ou inject text. Trace steps + `usage.media` para cobrar tokens. Migração `media_config` → `audio/vision_llm_key`. `ProductSearchService` com unaccent + pg_trgm word_similarity. |
| 14.2 | mesmo plano | Busca fuzzy de produtos com unaccent + token-by-token + pg_trgm word_similarity. "bicicleta eletrica" retorna 5 bikes, "bike" retorna 6 via similarity. Migração para ativar extensions unaccent e pg_trgm. |
| 14.3 | `2026-05-25-send-document-phase-14.md` | Tool `send_document(document_id, caption)`. Tabelas `product_documents` + `documents` (MinIO). Filament upload em Produto + DocumentResource standalone. Action `SendDocument` resolve doc, baixa de MinIO, envia como attachment Chatwoot. `NativeTool::SendDocument` + Python tool builder + `RuntimeResponsePayload.type=send_document`. Dispatch no `DispatchAgentRunJob`. 252 Pest tests + 86 pytest. |
| 14.4 | `2026-05-25-document-discovery-phase-14-4.md` | Fecha lacunas que impediam a IA de usar `send_document` na prática. Enum `DocumentCategory` carrega o purpose (`knowledge` não é enviável). `send_document` ganha `document_type` (`product`/`standalone`) — resolve colisão de IDs entre as duas tabelas. `query_products` passa a renderizar os documentos do produto (id + filename) no texto do LLM. Nova tool `query_documents` para a biblioteca avulsa (só categorias enviáveis, filtrada por `allowed_categories` do especialista). Aba "Documentos" no especialista com toggles `query_enabled`/`send_enabled` + categorias permitidas; reconcile no allowlist. Migração `document_tools_config`. 261 Pest tests + pytest tool_runtime. |

### Em andamento

(nenhuma)

### Adiada — decisão do usuário

| Fase | Plano | Motivo da pausa |
|---|---|---|
| 10 | `2026-05-31-rag-knowledge-base-extraction.md` | ✅ Entregue (2026-05-31) — RAG greenfield com domínio próprio. Tabela `agent_documents` + `document_chunks` (pgvector unsized + filtro por `embedding_model`); resource Filament "Base de Conhecimento" separada da mídia enviável (resource antiga renomeada "Mídias"). Pipeline de extração→chunk→embedding **no Python** (regra 6): `pypdf` para PDF digital, fallback vision-LLM (`pypdfium2`+`pillow`) para escaneado, markdown/texto direto. Embeddings BYOK por workspace reusando `AgentLlmKey`; trocar o modelo reindexa tudo com modal de aviso de custo. Tool `search_knowledge_base` (Laravel orquestra embedding da query via Python, roda SQL pgvector workspace-scoped). Endpoints `/internal/rag/ingest` e `/internal/rag/embed-query`. |

### Candidatas (ordenadas por prioridade sugerida)

| # | Fase | Escopo | Complexidade | Pre-req |
|---|---|---|---|---|
| 13 | **Trace latency real** | ✅ Entregue — `latency_ms` e `tokens` já populados nos trace steps. | Baixa | Nenhum |
| 13.1 | **Tabela de produtos + tool query_products** | ✅ Entregue — Fase 13.1 completa com Filament resource, CSV import e tool. | Media | Nenhum |
| 14 | **Send document via MinIO** | ✅ Entregue — Fase 14.4 completa: descoberta de documentos (`query_documents` + docs de produto no `query_products`), `document_type` para evitar colisão de IDs, purpose por categoria (`knowledge` não enviável), aba "Documentos" no especialista. |
| 15 | **Connectors de API externa (HTTP)** | ✅ Entregue — connector HTTP genérico definido em DB (`external_tools`), Filament CRUD, executor com auth/extração/log. Cobre o caso "query_orders/query_invoice" chamando a API do cliente (inclusive interna). DB-direct (SELECT escopado sem API) fica como ideia separada de menor prioridade. | Alta | Concluído |
| 17 | **Google Calendar como tool** | IA ganha integração Google Calendar via OAuth. N conexões por workspace (modelo n8n credentials), refresh lazy, 5 tools (`gcal_list/create/update/delete_events`, `gcal_find_free_slots`). Sem sync local, sem webhooks — 100% live contra API. Decisões em `2026-05-28-google-calendar-tool-phase-17.md`. Client ID/Secret único no `.env` (padrão SaaS multi-tenant); tokens OAuth isolados por workspace. | Média | Google Cloud project + OAuth consent screen + env vars `GOOGLE_CALENDAR_CLIENT_ID/SECRET/REDIRECT_URI` |
| 16 | **MCP servers por workspace** | Admin cadastra servidor MCP (URL + auth); tools do MCP entram no allowlist. **Reusa o registro `external_tools` (novo `kind='mcp'`) + `external_tool_call_logs` + dispatch do executor** montados na Fase 15 — falta o cliente MCP (handshake/list_tools) e a tradução de schema. Decisões registradas em `2026-05-27-mcp-servers-phase-16.md`: transporte **só Streamable HTTP** (alvo n8n novo, sem `/sse`), SSE legado fora de escopo, client no Laravel. Âncora: n8n MCP Server Trigger (tools tipadas de graça). Adiada para depois da Fase 17. | Alta | Fase 15 (feita) |
| — | **Notificacao de handoff** | Email / Slack / Chatwoot notification quando run cai em `waiting_human`. Hoje admin so ve no painel. | Baixa-media | Conta SMTP ou webhook Slack |
| 18 | **Trace polish** | Replay step-by-step com cursor, edicao inline de step, comparacao de runs. Hoje a timeline ja existe (Fase 9) mas e read-only. | Media | Fase 9 (feita) |
| 19 | **Drag-and-drop priority especialistas** | UI Filament arrasta especialistas dentro do agent para reordenar `priority`. | Baixa | Nenhum |
| 20 | **Billing / custos por run** | Acumular tokens + custo estimado por LLM key em `agent_runs.usage`. Dashboard por workspace + alerta de budget. | Media | Tabela de pricing por provider |
| 21 | **Localizacao** | Strings PT-BR extraidas para `lang/pt_BR/`. Permite fallback `en` por usuario. | Media | Nenhum |

## Decisoes arquiteturais fixas (relembrando)

- Tudo passa pelo Laravel. Python nunca acessa Chatwoot, MinIO ou DB direto.
- Tenancy automatica em toda tool. Laravel injeta `workspace_id` antes de executar qualquer query.
- Pydantic estrito no contrato Laravel <-> Python.
- Trace append-only em `agent_runs.output.trace`.
- HITL = first-class citizen (`waiting_human` status + UI + endpoint resume).
- pgvector + MinIO ja disponiveis nos containers Docker.

## Como retomar uma fase

1. Abrir o plano em `docs/tasks/YYYY-MM-DD-<fase>.md` correspondente.
2. Procurar a primeira Task com checkbox `- [ ]` (todas as anteriores devem estar `- [x]`).
3. Antes de codar, conferir esta secao "Pre-req" da fase neste roadmap.
4. Ao concluir uma fase, mover a linha de "Pendentes" para "Entregues" e atualizar este arquivo.

## Referencias

- Vision global do produto: `2026-05-17-multi-agent-supervisor-vision.md`
- Plano base original: `2026-05-17-agent-configuration-runtime-plan.md`
- Boost guidelines: `CLAUDE.md` na raiz do projeto.
