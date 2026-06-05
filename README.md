<div align="center">

# Oryntra

**Plataforma de agentes de IA para o Chatwoot.**

Camada de produto em Laravel + runtime de IA em Python/LangGraph, com RAG, memória,
multiempresa e integração nativa com o Chatwoot via webhook e API.

[![CI Laravel](https://github.com/andersonlemesc/Oryntra/actions/workflows/ci-laravel.yml/badge.svg)](https://github.com/andersonlemesc/Oryntra/actions/workflows/ci-laravel.yml)
[![CI Python](https://github.com/andersonlemesc/Oryntra/actions/workflows/ci-python.yml/badge.svg)](https://github.com/andersonlemesc/Oryntra/actions/workflows/ci-python.yml)
[![CI Security](https://github.com/andersonlemesc/Oryntra/actions/workflows/ci-security.yml/badge.svg)](https://github.com/andersonlemesc/Oryntra/actions/workflows/ci-security.yml)
[![License: Apache 2.0](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](LICENSE)

</div>

---

## O que é

O Oryntra adiciona uma camada de **agentes de IA** ao atendimento no Chatwoot, sem ser um
fork. O Chatwoot continua como inbox/canal; o Oryntra cuida de prompts, RAG, memória,
regras, debounce, mídia, multiempresa e da orquestração com modelos de IA.

- **Aplicação principal (Laravel):** painel admin (Filament), autenticação, multi-tenancy,
  webhooks do Chatwoot, configuração de agentes e jobs de orquestração.
- **Runtime de IA (Python):** serviço **privado** (nunca exposto à internet) com LangGraph,
  RAG, embeddings, transcrição e checkpoints.
- **Dados:** Postgres + pgvector. **Fila/cache/locks:** Redis. **Storage:** MinIO/S3.

Documento completo de visão e arquitetura: [`docs/visao-e-arquitetura.md`](docs/visao-e-arquitetura.md).

## Principais recursos

- 🤖 **Agentes configuráveis** — modo automático ou copiloto (sugestão como nota privada).
- 🧠 **Memória de longo prazo** por contato, com classificação de tipo (preferência, fato, restrição…).
- 📚 **RAG** sobre base de conhecimento por workspace (documentos, produtos).
- 🔀 **Roteamento supervisor → especialista** (LangGraph StateGraph).
- 🙋 **Human-takeover lock** — quando um humano assume a conversa, o bot para de responder até resolver.
- 🔑 **BYOK** — cada workspace usa a própria chave OpenAI/Anthropic/Gemini.
- 🏢 **Multiempresa** — `workspace_id` em toda query de negócio; isolamento por tenant.
- 🔁 **Idempotência e locks** por `chatwoot_message_id` e `conversation_id`.

## Stack

| Camada | Tecnologia |
|--------|-----------|
| Control plane | PHP 8.4, Laravel 13, Filament 5, Horizon, Reverb |
| Runtime IA | Python 3.12, FastAPI, LangGraph |
| Banco | PostgreSQL 16 + pgvector |
| Fila / cache | Redis |
| Storage | MinIO / S3 |
| Infra | Docker Compose, Nginx, PgBouncer |

## Arquitetura (resumo)

```
Internet / Chatwoot
        |
        v
  Laravel (público)            rede interna Docker         Python (privado)
  painel, auth, webhooks  ───────────────────────────►  LangGraph, RAG,
  jobs de orquestração      X-Internal-Token apenas       embeddings, checkpoints
        |                                                       |
        +───────────────► Postgres + pgvector ◄────────────────+
                          Redis · MinIO/S3
```

Regras de arquitetura críticas (tenancy, segredos criptografados, Python privado, locks) em
[`AGENTS.md`](AGENTS.md).

## Quick start (desenvolvimento)

Pré-requisitos: Docker + Docker Compose.

```bash
# 1. Configurar ambiente
cp .env.example .env
# edite .env e preencha os segredos necessários

# 2. Subir a stack
docker compose up -d

# 3. Gerar APP_KEY e migrar
docker compose exec laravel-app php artisan key:generate
docker compose exec laravel-app php artisan migrate

# 4. Criar usuário admin do painel
docker compose exec laravel-app php artisan make:filament-user
```

Painel: `http://localhost:8080/admin` · Mailpit (e-mails de dev): `http://localhost:8026`.

## Testes e qualidade

```bash
# Laravel — testes rodam em Postgres (banco oryntra_test)
docker compose exec laravel-app ./vendor/bin/pest
docker compose exec laravel-app ./vendor/bin/pint          # formatação
docker compose exec laravel-app ./vendor/bin/phpstan analyse   # análise estática (level 8)

# Python
docker compose exec agent-python pytest
docker compose exec agent-python ruff check .
docker compose exec agent-python mypy src/
```

CI roda os três pipelines a cada push/PR: **Laravel** (Pint, Larastan, Pest), **Python**
(Ruff, mypy, pytest) e **Security** (composer audit, pip-audit, TruffleHog, Gitleaks).

## Estrutura do monorepo

```
/
├── laravel/          # app Laravel (painel, webhooks, jobs)
├── agent-python/     # serviço Python privado (LangGraph, RAG)
├── docker/           # Dockerfiles e configs
├── docs/             # visão, arquitetura, ADRs, integrações, runbooks
└── .github/          # CI/CD workflows
```

## Deploy

Self-hosted via Docker Compose. Overlay de produção em
[`docker-compose.prod.yml`](docker-compose.prod.yml). Topologia:

- **Postgres e Redis gerenciados** (externos) — env aponta para os hosts gerenciados.
- **Traefik externo** termina TLS e roteia por `Host`; `nginx` e `reverb` (websocket no
  path `/app`) recebem labels Traefik e nada é publicado direto no host.
- **MinIO interno** — só na rede Docker.

```bash
cp .env.production.example .env   # edite segredos, domínio e hosts gerenciados
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d \
  nginx laravel-app laravel-horizon laravel-scheduler laravel-reverb \
  agent-python minio
```

Deploy automatizado por SSH ao publicar um release: ver [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml).

### Instalação via Docker Swarm

Para quem só quer instalar o sistema a partir das imagens publicadas (Docker Hub
`andersonlemes/oryntra-*`), há um stack Swarm de exemplo em
[`docker-stack.yml`](docker-stack.yml) — apenas a aplicação, com Postgres, Redis e S3
externos e Traefik na borda:

```bash
cp .env.stack.example .env && nano .env
set -a && . ./.env && set +a
docker stack deploy -c docker-stack.yml oryntra
```

## Contribuição e segurança

- Como contribuir: [`CONTRIBUTING.md`](CONTRIBUTING.md)
- Reportar vulnerabilidade: [`SECURITY.md`](SECURITY.md)
- Código de conduta: [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md)

## Licença

[Apache License 2.0](LICENSE) © 2026 Anderson Lemes. Ver também [`NOTICE`](NOTICE).
"Oryntra" e o logo são marcas de Anderson Lemes; a licença não concede direito de uso das
marcas (Seção 6 da Apache 2.0).
