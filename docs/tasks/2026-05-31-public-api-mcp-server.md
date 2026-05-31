# Fase — API pública + MCP Server próprio + perfil do usuário

**Status:** Entregue em 2026-05-31.

## Contexto

Até aqui o Oryntra só expunha webhook Chatwoot (público) e endpoints `internal/*` (Laravel↔Python
via `X-Internal-Token`). Não havia API pública autenticada por usuário, nem Sanctum, nem área de
perfil no painel. A Fase 16 entregou o MCP **de consumo** (agente usa MCP externo como tool); esta
fase entrega o **inverso**: o Oryntra expõe o **seu próprio** MCP server. O usuário instala um
pacote npm, aponta pro domínio dele, cola um token e gerencia todo o workspace via MCP.

Referência: `/home/anderson/portifolio` (REST `/api/v1` + Sanctum PAT + abilities + pacote
`@andersonlemesc/forge-mcp`).

## O que foi entregue

### Bloco A — Sanctum + token workspace-scoped
- `laravel/sanctum` instalado (sem `install:api`, preservando `routes/api.php`).
- Migration `personal_access_tokens` com coluna extra `workspace_id` (FK).
- `App\Models\ApiToken extends Sanctum\PersonalAccessToken` (+ `workspace()`), registrado via
  `Sanctum::usePersonalAccessTokenModel`. `User` ganhou `HasApiTokens`.
- `App\Support\ApiTokenAbilities` — catálogo de scopes (`agent/specialist/llmkey/category/product/
  media/knowledge/tool` × `read/write`). `App\Actions\Api\IssueApiToken` (grava workspace + valida).

### Bloco B — Núcleo REST `/api/v1`
- Grupo `auth:sanctum` + `throttle:mcp` (RateLimiter por token) + `api.workspace`
  (`ResolveApiWorkspace` injeta o `WorkspaceContext`). Aliases Sanctum `ability`/`abilities`.
- `ApiController` base (workspace, `perPage`, `findInWorkspace` — 404 cross-workspace).
- `GET /me` (whoami). Respostas via API Resources; segredos nunca serializados.

### Bloco C — Recursos (CRUD, workspace-scoped)
- **Agents** (+ auto-specialist em modo Single via `CreateAgentWithDefaults`, reusado pelo Filament),
  **Specialists**, **LLM keys** (+ `/models`).
- **Categories**, **Products** (filtros search/category/price via scopes), **product documents**.
- **Standalone documents** (categorias sendable), **Knowledge base RAG**
  (`from-text` inline + `confirm` de upload; `StoreKnowledgeDocument` estendido com
  `fromText`/`fromStoredPath`).
- **HTTP connectors** + **MCP servers** (`AssembleExternalTool` valida param_schema + separa
  credentials write-only/encrypted; `/mcp-servers/{id}/tools` faz discovery via `McpHttpClient`).

### Bloco E — Upload presigned (MinIO)
- `POST /uploads` → presigned PUT (`temporaryUploadUrl`) + `upload_id` assinado (HMAC via `Crypt`).
  `ConfirmsUploads` valida workspace+purpose+objeto. `UploadPurpose` enum.

### Bloco D — Perfil no Filament (avatar → user menu)
- `ProfilePage` (Fortify `UpdateUserProfileInformation`), `SecurityPage` (trocar senha + 2FA TOTP +
  recovery codes via actions Fortify; `User` ganhou trait `TwoFactorAuthenticatable`),
  `ApiTokensPage` (gerar com workspace + abilities, exibe plaintext uma vez, revogar).

### Bloco F — Pacote npm `packages/oryntra-mcp`
- Thin wrapper MCP (stdio) sobre o REST, espelhando `forge-mcp`. 30 tools com Zod `.describe()`
  ricos cobrindo todos os domínios. `claude mcp add oryntra --env ORYNTRA_API_URL=... --env
  ORYNTRA_API_TOKEN=... -- npx -y @oryntra/mcp`.

## Testes
- `tests/Feature/Api/V1/ApiTokenAuthTest` (6) + `ApiResourcesTest` (5) + `Profile/ProfilePagesTest`
  (4) — verdes. Suíte total: 374 passando (1 falha pré-existente `FortifyViewsTest`, não
  relacionada). Pint limpo, PHPStan limpo no código novo. Pacote npm: `tsc` limpo + smoke E2E
  (stdio → REST) confirmado (30 tools, whoami, list_agents).

## Verificação rápida
1. Painel → avatar → **Tokens da API** → gerar com workspace + abilities.
2. `curl -H "Authorization: Bearer <tok>" https://dominio/api/v1/me`.
3. `cd packages/oryntra-mcp && npm i && npm run build`; `claude mcp add ...`; chamar `create_agent`.

## Pendências conhecidas / próximos passos
- Passkeys: trait e tabela prontos, UI no SecurityPage ficou como sub-etapa futura (MVP focou
  senha + 2FA TOTP).
- Publicação do pacote npm em registry (GitHub Packages/npm) quando for distribuir.
- Confirm de upload para product/standalone docs exposto no REST; tools de upload no pacote npm
  podem ser ampliadas (hoje o pacote cobre o `add_knowledge_from_text`; upload binário fica via
  REST direto).
