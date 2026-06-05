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

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v5
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- livewire/livewire (LIVEWIRE) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/dusk (DUSK) - v8
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/telescope (TELESCOPE) - v5
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- rector/rector (RECTOR) - v2

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
