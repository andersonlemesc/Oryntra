# Agent Playground (Chat de Teste no Painel) — Phase 18

> ## ESTADO DA IMPLEMENTAÇÃO (2026-05-30, branch `feat/agent-playground-phase-18`)
>
> ### Decisões finais (sobrescrevem o texto original abaixo)
> - **Streaming**: token-a-token real. Implementado **sem** astream_events/AsyncPostgresSaver — em vez disso,
>   grafo síncrono intocado rodando em thread + **token-sink via contextvar** + `.stream()`. Bridge para SSE.
> - **Transporte**: **Reverb + job de fila** (não SSE-proxy). Job lê SSE do Python e broadcasta.
> - **AgentRun**: **Path A** — cria AgentRun real por turno (`source=playground`, `chatwoot_connection_id` nullable).
>   Tools/logs/trace/HITL 100% nativos. Stats filtram `source=chatwoot`. (Tabelas dedicadas seguem p/ UI do chat.)
> - **Contato**: escolher contato real (memória injeta/extrai).
>
> ### FEITO ✅
> **Python (agent-python)** — token streaming completo, testado:
> - `src/oryntra_agent/agent/streaming.py` (NOVO): contextvars `_token_sink`/`_event_sink`, `invoke_or_stream`,
>   `emit_token`/`emit_event`. Produção intocada quando sink None.
> - `agent/tool_runtime.py`: `track_llm_invoke` usa `invoke_or_stream`; loop emite `tool_call`/`tool_result`.
> - `agent/supervisor.py`: `generate_specialist_response_with_llm` e `generate_supervisor_opening_with_llm` usam
>   `invoke_or_stream`; `respond_node` emite `routing`; NOVA `stream_chatwoot_runtime()` (thread + asyncio.Queue).
> - `api/playground.py` (NOVO): `POST /internal/playground/stream` → SSE (token/routing/tool_call/tool_result/final/error).
> - `main.py`: registra router. `tests/test_playground_stream.py` (NOVO): 2 testes passam. **Zero regressão**
>   (baseline 10 fail / 129 pass; com mudanças 10 fail / 131 pass — mesmas falhas pré-existentes).
>
> **Laravel** — schema + execução:
> - Migrações: `create_playground_conversations_table`, `create_playground_messages_table` (com `agent_run_id` nullable),
>   `add_source_and_nullable_connection_to_agent_runs_table` (coluna `source` + `chatwoot_connection_id` nullable;
>   recria índice parcial in-flight no sqlite).
> - Enums: `AgentRunSource`, `PlaygroundMessageRole`, `PlaygroundMessageStatus`.
> - Models: `PlaygroundConversation`, `PlaygroundMessage` (+factories); `AgentRun` ganhou `source` (cast+fillable) e
>   scope `fromChatwoot()`.
> - Stats/resource filtram `fromChatwoot()`: `AgentRunStatsOverview`, `RunsThroughputChart`, `WaitingHumanRunsTable`,
>   `RecentFailedRunsTable`, `AgentRunResource::getEloquentQuery()`.
> - `AgentRuntimeClient::payload()` → **público `buildPayload()`** (reuso).
> - `Services/Playground/PlaygroundRuntimeClient.php` (NOVO): `createTurnRun()` (cria AgentRun do turno) +
>   `streamEvents()` (abre SSE do Python `withOptions(stream=>true)` e parseia eventos SSE).
> - `config/services.php`: `agent_runtime.stream_timeout` (180s).
> - Testes: `tests/Feature/PlaygroundModelsTest.php` passa; regressão DispatchAgentRun/EnqueueAgentRun OK (12 pass).
>
> ### CONCLUÍDO (commit 4a240ea) — Partes D–G
> - Broadcasting instalado: `config/broadcasting.php` (reverb), `routes/channels.php` (auth canal privado
>   `playground.conversation.{id}`), `withBroadcasting()` em `bootstrap/app.php`, `REVERB_*`/`VITE_REVERB_*` no `.env.example`.
> - `app/Jobs/Playground/StreamPlaygroundRunJob.php` (fila `playground`): lê SSE, batch de tokens (~75ms/24), broadcast
>   `PlaygroundStreamEvent` (ShouldBroadcastNow), persiste final (msg+trace+usage, AgentRun completed), extração de memória.
> - `app/Events/Playground/PlaygroundStreamEvent.php`. Horizon: supervisor+fila `playground`.
> - Front: `laravel-echo`+`pusher-js`, `resources/js/echo.js` (no build Vite, 73KB).
> - `app/Filament/Pages/AgentPlayground.php` + `resources/views/filament/pages/agent-playground.blade.php` (chat GPT-style,
>   sidebar conversas, selects agente/contato, Alpine+Echo append token-a-token + debug ao vivo) +
>   `resources/views/components/playground/debug-panel.blade.php` (trace do DB nas msgs terminais).
> - Testes: job (final/erro), fluxo de envio da page, scoping de ownership, models — **todos verde**. Pint limpo, Larastan limpo.
>
> ### FALTA p/ rodar em ambiente (usuário, no docker)
> - `php artisan migrate` (3 migrações novas) no container.
> - `cp .env.example .env`-equivalente: garantir `BROADCAST_CONNECTION=reverb` + `REVERB_*`/`VITE_REVERB_*` no `.env` real.
> - `npm install && npm run build` (já feito localmente; refazer no container se necessário).
> - Subir o container `laravel-reverb` (já existe no compose) + worker Horizon (fila `playground`).
> - Validação manual: abrir /admin/{tenant}/playground, escolher agente, enviar mensagem, ver streaming token-a-token + debug.
>
> ### (histórico) FALTA original ⬜
> 1. **Broadcasting NÃO está instalado no app** (sem `config/broadcasting.php`, `routes/channels.php`, nem
>    `withBroadcasting()` em `bootstrap/app.php`). Só o container Reverb existe. Precisa instalar server-side.
> 2. **Parte D**: `app/Jobs/Playground/StreamPlaygroundRunJob.php` (fila `playground`) — lê `PlaygroundRuntimeClient::streamEvents`,
>    broadcasta token (batched ~75ms/20) + routing/tool/trace, persiste no `final` (msg+trace+usage+response, atualiza
>    AgentRun completed, dispara `ExtractContactMemoryJob(run->id)` se completed+contato), `failed` em erro.
>    Evento único sugerido: `PlaygroundStreamEvent{messageId, kind, payload}` em `PrivateChannel("playground.conversation.{id}")`.
>    `routes/channels.php`: autorizar canal (user do workspace + dono). `config/horizon.php`: add fila `playground`.
>    `.env.example`: `BROADCAST_CONNECTION=reverb` + `REVERB_*`.
> 3. **Parte E**: `laravel-echo`+`pusher-js`, `resources/js/echo.js` (driver reverb), `npm run build`.
> 4. **Parte F**: ação de envio (cria msg user + msg assistant pending + AgentRun via `createTurnRun`, dispatch job),
>    título da conversa, `last_message_at`.
> 5. **Parte G (UI)**: `app/Filament/Pages/AgentPlayground.php` + blade (sidebar conversas, chat, selects Agente/Contato,
>    painel debug ao vivo via Echo, append token-a-token).
> 6. **Testes finais + Pint + Larastan**. Larastan ainda não rodado nos arquivos novos.
>
> ### Notas/armadilhas
> - Rodar testes Laravel com `php -d memory_limit=1G vendor/bin/pest <arquivos>` (suíte cheia estoura 128M em views Filament).
> - Migração NÃO rodada em dev/prod (DB `postgres` só acessível dentro do docker) — usuário roda `php artisan migrate` no container.
> - `agent_run_id` nas internal tool requests continua `required|min:1`; Path A garante run real, então OK.


## Objetivo

Permitir testar um agente (modo `single` ou `supervisor`) **diretamente no painel Filament**, sem precisar
de uma conexão Chatwoot. UI estilo ChatGPT: lista de conversas à esquerda, chat conversacional no centro,
e um **painel de debug** que mostra, por turno do assistente:

- roteamento do supervisor para o especialista escolhido (`specialist_id`, confidence, razão);
- acionamento de tools (nome, argumentos de entrada);
- resposta de cada tool (output);
- tokens/latência por passo e custo do turno;
- status final (`completed` / `waiting_human` / `failed`) e eventos de handoff/resolução.

A resposta do agente deve aparecer em **streaming token a token** (estilo GPT).

## Decisões aprovadas

| Tema | Decisão |
|------|---------|
| Execução/UX | **Token streaming completo** (texto aparece token a token) |
| Persistência | **Tabelas novas dedicadas** (`playground_conversations`, `playground_messages`) — não polui `agent_runs`/stats |
| Efeitos de tool | **Execução real** (gcal cria evento, query_products, MCP, HTTP connectors rodam de verdade). `send_document` é pulado (sem conexão Chatwoot) |
| Contato/memória | **Escolher contato real** — injeta/extrai memória normalmente |

## Estado atual (o que já existe e dá pra reusar)

- `AgentRuntimeClient::run(AgentRun)` (`app/Services/AgentRuntime/AgentRuntimeClient.php`) monta todo o payload
  (`supervisor`, `specialists`, `messages`, `contact`, `media_policy`, credenciais LLM, connectors, MCP) e chama
  o runtime Python. **Reusaremos o builder de payload, mas o endpoint será outro (streaming).**
- Runtime Python (`agent-python`) expõe hoje só `POST /internal/chatwoot/messages`, **request/response (sem streaming)**.
  Grafo LangGraph: `route` (supervisor) → `respond` (especialista + loop de tools), compilado com **PostgresSaver**
  (checkpointer) keyed por `thread_id`. Estado da conversa persiste por `thread_id` no Python.
- O trace já é produzido pelo runtime no formato `TraceStep` (step, type, specialist_id, tool, input, output,
  tokens{input,output}, latency_ms, ts) e o `usage` (supervisor/specialist/media/total_cost_cents) também.
  `AgentRunInfolist` já renderiza trace — reaproveitar a lógica de apresentação.
- Painel Filament é **multi-tenant por `Workspace`** (`AdminPanelProvider`), navigation groups: Agentes, Contatos,
  Chatwoot, Plataforma.

## Arquitetura de streaming (transporte) — Reverb + job de fila

**Decisão**: token streaming via **Reverb broadcasting** a partir de um **job de fila**, evitando segurar
worker PHP-FPM (o problema do SSE-proxy). O container Reverb já existe (`docker-compose`, `reverb:start :8081`).

Fluxo:

1. **Laravel (ação Livewire, rápida)**: ao enviar, cria `PlaygroundMessage` (user) + `PlaygroundMessage`
   (assistant `pending`), despacha `StreamPlaygroundRunJob` na fila **`playground`** e retorna na hora
   (FPM liberado).
2. **Job (`StreamPlaygroundRunJob`, worker Horizon — não FPM)**: monta payload, abre o **SSE do Python**
   (`Http::withOptions(['stream' => true])`), lê os chunks e **broadcasta** os eventos para o canal privado
   `playground.conversation.{id}` via Reverb. No evento `final`: persiste `content`/`trace`/`usage`/`response`/
   `status` no `PlaygroundMessage` e broadcasta `done`. Em erro: marca `failed` e broadcasta `error`.
3. **Python**: endpoint `POST /internal/playground/stream` devolve **SSE** (`text/event-stream`) — contrato abaixo.
4. **Browser**: `laravel-echo` assina o canal privado, faz append token-a-token na bolha do assistant e monta o
   painel de debug ao vivo; no `done`, dispara `$wire` para refrescar do DB (garante persistência).

**Batching obrigatório** (1 token = 1 evento WS é pesado): o job acumula tokens e faz **flush a cada ~75ms ou
~20 tokens** num único `PlaygroundTokenStreamed`. Trace/routing/tool são eventos individuais (poucos).

**Fila dedicada `playground`**: isola streams de teste dos runs de produção da fila `agent`. Adicionar ao
`config/horizon.php`.

### Infra a wirar (pequeno)

- `.env`/config: `BROADCAST_CONNECTION=reverb` + vars `REVERB_APP_*`/`REVERB_HOST`/`REVERB_PORT` (hoje
  `BROADCAST_CONNECTION=log`).
- Front: instalar `laravel-echo` + `pusher-js`, configurar `resources/js/echo.js` (driver reverb) e `npm run build`.
- `routes/channels.php`: autorizar canal privado `playground.conversation.{id}` (usuário do mesmo workspace +
  ownership da conversa).
- `config/horizon.php`: adicionar fila `playground` aos supervisors.

### Eventos SSE (contrato)

```
event: token      data: {"delta": "Olá"}                         # chunk de texto da resposta final
event: trace      data: {<TraceStep>}                            # cada passo conforme acontece
event: routing    data: {"specialist_id": 3, "confidence": 0.9}  # roteamento do supervisor
event: tool_call  data: {"tool": "...", "input": {...}}          # início de tool
event: tool_result data: {"tool": "...", "output": {...}}        # fim de tool
event: final      data: {<ChatwootRuntimeResponse completo>}     # response + trace + usage + status
event: error      data: {"message": "..."}
```

## Plano de implementação

### Parte A — Runtime Python: endpoint de streaming

1. **`agent-python/src/oryntra_agent/api/playground.py`** (novo router):
   - `POST /internal/playground/stream`, protegido por `verify_internal_token`.
   - Recebe o mesmo `ChatwootRuntimeRequest` (reuso total do schema/payload).
   - Pré-processa mídia como o endpoint atual (`preprocess_media`).
   - Retorna `StreamingResponse(media_type="text/event-stream")`.
2. **`supervisor.py`**: adicionar `async def stream_chatwoot_runtime(payload) -> AsyncIterator[dict]` que:
   - usa `graph.astream_events(..., version="v2", config=runtime_config(payload))`;
   - mapeia eventos do LangGraph para os eventos SSE:
     - `on_chat_model_stream` **filtrado pelo node `respond`** (tag/metadata `langgraph_node == "respond"`) → `event: token`;
     - finalização do node `route` → `event: routing` (specialist_id, confidence, reason);
     - `on_tool_start` / `on_tool_end` → `tool_call` / `tool_result`;
     - ao fim, montar o `ChatwootRuntimeResponse` (mesmo objeto do path síncrono, incl. `usage` do
       `_accumulated_usage` e o `trace` completo) → `event: final`.
   - Cuidado: o LLM do supervisor (node `route`) também emite tokens — **não** repassar esses como `token`
     (filtrar por node). Trace steps continuam vindos do builder existente (`runtime_trace_step`, etc.).
   - Reusar os mesmos `contextvars` (`_runtime_llm_credentials`, `_supervisor_llm_credential`,
     `_accumulated_usage`) com set/reset em torno do stream.
3. Registrar o router em `main.py`.
4. **Testes** (`agent-python/tests`): teste de que o stream emite `token` só do especialista, emite `routing`,
   `tool_call`/`tool_result` para uma tool fake, e um `final` com response+usage coerentes. Mockar LLM/tools.

> Risco: `astream_events` sobre grafo com loop de tools + supervisor exige filtragem cuidadosa por node/tags.
> Validar cedo com um agente `single` simples antes do `supervisor`.

### Parte B — Laravel: modelos e migrações (tabelas dedicadas)

1. **Migração `playground_conversations`**:
   - `id`, `workspace_id` (fk), `agent_id` (fk), `contact_id` (fk, nullable), `user_id` (criador, fk),
     `title` (string, derivado da 1ª mensagem), `thread_id` (string único — usado no checkpointer Python),
     `last_message_at` (timestamp), timestamps.
   - índices: `(workspace_id, agent_id)`, `(workspace_id, user_id)`.
2. **Migração `playground_messages`**:
   - `id`, `playground_conversation_id` (fk, cascade), `role` (`user`|`assistant`), `content` (text, nullable),
     `status` (`pending`|`streaming`|`completed`|`waiting_human`|`failed`, p/ assistant), `specialist_id` (nullable),
     `trace` (jsonb, nullable), `usage` (jsonb, nullable), `response` (jsonb, nullable — payload bruto),
     `error_message` (nullable), timestamps.
   - índice: `(playground_conversation_id, created_at)`.
3. **Models** `PlaygroundConversation`, `PlaygroundMessage` (+ factories) com casts (`trace`/`usage`/`response` => array),
   relações, e `BelongsTo Workspace/Agent/Contact`. Seguir convenções dos models existentes (`#[Fillable]`, docblocks).
4. **`thread_id`**: gerar estável por conversa, ex. `workspace:{id}:playground:{conversation_id}` (sem account/conversation Chatwoot).
   Garantir que difere do formato Chatwoot pra não colidir no checkpointer.

### Parte C — Laravel: serviço de execução do playground

1. **`app/Services/Playground/PlaygroundRuntimeClient.php`**:
   - Reaproveitar a montagem de payload do `AgentRuntimeClient` — **refatorar** o método privado `payload()`
     para um builder compartilhável (ex.: extrair `PlaygroundRunPayloadBuilder` ou tornar `payload()` reutilizável
     a partir de um objeto leve, não só `AgentRun`). Evitar duplicar a lógica de credenciais/connectors/MCP.
   - Como o builder atual depende de `AgentRun`, opção pragmática: criar um `AgentRun` **transiente/não-persistido**
     (ou um value object) com `workspace_id`, `agent_id`, `contact_id`, `thread_id`, `input.messages`,
     `conversation_id = playground_conversation_id` (sintético) e reusar o builder. **Decidir no detalhe**:
     refator limpo (preferível) vs. AgentRun transiente.
   - Método `streamUrl()/openStream(...)`: abre `Http::withToken/Headers(...)->withOptions(['stream' => true])
     ->post($base.'/internal/playground/stream', $payload)` e devolve o corpo como iterável de linhas SSE.
2. **Config**: reusar `services.agent_runtime.base_url` / `internal_token`. Sem timeout curto (stream longo):
   ajustar timeout para o caso streaming.

### Parte D — Laravel: job de streaming + broadcast Reverb

1. **`app/Jobs/Playground/StreamPlaygroundRunJob.php`** (fila `playground`):
   - Recebe `playgroundMessageId` (a msg assistant `pending`).
   - Monta payload (Parte C), abre o SSE do Python (`PlaygroundRuntimeClient::openStream`), itera as linhas.
   - Mapeia eventos SSE → broadcast no canal privado `playground.conversation.{id}`:
     - `token` → buffer; flush a cada ~75ms/~20 tokens via `PlaygroundTokenStreamed` (delta acumulado);
     - `routing`/`tool_call`/`tool_result`/`trace` → eventos próprios (poucos);
     - `final` → persiste `PlaygroundMessage` (content, trace, usage, response, status) + `PlaygroundRunCompleted`;
     - `error` → marca `failed` + `PlaygroundRunFailed`.
   - Timeout do job alto (stream longo); `tries = 1`.
2. **Eventos broadcast** (`app/Events/Playground/*`): `implements ShouldBroadcast`, `PrivateChannel
   "playground.conversation.{id}"`. Payloads enxutos.
3. **`routes/channels.php`**: autorizar `playground.conversation.{id}` — usuário pertence ao workspace da
   conversa e é dono (ou regra de workspace). Reusar contexto de tenant.

### Parte E — Filament: página + UI

1. **Página custom** `app/Filament/Pages/AgentPlayground.php` (full-width, sem ser Resource):
   - navigation group **"Agentes"**, ícone de chat, label "Playground" / "Testar Agente".
   - Livewire component (a própria Page) com estado: `agentId`, `contactId`, `conversationId`, lista de conversas,
     mensagens da conversa ativa, e form de envio.
2. **View Blade** (`resources/views/filament/pages/agent-playground.blade.php`), layout 2 colunas:
   - **Sidebar esquerda**: botão "Nova conversa", lista de conversas do usuário no workspace (título +
     `last_message_at`), seleção ativa, deletar.
   - **Centro**: header com selects de **Agente** (apenas agentes do workspace) e **Contato** (busca),
     timeline de mensagens (bolhas user/assistant), e input no rodapé.
   - Cada bolha de assistant tem um **toggle "Debug"** (Alpine) que expande o painel de trace daquele turno:
     roteamento → especialista, lista de tool_call/tool_result (input/output em `<pre>` com JSON), tokens/latência,
     usage e custo. Reaproveitar a apresentação de `AgentRunInfolist`.
3. **Streaming no front (Echo + Alpine/Livewire)**:
   - Ao enviar: ação Livewire cria msg user + msg assistant `pending` e despacha `StreamPlaygroundRunJob`.
   - Front assina o canal privado via `laravel-echo` (Alpine ou Livewire `#[On('echo-private:...')]`).
   - `PlaygroundTokenStreamed` → append no texto da bolha assistant (autoscroll); `routing`/`tool_call`/
     `tool_result`/`trace` → empilha no painel debug ao vivo; `PlaygroundRunCompleted` → marca completo e
     dispara `$wire` p/ refrescar do DB; `PlaygroundRunFailed` → mostra erro.
   - Indicador "digitando…" enquanto `pending` e sem primeiro token.
4. **Seleção single vs supervisor**: transparente — o modo vem do `Agent` escolhido; o runtime decide. Para
   `single`, o painel debug mostra menos roteamento (esperado). Sem trabalho extra de UI além de tolerar ausência
   de etapa de routing.

### Parte F — Efeitos colaterais & memória (execução real)

- Tools rodam de verdade (decisão aprovada). Documentar na UI um aviso discreto: "Tools executam de verdade".
- `send_document` depende de conexão Chatwoot → no playground será **pulado** (sem entrega), mas o trace mostra
  a tentativa. Confirmar que o runtime não quebra sem `chatwoot_internal_base_url` (passar vazio/seguro).
- Memória: contato real selecionado → injeção/extração normais. **Importante**: a extração de memória hoje é
  disparada no Job (`ExtractContactMemoryJob`) após run Chatwoot completar. No playground, decidir se dispara
  extração também (provável: sim, p/ teste fiel) — disparar após `final` com `status=completed`, reusando o job
  a partir de um identificador do playground (ou um caminho equivalente que não dependa de `AgentRun`).
  **Ponto a validar**: `ExtractContactMemoryJob` recebe `agent_run_id`; sem AgentRun persistido, ou criamos um
  caminho de extração baseado em `(contact_id, messages)` ou persistimos um AgentRun-sombra. Decidir no detalhe.

### Parte G — Testes (Pest)

- Feature: criar conversa, enviar mensagem, mock do runtime stream → persiste assistant message + trace + usage.
- Autorização: usuário de outro workspace não acessa conversa.
- Rota SSE: emite eventos e persiste no `final`; em `error` marca `failed`.
- Unit: builder de payload do playground (credenciais/connectors corretos; `thread_id` no formato esperado).

## Ordem de execução sugerida

1. Parte A (Python streaming) + testes — validar com agente `single`, depois `supervisor`.
2. Parte B (migrações/models).
3. Parte C + D (serviço + job/broadcast Reverb + infra Echo) — testar fim-a-fim com `curl` no SSE Python e
   ouvinte Reverb.
4. Parte E (página/UI/Echo streaming).
5. Parte F (efeitos/memória) + Parte G (testes) + Pint/Larastan.

## Pontos em aberto (resolver durante implementação)

- **Refator do payload builder** (compartilhar entre `AgentRuntimeClient` e playground) vs AgentRun transiente.
- **Extração de memória** no playground sem `AgentRun` persistido.
- Filtragem de tokens por node no `astream_events` (não vazar tokens do supervisor).
- **Batching de tokens** (intervalo/contagem de flush) — ajustar para fluidez vs. carga no Reverb.
- i18n: textos da UI seguindo neutralidade (UTC/en default, sem hardcode de região) conforme regra do projeto.

## Arquivos afetados (resumo)

**Python**: `api/playground.py` (novo), `agent/supervisor.py` (stream fn), `main.py`, `tests/`.
**Laravel**: 2 migrações, `Models/Playground{Conversation,Message}.php` (+factories),
`Services/Playground/PlaygroundRuntimeClient.php`, refator em `Services/AgentRuntime/AgentRuntimeClient.php`,
`Jobs/Playground/StreamPlaygroundRunJob.php`, `Events/Playground/*`, `routes/channels.php`,
`config/horizon.php` (fila `playground`), `Filament/Pages/AgentPlayground.php`,
`resources/views/filament/pages/agent-playground.blade.php`, testes Pest.
**Front/infra**: `resources/js/echo.js` (+`laravel-echo`/`pusher-js`), `BROADCAST_CONNECTION=reverb` + vars `REVERB_*`.
