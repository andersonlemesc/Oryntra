# Async Agent Runtime + Escalabilidade Produção

**Data:** 2026-06-02
**Status:** Implementado

## Problema

O fluxo de agent run era HTTP síncrono bloqueante: `DispatchAgentRunJob` chamava
`AgentRuntimeClient::run()` que bloqueava o worker PHP (256MB) durante todo o run LLM
(30s–2min). Teto de concorrência = nº de workers PHP, com RAM desperdiçada em workers
ociosos-bloqueados. Como o projeto será open-source + serviço próprio, o shape síncrono
viraria contrato caro de mudar depois.

BYOK (chave LLM por account) → sem teto LLM compartilhado; o gargalo é o pool de workers
(escala horizontal), o que torna **noisy-neighbor** crítico.

## Solução (hard cutover, sem feature flag)

Migração para **async-callback**: Laravel dispara o run e libera o worker; Python roda em
background e devolve o resultado via callback HTTP interno.

```
DispatchAgentRunJob → POST /internal/chatwoot/messages/dispatch (202) → worker liberado
Python (background, semáforo) → POST /api/internal/agent-runs/{id}/result
  → AgentRunResultController → FinalizeAgentRunJob (entrega Chatwoot + status)
```

### Arquivos

**PHP**
- `AgentRuntimeClient::start()` — dispatch fire-and-forget (202). `run()` síncrono mantido
  para o preview admin (`EditAgent`). `agent_run_id` adicionado ao payload.
- `DispatchAgentRunJob` — emagrecido para fire-only; `tries=3` + `failed()` marca Failed.
- `FinalizeAgentRunJob` — **novo**; lógica de entrega/finalização movida (idempotente).
- `AgentRunResultController` + `AgentRunResultRequest` + rota `internal.agent-runs.result`.
- `ReapStuckAgentRunsCommand` (`agent:reap-stuck-runs`) — agendado a cada minuto; marca
  runs `Running` > `AGENT_RUN_TIMEOUT` como Failed (substitui o timeout HTTP síncrono).
- `Middleware/ThrottleAgentRunsPerWorkspace` — cap de runs concorrentes por workspace
  (fairness / anti noisy-neighbor) na fila `agent`.
- `config/horizon.php` — `maxProcesses` env-driven (`HORIZON_*_MAX`).
- `config/services.php` — `accept_timeout`, `run_timeout`, `max_concurrency_per_account`.

**Python**
- `api/chatwoot_messages.py` — endpoint `/messages/dispatch` (202 + background + semáforo),
  `_execute_runtime()` extraído, refs fortes às tasks de background.
- `runtime_callback.py` — **novo**; POST do resultado ao Laravel com retry/backoff.
- `settings.py` — `agent_max_concurrency`, `callback_timeout_seconds`.
- `main.py` — logging estruturado por `log_level`.

**Infra**
- `docker-compose.yml` — service `pgbouncer` (transaction mode; Laravel opt-in via
  `LARAVEL_DB_HOST=pgbouncer`); Python fica direto no postgres (checkpointer LangGraph usa
  prepared statements). Envs de concorrência + LangSmith passthrough no agent-python.
- `docker/agent-python/Dockerfile` — prod multi-worker (`UVICORN_WORKERS`).
- `.env.example` — perfil "server médio" (4 vCPU/16GB).

## Sizing

```
concorrência LLM = min(HORIZON_AGENT_MAX, UVICORN_WORKERS * AGENT_MAX_CONCURRENCY)
fairness por tenant = AGENT_MAX_CONCURRENCY_PER_ACCOUNT
```

## Testes

- Pest: `DispatchAgentRunJobTest` (reescrito, fire-only), `FinalizeAgentRunJobTest` (novo),
  `Internal/AgentRunResultControllerTest`, `Agent/ThrottleAgentRunsPerWorkspaceTest`,
  `Agent/ReapStuckAgentRunsCommandTest`. 21 testes.
- pytest: `test_chatwoot_dispatch.py` (202, agent_run_id obrigatório, callback completed/failed).
- Pint + phpstan limpos; ruff limpo; suíte Python 166 passed (76% cobertura).

## Pendente (verificação E2E manual)

- Subir docker compose, enviar webhook real, confirmar no Horizon que o worker `agent`
  libera rápido e o `FinalizeAgentRunJob` roda após callback.
- Matar o container Python mid-run → reaper marca Failed em ≤ `AGENT_RUN_TIMEOUT`.
- Validar pgbouncer no caminho do Laravel sem erro de prepared statement no checkpointer.
