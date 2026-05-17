# Arquitetura Oryntra

## Visão de alto nível

```mermaid
flowchart TB
    subgraph external[Externo]
        Customer[Cliente final]
        Chatwoot[Chatwoot]
    end

    subgraph public[Camada Pública]
        Nginx[Nginx]
        LaravelApp[Laravel App<br/>Filament + Fortify]
    end

    subgraph private[Rede Interna Docker]
        Horizon[Horizon Workers<br/>Queues]
        Reverb[Reverb<br/>WebSocket]
        Scheduler[Scheduler]
        PyAgent[Python Agent<br/>FastAPI + LangGraph]
        Postgres[(Postgres<br/>+ pgvector)]
        Redis[(Redis)]
        MinIO[(MinIO<br/>S3)]
        Mailpit[Mailpit]
    end

    Customer -->|mensagem| Chatwoot
    Chatwoot -->|webhook| Nginx
    Nginx --> LaravelApp
    LaravelApp -->|API| Chatwoot
    LaravelApp <--> Postgres
    LaravelApp <--> Redis
    LaravelApp -.->|dispatch| Horizon
    Horizon <--> Postgres
    Horizon <--> Redis
    Horizon -->|HTTP interno<br/>X-Internal-Token| PyAgent
    PyAgent <--> Postgres
    PyAgent <--> Redis
    PyAgent -->|LLM API<br/>BYOK| LLM[OpenAI/Anthropic/Gemini]
    LaravelApp <--> MinIO
    PyAgent <--> MinIO
    Reverb -->|WS| Browser[Browser admin]
    LaravelApp -.-> Mailpit
```

## Fluxo de mensagem (request lifecycle)

```mermaid
sequenceDiagram
    participant C as Cliente
    participant CW as Chatwoot
    participant L as Laravel
    participant R as Redis
    participant H as Horizon Worker
    participant P as Python Agent
    participant DB as Postgres

    C->>CW: envia mensagem
    CW->>L: POST /api/webhooks/chatwoot/{uuid}
    L->>L: valida assinatura HMAC
    L->>DB: salva message + log
    L->>R: lock conv_id + adiciona ao buffer debounce
    L-->>CW: 200 OK (imediato)
    L->>H: dispatch HandleChatwootWebhookJob

    Note over R,H: aguarda janela debounce (~8s)
    H->>R: consolida buffer
    H->>P: POST /agent/run (input + agent_config + llm_key)

    P->>DB: load checkpoint LangGraph (thread_id)
    P->>P: prepare_input → guards → retrieve RAG → llm_call → postprocess
    P->>DB: salva checkpoint + agent_logs
    P-->>H: {response, tokens, cost}

    H->>DB: persiste agent_run
    H->>L: dispatch SendChatwootMessageJob
    L->>CW: POST /api/v1/accounts/{id}/conversations/{cid}/messages
    L->>R: libera lock
```

## Separação Laravel ↔ Python

| Responsabilidade | Laravel | Python |
|---|---|---|
| Painel admin (CRUD) | ✅ Filament | ❌ |
| Auth + tenancy | ✅ Fortify + Shield | ❌ |
| Webhook receiver | ✅ | ❌ |
| Queue orchestration | ✅ Horizon | ❌ |
| Debounce + locks | ✅ Redis | ❌ |
| Send msg Chatwoot | ✅ via API | ❌ |
| Sync Chatwoot accounts | ✅ Platform API | ❌ |
| LangGraph runtime | ❌ | ✅ |
| LLM calls | ❌ | ✅ (BYOK) |
| Embeddings | ❌ | ✅ |
| RAG search | ❌ | ✅ |
| Document parsing | ❌ | ✅ |
| Audio transcription | ❌ | ✅ |
| Image vision | ❌ | ✅ |
| Checkpoints LangGraph | ❌ | ✅ (escreve em Postgres) |
| Memória estruturada | ✅ tabelas + jobs | leitura via tool |
| Memória semântica (RAG) | ❌ | ✅ pgvector |

## Comunicação Laravel ↔ Python

- Protocolo: HTTP/1.1 interno (Docker network `oryntra-net`)
- Endpoint: `http://agent-python:8000`
- Auth: header `X-Internal-Token: $INTERNAL_API_TOKEN`
- Timeout: 60s (LLM pode demorar)
- Retry: Horizon faz retry no job, Python idempotente via `agent_run_id`

## Estratégia de checkpoints LangGraph

- Lib: `langgraph-checkpoint-postgres`
- Tabela: `langgraph_checkpoints` (gerenciada pela lib)
- `thread_id`: `workspace:{ws_id}:account:{account_id}:conversation:{conv_id}`
- Permite HITL (Fase 6): pausa execução, retoma após aprovação humana

## Multi-tenancy

- Toda tabela de negócio tem `workspace_id` + índice composto
- Filament tenancy automatiza scope nas Resources
- Jobs/services manuais precisam usar `Workspace::scope()` ou filtrar manualmente
- RAG search no Python filtra `workspace_id` na query pgvector
- LangGraph checkpoints incluem `workspace_id` no `thread_id`

## Escalabilidade

| Componente | Estratégia |
|---|---|
| Laravel app | Horizontal: várias instâncias atrás de load balancer |
| Horizon workers | Horizontal: aumentar supervisors por fila pesada |
| Python agent | Horizontal: stateless, escala com volume de runs |
| Postgres | Vertical inicial; sharding por workspace só em escala extrema |
| Redis | Sentinel/Cluster em escala alta |
| MinIO | Distributed em produção real |

## Observabilidade

- **Pail** (terminal): tail de logs em dev
- **Telescope** (web): debug detalhado de requests/queries/jobs em dev (off em prod)
- **Horizon** (web): dashboard de queues
- **Reverb logs**: WebSocket connections
- **agent_runs / agent_logs**: trace por execução visível no Filament
- Adiar: Pulse, Sentry, Langfuse (avaliar quando houver volume real)
