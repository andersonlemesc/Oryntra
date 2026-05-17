# Checkpoint de implementacao - 2026-05-17

Este documento registra onde o desenvolvimento parou em 2026-05-17 11:08 -03.

## Estado geral

O projeto esta com a base Laravel/Filament funcional em Docker, auth Fortify ajustado, tenancy por workspace criada e a primeira entidade real de integracao Chatwoot implementada.

Ainda nao houve commit dessas alteracoes.

## Infra e runtime

- Docker Laravel ajustado para PHP 8.4, alinhado ao composer atual.
- `composer.json` e `composer.lock` atualizados para PHP 8.4.
- `docker-compose.yml` ajustado para Horizon, Scheduler e Reverb nao herdarem healthcheck incorreto de FPM.
- `agent-python` recebeu `PYTHONPATH=/app/src`.
- Containers subiram e migrations foram aplicadas no Postgres local.

Arquivos principais:

- `docker/laravel/Dockerfile`
- `docker/agent-python/Dockerfile`
- `docker-compose.yml`
- `laravel/composer.json`
- `laravel/composer.lock`

## Auth Fortify

Fortify foi configurado para fornecer as telas publicas de autenticacao usadas antes do painel Filament.

Implementado:

- Login
- Cadastro
- Esqueci senha
- Reset de senha
- Confirmacao de senha
- Two-factor challenge
- Verificacao de e-mail

Arquivos principais:

- `laravel/app/Providers/FortifyServiceProvider.php`
- `laravel/config/fortify.php`
- `laravel/resources/views/auth/*.blade.php`
- `laravel/resources/views/components/auth/layout.blade.php`
- `laravel/tests/Feature/FortifyViewsTest.php`

## Tenancy por workspace

Foi criada a base multi-tenant. No Oryntra, `workspace` representa o ambiente isolado de operacao: conexoes Chatwoot, agentes, prompts, documentos, execucoes e chaves ficam vinculados ao `workspace_id`.

Hierarquia atual:

```text
Organization
└── Workspace
    ├── Chatwoot Connections
    ├── Agents
    ├── Prompts
    ├── Documents
    └── Agent Runs
```

Implementado:

- Model `Organization`
- Model `Workspace`
- Pivot `workspace_members`
- `User` implementando tenancy Filament
- Registro de workspace no painel Filament
- Admin panel com `tenant(Workspace::class)`

Arquivos principais:

- `laravel/app/Models/Organization.php`
- `laravel/app/Models/Workspace.php`
- `laravel/app/Models/User.php`
- `laravel/app/Providers/Filament/AdminPanelProvider.php`
- `laravel/app/Filament/Pages/Tenancy/RegisterWorkspace.php`
- `laravel/database/migrations/2026_05_17_030441_create_organizations_table.php`
- `laravel/database/migrations/2026_05_17_030441_create_workspaces_table.php`
- `laravel/database/migrations/2026_05_17_030442_create_workspace_members_table.php`
- `laravel/tests/Feature/WorkspaceTenancyTest.php`

## Chatwoot connection base

Foi implementada a base para cada workspace cadastrar uma conexao Chatwoot. Esta fase nao implementa webhook nem chamadas reais para a API do Chatwoot.

Implementado:

- Tabela `chatwoot_connections`
- `workspace_id` obrigatorio
- FK para `workspaces.id` com cascade on delete
- `connection_uuid` publico e unico para futuro webhook
- `name`
- `base_url`
- `account_id`
- `api_access_token` com cast `encrypted`
- `webhook_secret` nullable com cast `encrypted`
- `status` com valores `active` e `inactive`
- PK, FK, uniques, indice por tenant/status e check constraint no Postgres
- Model `ChatwootConnection`
- Enum `ChatwootConnectionStatus`
- Factory
- Helper `chatwootHeaders()`
- Relacao `Workspace::chatwootConnections()`
- Filament Resource tenant-scoped
- Testes de encryption, factory, FK, helper e isolamento por tenant

Arquivos principais:

- `laravel/database/migrations/2026_05_17_032629_create_chatwoot_connections_table.php`
- `laravel/app/Models/ChatwootConnection.php`
- `laravel/app/Enums/ChatwootConnectionStatus.php`
- `laravel/database/factories/ChatwootConnectionFactory.php`
- `laravel/app/Models/Workspace.php`
- `laravel/app/Filament/Resources/ChatwootConnections/ChatwootConnectionResource.php`
- `laravel/app/Filament/Resources/ChatwootConnections/Schemas/ChatwootConnectionForm.php`
- `laravel/app/Filament/Resources/ChatwootConnections/Tables/ChatwootConnectionsTable.php`
- `laravel/app/Filament/Resources/ChatwootConnections/Pages/ListChatwootConnections.php`
- `laravel/app/Filament/Resources/ChatwootConnections/Pages/CreateChatwootConnection.php`
- `laravel/app/Filament/Resources/ChatwootConnections/Pages/EditChatwootConnection.php`
- `laravel/tests/Feature/ChatwootConnectionTest.php`
- `laravel/tests/Feature/ChatwootConnectionResourceTest.php`

## Verificacoes executadas

Comandos executados com sucesso:

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
docker compose exec laravel-app php artisan test --compact
docker compose exec laravel-app ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec laravel-app php artisan migrate --force
```

Resultado dos testes:

- 18 testes passaram
- 49 assertions
- PHPStan sem erros
- Pint passou

Verificacao direta no Postgres confirmou:

- PK `chatwoot_connections_pkey`
- FK `chatwoot_connections_workspace_id_foreign`
- Unique `chatwoot_connections_connection_uuid_unique`
- Unique por tenant `workspace_id + name`
- Unique por tenant `workspace_id + base_url + account_id`
- Indice `workspace_id + status`
- Check constraint `status IN ('active', 'inactive')`
- `api_access_token` e `webhook_secret` como `text`

## Fora de escopo ainda nao implementado

- Webhook receiver do Chatwoot
- Validacao de webhook secret
- Idempotencia por `chatwoot_message_id`
- Lock por `conversation_id`
- Jobs Horizon para processar mensagens
- Envio real de mensagens ao Chatwoot
- Sync de accounts, inboxes ou contatos
- Runtime Python/LangGraph integrado
- Agents, prompts, documents/RAG e agent runs
- Filament Shield aplicado aos novos resources

## Proximo passo recomendado

Implementar a fase de webhook Chatwoot:

1. Criar rota publica com `connection_uuid`.
2. Resolver `ChatwootConnection` por `connection_uuid`.
3. Validar `webhook_secret`.
4. Persistir payload bruto de webhook em tabela com `workspace_id`.
5. Garantir idempotencia por `chatwoot_message_id`.
6. Despachar job Horizon para processamento.
7. Adicionar lock por `conversation_id`.
8. Testar com `Http::fake()` e payloads de exemplo.

Antes dessa fase, revisar a documentacao em `docs/integrations/chatwoot/README.md`.

## Observacoes importantes

- `AGENTS.md` ainda menciona Laravel/Filament antigos em alguns trechos, mas o Boost/runtime atual confirmou Laravel 13, PHP 8.4 e Filament 5.
- O worktree esta sujo por design: as alteracoes ainda nao foram commitadas.
- Nao foi feita chamada real a API externa.
- Nao foi criado commit.
