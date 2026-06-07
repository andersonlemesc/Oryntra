# Agent Runtime — Conexão Postgres Morta no Checkpointer LangGraph

**Data:** 2026-06-07
**Status:** Implementado
**Escopo:** `agent-python` (runtime) + `laravel` (rede de segurança na falha).

## Resultado

- **Parte 1 — Pool com liveness-check** no checkpointer LangGraph (corrige a causa
  raiz). Arquivos: `agent-python/src/oryntra_agent/agent/supervisor.py`
  (`ConnectionPool` + `check_connection` + keepalives, `close_runtime_checkpointer`),
  `settings.py` (`pg_pool_min_size`/`pg_pool_max_size`), `main.py` (fecha pool no
  shutdown), `tests/conftest.py` + `tests/test_supervisor_runtime.py`.
- **Parte 2 — Fallback de falha (handoff humano)** para nenhum cliente ficar sem
  resposta mesmo quando o pool não cobre a falha. `OpenConversationOnAgentFailureJob`
  abre a conversa, trava o bot (human takeover), posta nota privada e — se o binding
  ativo configura destino — atribui time/agente e aplica label. Disparado pelo
  `FinalizeAgentRunJob` no status terminal Failed, cobrindo o callback de falha **e**
  o `agent:reap-stuck-runs` (ambos finalizam por ali). Cliente não recebe mensagem de
  erro automática; um humano assume. Decisões: silencioso + nota privada; handoff se
  configurado senão só abrir; finalize + reaper.
- Testes: pytest 168 verde; Pest `OpenConversationOnAgentFailureJobTest` +
  `FinalizeAgentRunJobTest` (rodam no CI — DB Postgres). Pint + PHPStan + ruff limpos.

## Problema

Agent runs falham de forma intermitente/persistente com:

```
consuming input failed: server closed the connection unexpectedly
This probably means the server terminated abnormally before or while processing the request.
```

O erro aparece no `runtimeResult.error` devolvido pelo agent-python ao Laravel
(`FinalizeAgentRunJob` apenas grava o resultado — ele roda OK). O run morre em
**duração 0s**, ou seja, na **primeira operação de DB do runtime**, depois de um
intervalo de ociosidade entre conversas (caso observado: ~4 min desde o
`ExtractContactMemoryJob` anterior).

## Diagnóstico (descartados)

| Hipótese | Verdito | Evidência |
|---|---|---|
| Webhook URL com `}` / assinatura | Não | webhook job `ProcessChatwootWebhookEventJob` rodou em 0.05s |
| PG reiniciou (deploy/OOM) | Não | `pg_postmaster_start_time` = 2026-05-26 (uptime ~12 dias) |
| idle-timeout do PG mata conexão | Não | `idle_in_transaction_session_timeout` = 0, `idle_session_timeout` = 0 |
| Estouro de `max_connections` | Não | 72 abertas / 200 max |
| pgbouncer ausente | Irrelevante | pooler não evita; em transaction mode criaria erro novo |
| `retry_after` < job timeout / lock contention (Laravel) | Não | falha está no `runtimeResult`, não no worker PHP |

## Causa raiz

`runtime_checkpointer()` em `src/oryntra_agent/agent/supervisor.py:287` cacheia **uma
única conexão psycopg** num global e a reusa pela vida do processo:

```python
def runtime_checkpointer() -> Any:
    global _postgres_checkpointer_context
    if settings.langgraph_checkpointer == "postgres":
        if _postgres_checkpointer_context is None:
            _postgres_checkpointer_context = PostgresSaver.from_conn_string(settings.postgres_url)
        return _postgres_checkpointer_context.__enter__()
    return InMemorySaver()
```

- `PostgresSaver.from_conn_string` abre **1 conexão** (não pool).
- Guardada em global → vive o processo inteiro (o build do grafo também é cacheável).
- Idle entre conversas → a conexão morre (TCP/keepalive/rede do overlay — não o PG,
  que tem idle-timeout 0).
- **Sem health-check/reconnect**: a conexão morta fica cacheada → todo run seguinte
  falha com `server closed the connection unexpectedly` até reiniciar o container.
- `.__enter__()` repetido no mesmo context manager já consumido é frágil por si só.

## Solução

Trocar a conexão única por um **pool psycopg com liveness-check**, que valida (e
descarta) a conexão morta antes de entregar. Deps já presentes:
`psycopg[binary,pool]>=3.2` + `langgraph-checkpoint-postgres>=2.0`.

```python
from psycopg_pool import ConnectionPool

_checkpointer_pool: ConnectionPool | None = None

def runtime_checkpointer() -> Any:
    global _checkpointer_pool
    if settings.langgraph_checkpointer != "postgres":
        return InMemorySaver()

    if _checkpointer_pool is None:
        _checkpointer_pool = ConnectionPool(
            conninfo=settings.postgres_url,
            min_size=settings.pg_pool_min_size,
            max_size=settings.pg_pool_max_size,
            open=True,
            check=ConnectionPool.check_connection,  # descarta conexão morta no getconn
            kwargs={
                "autocommit": True,
                "prepare_threshold": 0,
                # keepalives: derruba conexão semi-morta cedo, no nível TCP
                "keepalives": 1,
                "keepalives_idle": 30,
                "keepalives_interval": 10,
                "keepalives_count": 5,
            },
        )

    return PostgresSaver(_checkpointer_pool)
```

`check=ConnectionPool.check_connection` resolve exatamente o caso: a cada `getconn`
o pool roda um `SELECT 1`; se a conexão morreu na ociosidade, é descartada e uma
nova é aberta. `PostgresSaver(pool)` é leve — pode ser criado por run; o estado vive
no pool compartilhado, então mesmo um saver de vida longa pega conexão validada por
operação.

### Notas de implementação

- Remover o global `_postgres_checkpointer_context` (linha 60) e o uso de
  `AbstractContextManager` para o checkpointer.
- Build do grafo (`builder.compile(checkpointer=runtime_checkpointer())`): se o build
  for `lru_cache`, garantir que o checkpointer bound seja o `PostgresSaver(pool)` — a
  validação acontece no pool, não no saver, então cache do grafo é seguro.
- **Setup das tabelas**: manter `manage.py` (`PostgresSaver.setup()`) como fonte de
  verdade das tabelas do checkpointer. NÃO chamar `setup()` no hot path. Migrações já
  rodam no boot/deploy.
- Fechamento gracioso: registrar `_checkpointer_pool.close()` no shutdown do FastAPI
  (`lifespan`/`atexit`) para não vazar conexões em reload.

### Settings novos (`src/oryntra_agent/settings.py`)

```python
pg_pool_min_size: int = 1
pg_pool_max_size: int = 10   # casa com AGENT_MAX_CONCURRENCY_PER_ACCOUNT(5) + margem
```

Expor no stack (`.github/docker-stack.yml` + `docker-compose.prod.yml` + `.env.example`)
como `PG_POOL_MAX_SIZE` / `PG_POOL_MIN_SIZE` (opcional, com default).

## Decisões (defaults assumidos)

1. **`max_size` = 10**, `min_size` = 1 — configurável por env.
2. **Setup** continua no `manage.py`; sem `setup()` no caminho quente.
3. **Reaper Laravel de webhook events** (`processing` órfão) — **fora de escopo aqui**.
   O run deste caso foi finalizado como `failed` (não ficou órfão), e
   `agent:reap-stuck-runs` já cobre runs travados. Avaliar reaper de webhook events
   como hardening separado se aparecer evento preso em `processing`.
4. **PR dedicado** ao agent-python.

## Testes (pytest)

- `test_runtime_checkpointer_postgres_uses_pool` — com `langgraph_checkpointer=postgres`,
  retorna `PostgresSaver` ligado a um `ConnectionPool`; chamadas repetidas reusam o
  mesmo pool (singleton).
- `test_runtime_checkpointer_memory` — default retorna `InMemorySaver`.
- **Regressão da causa raiz**: simular conexão morta (fechar a conexão do pool / mockar
  `check_connection`) e confirmar que o próximo `getconn` entrega conexão válida em vez
  de propagar `OperationalError`.
- ruff limpo; suíte Python verde.

## Validação E2E (manual)

1. Subir o stack, enviar webhook → run completa.
2. Deixar o agente **ocioso > 5 min**, enviar nova mensagem → run completa (antes:
   `server closed the connection unexpectedly`).
3. (Opcional) Cortar a conexão à força no PG (`pg_terminate_backend`) e disparar run →
   pool reconecta, run completa.

## Fora de escopo

- Reaper de `chatwoot_webhook_events` presos em `processing` (hardening futuro).
- Ajustes de `retry_after`/timeouts do Horizon (não relacionados a esta falha).
- pgbouncer para o caminho do Python (checkpointer usa prepared statements — conexão
  direta é requisito).
