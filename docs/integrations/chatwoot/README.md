# Integração Chatwoot

Guia consolidado pra integrar Oryntra com Chatwoot. Specs OpenAPI baixadas neste diretório.

## Specs disponíveis

| Arquivo | Cobertura | Tamanho |
|---|---|---|
| `openapi-platform.json` | Platform API (super-admin) | ~200K |
| `openapi-application.json` | Application API (account/user) | ~380K |
| `openapi-full.json` | Tudo consolidado | ~420K |

Fonte: `https://github.com/chatwoot/chatwoot/tree/develop/swagger`. Reatualizar periodicamente.

## Tipos de token (3)

### 1. User Access Token
- **Onde gerar:** `Profile Settings` no Chatwoot (após login do user)
- **Header:** `api_access_token: <token>`
- **Escopo:** ações em nome daquele user específico
- **Uso Oryntra:** **principal** — workspace cadastra esse token em `ChatwootConnectionResource` pra enviar mensagens como o user "bot"

### 2. Account API Key
- **Onde gerar:** Settings → Account Settings (cada account Chatwoot)
- **Header:** `api_access_token: <token>`
- **Escopo:** account-level (não usuário específico)
- **Uso Oryntra:** alternativa ao User Access Token quando preferir token de conta

### 3. Platform App Token (super-admin)
- **Onde gerar:** Super Admin Console → Platform Apps (só self-hosted)
- **Header:** `api_access_token: <token>`
- **Escopo:** instance-wide (cria/lista/atualiza accounts, users, account_users)
- **Uso Oryntra:** env `CHATWOOT_PLATFORM_TOKEN` global — usado por `SyncChatwootAccountsJob` pra provisionar workspaces baseado em accounts Chatwoot

## Base URLs

```
Application API:  {BASE}/api/v1/accounts/{account_id}/...
Platform API:     {BASE}/platform/api/v1/...
Public/Client:    {BASE}/public/api/v1/inboxes/{identifier}/...
```

`{BASE}` em dev local com Chatwoot Docker: `http://host.docker.internal:3000` (acessível dos containers Oryntra).

## Webhook events (12)

Configurar webhook em Chatwoot apontando pra `POST {ORYNTRA_BASE}/api/webhooks/chatwoot/{connection_uuid}`.

| Evento | Descrição | Crítico pro Oryntra |
|---|---|---|
| `message_created` | Nova mensagem em conversa | ✅ — gatilho do agente |
| `message_updated` | Mensagem editada | ✅ — reprocessar se incoming |
| `conversation_created` | Nova conversa aberta | ✅ — inicializa thread |
| `conversation_updated` | Conversa atualizada (status/atribuição) | ✅ — detectar atribuição a humano |
| `conversation_status_changed` | Status mudou (open/resolved/pending) | ✅ — pausar agente se resolved |
| `contact_created` | Novo contato | opcional — preencher customer_memory |
| `contact_updated` | Contato editado | opcional |
| `inbox_created` | Novo inbox | opcional — pra sync |
| `inbox_updated` | Inbox editado | opcional |
| `webwidget_triggered` | Widget de chat aberto | não usado MVP |
| `conversation_typing_on` | Cliente digitando | não usado MVP |
| `conversation_typing_off` | Cliente parou de digitar | não usado MVP |

### Payload base (todos eventos)
```json
{
  "event": "message_created",
  "account": { "id": 1, "name": "..." },
  ...
}
```

### Validação de webhook
Chatwoot envia HMAC via header `X-Chatwoot-Signature` quando `secret` está configurado em `webhooks` table. Validar:
```php
$signature = hash_hmac('sha256', $payload, $secret);
// comparar com $request->header('X-Chatwoot-Signature')
```

## Schema relevante (Chatwoot DB)

Resumo das tabelas que importam pra integração. Schema completo em [Chatwoot source](https://github.com/chatwoot/chatwoot/blob/develop/db/schema.rb).

### accounts
- `id`, `name`, `status`, `locale`, `feature_flags`
- 1 account = 1 tenant Chatwoot. Mapeia pra `chatwoot_connections.account_id` no Oryntra.

### users
- `id`, `email`, `name`, `display_name`, `pubsub_token`
- 2FA: `otp_secret`
- Auth: `encrypted_password`

### access_tokens
- Polimórfico: `owner_type` (User|Account), `owner_id`
- `token` (unique) — usado no header `api_access_token`

### account_users
- `account_id` + `user_id` (relação N:N)
- `role` (admin|agent), `custom_role_id`
- Unique: `uniq_user_id_per_account_id`

### inboxes
- `account_id`, `name`, `channel_id`, `channel_type`
- Auto-assign config: `auto_assignment_config`

### conversations
- `account_id`, `inbox_id`, `contact_id`, `assignee_id`
- `uuid` (público), `display_id` (sequencial por account)
- `status` (0=open, 1=resolved, 2=pending, 3=snoozed)

### messages
- `account_id`, `conversation_id`, `content`, `source_id`
- `sender_type` + `sender_id` (polimórfico: Contact, User, AgentBot)
- `message_type` (0=incoming, 1=outgoing, 2=activity, 3=template)
- Webhook só dispara se `message.webhook_sendable?` retornar true

### contacts
- `account_id`, `email`, `phone_number`, `identifier`, `name`
- Unique: `uniq_email_per_account_contact`, `uniq_identifier_per_account_contact`

### attachments
- `account_id`, `message_id`
- `external_url`, `file_type` (image|audio|video|file), `extension`
- `meta` (JSON)

### webhooks
- `account_id`, `url`, `secret`, `subscriptions` (JSON array de eventos)
- `webhook_type`: account-level (todos events da account) ou inbox-specific

### channel_api
- `account_id`, `webhook_url`, `identifier`, `hmac_token`
- Pra inboxes type "API" (canal customizado, útil pra integrações)

## Endpoints principais usados pelo Oryntra

### Enviar mensagem (Application API)
```
POST /api/v1/accounts/{account_id}/conversations/{conversation_id}/messages
Headers: api_access_token: {user_token}
Body: { "content": "...", "message_type": "outgoing", "private": false }
```

### Atribuir conversa (transferir pra humano)
```
POST /api/v1/accounts/{account_id}/conversations/{conversation_id}/assignments
Headers: api_access_token: {user_token}
Body: { "assignee_id": <user_id> | "team_id": <team_id> }
```

### Listar accounts (Platform API)
```
GET /platform/api/v1/accounts
Headers: api_access_token: {platform_token}
```

### Listar users de account (Platform API)
```
GET /platform/api/v1/accounts/{account_id}/account_users
Headers: api_access_token: {platform_token}
```

### Criar conta (Platform API — usado em white-label)
```
POST /platform/api/v1/accounts
Headers: api_access_token: {platform_token}
Body: { "name": "...", "locale": "pt-BR", "domain": "..." }
```

## Dev local — apontando webhook do Chatwoot pro Oryntra

Com Chatwoot rodando em `http://localhost:3000` e Oryntra em `http://localhost:8080`:

1. Em Chatwoot: Settings → Integrations → Webhooks → Add new webhook
2. URL: `http://host.docker.internal:8080/api/webhooks/chatwoot/{connection_uuid}`
3. Subscribe: `message_created`, `conversation_created`, `conversation_status_changed`, `conversation_updated`
4. Salvar — Chatwoot retorna `secret`. Copiar pro `ChatwootConnectionResource` no Oryntra.

## Geração de Postman Collection (opcional)

```bash
npm install -g openapi-to-postmanv2
openapi2postmanv2 -s openapi-application.json -o postman/application.json -p
openapi2postmanv2 -s openapi-platform.json -o postman/platform.json -p
```

## Atualização das specs

```bash
cd docs/integrations/chatwoot/
curl -sSL -o openapi-platform.json    https://raw.githubusercontent.com/chatwoot/chatwoot/develop/swagger/tag_groups/platform_swagger.json
curl -sSL -o openapi-application.json https://raw.githubusercontent.com/chatwoot/chatwoot/develop/swagger/tag_groups/application_swagger.json
curl -sSL -o openapi-full.json        https://raw.githubusercontent.com/chatwoot/chatwoot/develop/swagger/swagger.json
```

Recomendado: rodar bimestralmente ou ao notar mudanças nas APIs em produção.
