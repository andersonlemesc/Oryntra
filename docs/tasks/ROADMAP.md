# Oryntra Roadmap — Resumo de Fases

> Indice vivo do que ja foi entregue e o que esta pendente.
> Atualize ao concluir ou re-priorizar uma fase. Detalhes completos ficam em cada plano `YYYY-MM-DD-*.md` neste diretorio.

## Estado atual (2026-05-24)

Branch ativa: `feature/resolve-conversation-tool` (pronta pra merge em `develop`/`main`).

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

## Fases pendentes

### Em andamento

(nenhuma)

### Adiada — decisao do usuario

| Fase | Plano | Motivo da pausa |
|---|---|---|
| 10 | `2026-05-20-rag-knowledge-base-phase-10.md` | Estrategia de extracao PDF revisada: `spatie/pdf-to-text` + `poppler-utils` no container (mais leve em memoria que `smalot/pdfparser`). Decisao registrada em `docs/integrations/chatwoot/captain-rag-reference.md`. Plano principal a ser refeito antes de iniciar. |

### Candidatas (ordenadas por prioridade sugerida)

| # | Fase | Escopo | Complexidade | Pre-req |
|---|---|---|---|---|
| 13 | **Send document via MinIO** | Tool `send_document(document_id, caption)`. Admin sobe arquivos pre-prontos no MinIO. IA escolhe enviar PDF/imagem ao cliente via Chatwoot (upload multipart). | Media | Nenhum |
| 14 | **Vision / Audio** | `transcribe_audio` (Whisper API) + `vision_describe` (GPT-4o / Claude vision). Cliente manda audio ou foto via WhatsApp, IA processa o conteudo. | Media-alta | LLM com vision habilitado |
| 15 | **DB query tools whitelisted** | Tools `query_*` parametrizadas por workspace. Ex: `query_orders(customer_email)`, `query_invoice(invoice_id)`. Laravel valida payload + executa SELECT escopado + retorna rows tipadas. | Alta | Schema do DB externo + politica de seguranca |
| 16 | **MCP servers por workspace** | Tabela `workspace_mcp_servers`. Admin cadastra URL + auth. Tools do MCP viram disponiveis no allowlist dos especialistas. Laravel atua como proxy + valida scopes. | Alta | Nenhum |
| 17 | **Notificacao de handoff** | Email / Slack / Chatwoot notification quando run cai em `waiting_human`. Hoje admin so ve no painel. | Baixa-media | Conta SMTP ou webhook Slack |
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
