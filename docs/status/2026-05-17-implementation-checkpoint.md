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

## Atualizacao - 2026-05-17 12:38 -03

Foi implementado e validado o fluxo de convites para usuarios sincronizados do Chatwoot.

Motivo:

- Usuarios sincronizados pelo Chatwoot eram criados com senha aleatoria.
- Sem convite ou reset de senha, esses usuarios nao tinham como logar.
- A abordagem escolhida foi invitation flow dedicado, nao password reset reaproveitado.

Implementado:

- Config `config/invitations.php`
- Tabela `user_invitations`
- Coluna `users.last_invitation_sent_at`
- Model `UserInvitation`
- Action `SendUserInvitation`
- Notification `UserInvitationNotification`
- Controller `InvitationController`
- View `resources/views/auth/accept-invitation.blade.php`
- Rotas:
  - `GET /accept-invitation/{token}`
  - `POST /accept-invitation/{token}`
- Sync Chatwoot agora dispara convite automaticamente apenas para usuarios novos, quando `invitations.send_on_sync` esta ativo.
- Notification implementa `ShouldQueue` e usa a fila `emails`.
- Foi necessario reiniciar Horizon com `php artisan horizon:terminate` para carregar o codigo/config novo.

Arquivos principais:

- `laravel/config/invitations.php`
- `laravel/database/migrations/2026_05_17_060000_create_user_invitations_table.php`
- `laravel/app/Models/UserInvitation.php`
- `laravel/app/Actions/Invitations/SendUserInvitation.php`
- `laravel/app/Notifications/UserInvitationNotification.php`
- `laravel/app/Http/Controllers/Auth/InvitationController.php`
- `laravel/resources/views/auth/accept-invitation.blade.php`
- `laravel/app/Jobs/Chatwoot/SyncChatwootAccountsJob.php`
- `laravel/app/Models/User.php`
- `laravel/routes/web.php`
- `laravel/tests/Feature/Invitations/InvitationFlowTest.php`
- `laravel/tests/Feature/Chatwoot/SyncInvitationsTest.php`

Estado validado:

- Sync com usuarios novos criou 11 usuarios.
- Sync com usuarios novos criou 11 convites.
- `last_invitation_sent_at` foi preenchido para 11 usuarios.
- Notification foi enfileirada em `emails` e processada pelo Horizon.
- A fila `emails` pode aparecer zerada depois do teste porque o supervisor processa rapidamente.
- Se rodar sync novamente sem limpar usuarios, `invites_sent` pode aparecer `0`, pois o convite automatico so dispara para usuarios novos.

Estado atual do banco durante este checkpoint:

- `users` nao-super-admin: 11
- `user_invitations`: 11
- usuarios com `last_invitation_sent_at`: 11
- ultimo summary consultado mostrou `invites_sent: 0` porque os usuarios ja existiam nessa execucao posterior.

Rota raiz e convite:

- `/` agora redireciona:
  - guest -> `/login`
  - autenticado -> `/admin`
- `/accept-invitation/{token}` nao usa mais middleware `guest`.
- Isso permite abrir link de convite mesmo se houver outra sessao ativa no navegador.
- O link de convite foi testado e retornou `200 OK`.

Verificacoes executadas depois desses ajustes:

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
docker compose exec laravel-app php artisan test --compact
docker compose exec laravel-app ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
docker compose exec laravel-app php artisan migrate --force
```

Resultado mais recente:

- 45 testes passaram
- 136 assertions
- PHPStan sem erros
- Pint passou
- Migrations sem pendencia

Proximo passo recomendado atualizado:

1. Testar manualmente um convite real ate o fim:
   - abrir email no Mailpit
   - acessar link
   - definir senha
   - confirmar login no Filament
2. Depois seguir para webhook receiver Chatwoot.

## Atualizacao - 2026-05-17 16:45 -03

Foi implementada, testada e mergeada localmente em `develop` a fase de conexao Chatwoot baseada em Agent Bot + webhook receiver.

Commits locais:

- `857cd67 feat: provision Chatwoot agent bot connections`
- `58d2f91 merge: chatwoot agent bot webhooks`

Estado Git:

- Branch atual: `develop`
- `develop` esta 2 commits a frente de `origin/develop`
- Branch temporaria `feature/chatwoot-agent-bot-webhooks` foi removida localmente apos merge
- Worktree limpo no momento do merge

### Webhook receiver Chatwoot

Implementado:

- Rota publica `POST /api/webhooks/chatwoot/{connectionUuid}`
- Arquivo `routes/api.php` registrado no bootstrap da aplicacao
- Controller `ChatwootWebhookController`
- Resolucao da conexao por `connection_uuid`
- Rejeicao de conexao inexistente ou inativa
- Validacao opcional de assinatura:
  - se `webhook_secret` estiver salvo, exige header `X-Chatwoot-Signature`
  - se `webhook_secret` estiver vazio, aceita pelo UUID da URL
- Validacao de `account_id` do payload contra `chatwoot_connections.account_id`
- Persistencia do payload bruto em `chatwoot_webhook_events`
- `workspace_id` salvo no evento recebido
- Idempotencia por `chatwoot_connection_id + chatwoot_message_id`
- Job `ProcessChatwootWebhookEventJob` na fila `chatwoot-webhooks`
- Lock por conversa via `Cache::lock`
- Campo `payload` convertido para `jsonb` no Postgres

Arquivos principais:

- `laravel/routes/api.php`
- `laravel/bootstrap/app.php`
- `laravel/app/Http/Controllers/ChatwootWebhookController.php`
- `laravel/app/Models/ChatwootWebhookEvent.php`
- `laravel/app/Jobs/Chatwoot/ProcessChatwootWebhookEventJob.php`
- `laravel/database/migrations/2026_05_17_154246_create_chatwoot_webhook_events_table.php`
- `laravel/database/migrations/2026_05_17_154533_alter_chatwoot_webhook_events_payload_to_jsonb.php`
- `laravel/tests/Feature/Chatwoot/ChatwootWebhookReceiverTest.php`

### Provisionamento de Agent Bot

Fluxo definido:

- Usuario cria uma conexao Chatwoot no workspace atual.
- Usuario nao informa `account_id`, token nem webhook secret.
- `account_id` vem do `workspaces.chatwoot_account_id`, criado pelo sync Platform.
- Oryntra usa o Platform Token configurado para criar um Agent Bot no Chatwoot.
- Chatwoot retorna `access_token` do bot.
- Oryntra salva esse token criptografado em `chatwoot_connections.api_access_token`.
- Esse token sera usado futuramente para responder mensagens pela Application API.
- O Agent Bot envia eventos para a URL de webhook gerada pelo Oryntra.

Implementado:

- Config `config/chatwoot.php`
- Env `CHATWOOT_WEBHOOK_BASE_URL`
- Action `ProvisionChatwootAgentBot`
- Job `ProvisionChatwootAgentBotJob` na fila `chatwoot-sync`
- Metodo `ChatwootPlatformClient::createAgentBot()`
- Campos em `chatwoot_connections`:
  - `agent_bot_id`
  - `agent_bot_outgoing_url`
  - `provisioned_at`
  - `provisioning_started_at`
  - `provisioning_error`
- `api_access_token` agora pode ser nullable enquanto o bot esta sendo provisionado.
- Criacao de segunda conexao para a mesma conta/workspace e bloqueada.
- Provisionamento e idempotente: conexao ja provisionada nao cria outro bot.

Arquivos principais:

- `laravel/config/chatwoot.php`
- `laravel/app/Actions/Chatwoot/ProvisionChatwootAgentBot.php`
- `laravel/app/Jobs/Chatwoot/ProvisionChatwootAgentBotJob.php`
- `laravel/app/Services/Chatwoot/ChatwootPlatformClient.php`
- `laravel/database/migrations/2026_05_17_160442_add_agent_bot_fields_to_chatwoot_connections_table.php`
- `laravel/tests/Feature/Chatwoot/ProvisionChatwootAgentBotTest.php`

### Exclusao de conexao

Implementado:

- Ao excluir `ChatwootConnection`, o model dispara `DeleteChatwootAgentBotJob`.
- O job remove o Agent Bot no Chatwoot via Platform API:
  - `DELETE /platform/api/v1/agent_bots/{id}`
- `404` e tratado como sucesso idempotente, pois o bot ja pode ter sido removido.
- O disparo pelo evento `deleting` cobre exclusao pela tela de edicao e bulk delete.

Arquivos principais:

- `laravel/app/Actions/Chatwoot/DeleteChatwootAgentBot.php`
- `laravel/app/Jobs/Chatwoot/DeleteChatwootAgentBotJob.php`
- `laravel/app/Models/ChatwootConnection.php`
- `laravel/tests/Feature/Chatwoot/DeleteChatwootAgentBotTest.php`

### UI Filament da conexao

A tela de conexoes foi ajustada para refletir o fluxo real:

- Na criacao, usuario informa apenas `name` e `status`.
- `base_url`, `account_id`, tokens e webhook URL sao derivados pelo backend.
- Na edicao, apenas `status` e persistido.
- `name` fica somente leitura na edicao para evitar divergencia com o nome do Agent Bot no Chatwoot.
- `agent_bot_id`, webhook URL e status de `webhook_secret` sao informativos.
- Campo `Erro de provisionamento` so aparece quando ha erro salvo.

Arquivos principais:

- `laravel/app/Filament/Resources/ChatwootConnections/Pages/CreateChatwootConnection.php`
- `laravel/app/Filament/Resources/ChatwootConnections/Pages/EditChatwootConnection.php`
- `laravel/app/Filament/Resources/ChatwootConnections/Schemas/ChatwootConnectionForm.php`
- `laravel/app/Filament/Resources/ChatwootConnections/Tables/ChatwootConnectionsTable.php`

### Validacao pratica com Chatwoot

Foi testado manualmente:

- Criacao de conexao pelo Oryntra
- Criacao de Agent Bot no Chatwoot via Platform API
- URL gerada no Chatwoot apontando para Oryntra:
  - `http://host.docker.internal:8080/api/webhooks/chatwoot/{connection_uuid}`
- Token de acesso do Agent Bot salvo no Oryntra e criptografado no banco
- Token local bate com o token retornado pela Platform API
- Criacao de bot temporario confirmou que a Platform API retorna:
  - `id`
  - `name`
  - `description`
  - `outgoing_url`
  - `account_id`
  - `access_token`
- A Platform API nao retorna `webhook_secret`.

Decisao atual:

- `webhook_secret` permanece opcional.
- Se o Chatwoot nao expuser o secret via API, o Oryntra aceita webhook pelo `connection_uuid`.
- Isso e suficiente para MVP/dev, pois o UUID na URL tem alta entropia.
- Em hardening futuro, transformar a validacao em middleware e/ou permitir configuracao manual do secret por super admin.

Estado runtime consultado:

- Conexao ativa:
  - workspace: `andersonlemes`
  - connection id: `2`
  - agent_bot_id: `2`
  - token salvo: sim
  - secret salvo: nao
  - status: `active`
  - erro de provisionamento: `null`
- `chatwoot_webhook_events`: 0
- Jobs pendentes: 0
- Failed jobs: 1 antigo de sync (`2026-05-17 14:57:54`, antes desta fase)

### Verificacoes executadas

Comandos executados antes do commit/merge:

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
docker compose exec laravel-app php artisan test --compact
docker compose exec laravel-app ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

Resultado:

- 58 testes passaram
- 181 assertions
- PHPStan sem erros
- Pint passou

### Proximo passo recomendado

1. Disparar um evento real do Chatwoot para confirmar entrada em `chatwoot_webhook_events`.
2. Transformar a validacao/resolucao do webhook em middleware.
3. Implementar o processamento real do evento:
   - ignorar mensagens outbound/bot
   - filtrar apenas eventos uteis
   - manter lock por `conversation_id`
4. Definir contrato Laravel -> Python:
   - workspace
   - account
   - conversation
   - message
   - thread_id LangGraph
5. Implementar chamada interna para `agent-python`.
6. Implementar resposta ao Chatwoot usando o token do Agent Bot.
