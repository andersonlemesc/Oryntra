# Oryntra — Aplicação Laravel (painel + API)

Camada de **produto** do Oryntra: painel administrativo, autenticação, multiempresa,
integração com o Chatwoot (webhook + Platform/Admin API), filas, websocket e a **API
pública `/api/v1`** consumida pelo [`@oryntra/mcp`](../packages/oryntra-mcp).

> Visão geral do projeto (Laravel + runtime Python/LangGraph, deploy, etc.) no
> [README raiz](../README.md). Regras canônicas para agentes em [AGENTS.md](../AGENTS.md).

## Stack

- **PHP 8.4** · **Laravel 13**
- **Filament v5** — painel admin (recursos, páginas, widgets)
- **Fortify** — autenticação headless · **Sanctum** — tokens de API (`ApiToken`)
- **Horizon** — filas Redis · **Reverb** — websocket (broadcast)
- **PostgreSQL + pgvector** — banco + embeddings (RAG)
- Qualidade: **Pest** (testes), **Larastan/PHPStan** (análise estática), **Pint** (estilo),
  **Telescope** (dev), **Laravel Boost** (MCP de dev)

## O que faz

- **Painel (Filament):** agentes, especialistas, chaves LLM (BYOK), catálogo, base de
  conhecimento (RAG), conexões Chatwoot/Google Calendar, workspaces.
- **Multiempresa:** cada conta Chatwoot vira um `Workspace`; todo recurso é escopado.
- **Chatwoot:** recebe webhooks (`ResolveChatwootWebhookConnection`), gerencia contas via
  Platform API (`ChatwootPlatformClient`) e conteúdo via Admin API (`ChatwootAdminApiClient`).
- **Orquestração de IA:** despacha runs pro runtime Python (`agent-python`) e recebe o
  resultado de volta por um token interno.
- **API `/api/v1`:** `auth:sanctum` + abilities por rota, escopada a um workspace
  (`api.workspace`). É a superfície usada pelo MCP.

## Estrutura

```
app/
  Filament/        # painel: Resources, Pages, Widgets
  Http/            # Controllers, Middleware (webhook, internal runtime, workspace)
  Jobs/Chatwoot/   # sync de contas/usuários, webhooks
  Services/Chatwoot/  # clients Platform/Admin/AgentBot
  Models/          # Workspace, ApiToken, ChatwootConnection, …
routes/
  api.php          # /api/v1 (Sanctum + abilities)
  web.php, channels.php, console.php
bootstrap/app.php  # middleware (trustProxies/trustHosts), providers
```

## Desenvolvimento

A forma recomendada é subir a stack pela **raiz do repositório** (Postgres, Redis, MinIO,
nginx, agent-python juntos):

```bash
# na raiz do repo
docker compose up -d
```

Dentro de `laravel/`, fluxo local (precisa de PHP 8.4 + Node 22 + Postgres/Redis):

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
composer run dev      # serve + queue + pail + vite, juntos
```

## Qualidade (rode antes de commitar)

```bash
vendor/bin/pint                 # estilo (corrige)
vendor/bin/phpstan analyse      # análise estática (Larastan)
php artisan test --compact      # Pest
```

## Licença

Apache-2.0 — ver [LICENSE](../LICENSE).
