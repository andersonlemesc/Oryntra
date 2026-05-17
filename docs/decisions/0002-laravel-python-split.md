# 0002 — Separação Laravel + Python

- **Status:** Aceito
- **Data:** 2026-05-16

## Contexto

Oryntra precisa de painel admin (CRUD-heavy, multi-tenant, auth, webhooks) **e** runtime de agente LLM (orquestração de grafo, RAG, embeddings, transcrição, vision, checkpoints). Linguagens diferentes têm forças diferentes: PHP/Laravel domina produto SaaS web; Python domina ML/IA com ecossistema LangGraph + LangChain.

## Decisão

Dividir em dois serviços:

- **Laravel** — camada de produto: painel Filament, auth Fortify, multi-tenancy, webhooks Chatwoot, queues Horizon, orquestração de jobs, persistência.
- **Python (FastAPI + LangGraph)** — runtime de agente: grafo, RAG, embeddings, transcrição, vision, checkpoints. **Privado**, só acessível via rede interna Docker.

Comunicação: HTTP interno (`http://agent-python:8000`) com header `X-Internal-Token`.

## Consequências

**Positivas:**
- Cada serviço usa stack idiomática do seu domínio (Filament em Laravel, LangGraph em Python)
- Python pode escalar horizontal independente do painel
- Mudança de provider LLM (OpenAI → Anthropic → local) só afeta Python
- Time futuro: dev Laravel e dev Python trabalham em paralelo

**Negativas:**
- Latência extra de HTTP interno (~5-20ms — aceitável pra LLM que leva segundos)
- Contrato HTTP entre os dois precisa ser versionado e testado (contract tests)
- Setup dev mais complexo (2 Dockerfiles, 2 conjuntos de deps)

## Alternativas rejeitadas

- **Tudo em PHP:** ecossistema LangGraph/RAG/Whisper em PHP é imaturo. Reinventar a roda.
- **Tudo em Python:** perde produtividade Filament + experiência do dev em Laravel.
- **Mod do Chatwoot (Ruby on Rails):** acopla ao ciclo de release do Chatwoot, fork mantenção pesada.
