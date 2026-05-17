# AGENTS.md

Regras de projeto pra agentes de IA (Claude Code, Cursor, Copilot). Leitura obrigatória antes de qualquer tarefa.

## Produto

**Oryntra** — plataforma open-source de agentes LLM pra Chatwoot. Painel Laravel/Filament + runtime Python/LangGraph. Multi-tenant via `workspace_id`. Detalhes completos em `README.md`.

## Stack fixada

- **Laravel** 13 + **PHP** 8.3
- **Filament** 3 (admin painel + multi-tenancy)
- **Fortify** (auth: 2FA, recovery codes, password confirmation)
- **Shield** (roles/permissions Filament)
- **Horizon** + **Redis** (queues)
- **Reverb** (WebSocket real-time)
- **Pail + Telescope** (observabilidade dev)
- **Spatie ActivityLog** (audit)
- **Pest** (testes Laravel)
- **Pint + Larastan level 8 + Rector** (code quality)
- **Postgres** + **pgvector** (banco + embeddings)
- **MinIO** (S3-compatible storage)
- **Python** 3.12 + **FastAPI** + **LangGraph** (runtime de agente, privado)
- **uv** (gerenciador Python), **ruff** + **mypy** (Python quality), **pytest**
- **Docker Compose** (dev e prod)

## Estrutura do monorepo

```
/                     # raiz: docs, docker, configs
├── laravel/          # app Laravel
├── agent-python/     # serviço Python privado
├── docker/           # Dockerfiles e configs
├── docs/             # docs, ADRs, runbooks, OpenAPI
└── .github/          # CI workflows
```

## Comandos comuns

```bash
docker compose up -d                                # subir stack
docker compose exec laravel-app php artisan migrate
docker compose exec laravel-app php artisan make:filament-user
docker compose exec laravel-app php artisan pail   # tail logs
docker compose exec laravel-app ./vendor/bin/pest
docker compose exec laravel-app ./vendor/bin/pint
docker compose exec laravel-app ./vendor/bin/phpstan analyse
docker compose exec agent-python pytest
docker compose exec agent-python ruff check .
docker compose exec agent-python mypy src/
```

## Regras de arquitetura (críticas)

1. **Python nunca exposto publicamente.** Acessível só pela rede interna Docker via `http://agent-python:8000`. Toda chamada exige header `X-Internal-Token`.
2. **`workspace_id` em toda query de negócio.** Sem exceção. Tabela sem `workspace_id` = bug de tenancy.
3. **Tokens criptografados.** API tokens Chatwoot, chaves LLM, recovery codes — sempre cast `encrypted`.
4. **Idempotência por `chatwoot_message_id`.** Webhooks repetidos não geram resposta duplicada.
5. **Lock por `conversation_id`.** Duas respostas simultâneas pra mesma conversa = bug.
6. **Embeddings só no Python.** Laravel nunca chama API de embedding direto.
7. **BYOK (Bring Your Own Key).** Workspace cadastra própria chave OpenAI/Anthropic/Gemini. Plataforma não cobra LLM.
8. **`thread_id` do LangGraph composto:** `workspace:{ws_id}:account:{account_id}:conversation:{conv_id}`.

## Convenções de código

### PHP
- PSR-12 (enforced por Pint)
- Constructor property promotion (PHP 8)
- Return types explícitos em métodos públicos
- `config()` fora de arquivos de config, nunca `env()`
- Use `php artisan make:*` com `--no-interaction`
- `DB::transaction()` em operações multi-tabela
- Eloquent > queries cruas. Evitar facade `DB::` exceto em jobs específicos
- Eager load relations pra evitar N+1
- Datas: armazenar **UTC no banco** (Laravel default). Usar `now()` ou `Carbon::now()` sem hardcode de timezone — respeita `config('app.timezone')` do env
- Display ao usuário: converter pra timezone do `$user->timezone` ou `$workspace->timezone` no momento da renderização
- Nunca hardcoded timezone em código de negócio (projeto open-source — roda em qualquer país)

### Python
- Black-compatible (ruff format)
- Type hints obrigatórios (mypy strict)
- Async/await em endpoints FastAPI
- Pydantic models pra payloads
- Estrutura: `src/oryntra_agent/{agent,rag,media,api}/`

### Filament
- Resources em `app/Filament/Resources/`
- Tenancy: model `User implements HasTenants`, scope automático por `workspace_id`
- Policies por resource (Shield gera)
- Custom Pages em `app/Filament/Pages/`
- Forms/Tables modulares (extrair em métodos privados quando >50 linhas)

### Tratamento de erro
- Try-catch em operações de escrita (controllers, services, jobs)
- Log com contexto completo: mensagem, file, line, trace, IDs relevantes
- Mensagens user-friendly — nunca expor detalhes técnicos
- Jobs Horizon com `tries`, `backoff`, `failed()` method

### Testes
- Toda mudança tem teste
- `php artisan make:test --pest --no-interaction`
- Factories pra setup; checar states existentes antes de criar manual
- Mock Chatwoot via `Http::fake()` (Laravel) e `httpx_mock` (Python)
- Cobertura mínima: 60% (Pest `--coverage --min=60`, pytest `--cov-fail-under=60`)
- Contract tests Laravel↔Python em `tests/Contract/`

## Git workflow

- **Nunca** commit direto em `main` ou `develop`
- Branches: `feature/*`, `bugfix/*`, `hotfix/*` a partir de `develop`
- Conventional commits: `feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`
- Um feature = um commit limpo (squash se necessário)
- Merge via Pull Request, CI tem que passar
- Antes de commit: `pint --dirty`, `pest`, `phpstan analyse`

## Skills Claude Code aplicáveis

| Skill | Quando |
|---|---|
| `laravel-filament` | Antes de criar/editar Filament Resources |
| `laravel-blade` | Páginas Blade fora do Filament |
| `postgres` / `postgresql-optimization` | Migrations, índices, queries pesadas |
| `web-design-guidelines` | Review de UI antes de merge |
| `security-review` | PRs em auth, webhooks, encryption, queries tenant |
| `simplify` | Refactor de Resources e jobs grandes |
| `review` | PRs (`gh pr create` invoca) |
| `writing-plans` | Antes de cada nova fase, criar plano em `docs/tasks/` |

## Limites de comportamento (boundaries)

- Não criar arquivos sem necessidade — preferir editar existentes
- Não trocar dependências sem aprovação
- Não criar docs sem ser pedido (exceto ADRs em decisões arquiteturais)
- Não commit sem confirmação do usuário
- Não deletar testes sem aprovação
- Stick à estrutura de diretórios existente
- Checar arquivos irmãos antes de criar novos (seguir convenções)

## Glossário curto

- **workspace** — tenant. Toda operação isolada por workspace.
- **agent** — config de agente IA num workspace (prompt, model, rules, llm_key)
- **agent_run** — execução de agente em uma conversa (com tokens, custo, trace)
- **thread_id** — chave de checkpoint LangGraph: `workspace:X:account:Y:conversation:Z`
- **debounce** — agrupa mensagens picadas do cliente (janela Redis ~8s)
- **guard** — validação antes/depois do LLM ou ferramenta sensível
- **HITL** — Human-in-the-loop: pausa pra aprovação humana
- **BYOK** — Bring Your Own Key: workspace traz própria chave LLM
- **RAG** — Retrieval-Augmented Generation (busca em base de conhecimento)
- **embedding** — vetor numérico de texto pra busca semântica (pgvector)
- **Platform App Token** — token super-admin Chatwoot pra sync de accounts/users
- **api_access_token** — header de auth da Application API do Chatwoot

Detalhes em `docs/glossary.md`.
