# Phase 7.2 — Handoff auto-execute + contact tools + Chatwoot sync expansion

> **Para agentes:** use checkbox (`- [ ]`) por subtask. Implementar bloco a bloco.

**Goal:** Corrigir bugs do fluxo handoff (gate HITL desnecessário, variáveis vazias, conversa não abre), adicionar configuração por especialista de transferência humana / time com dropdowns populados, sincronizar times e ids Chatwoot, e expor ferramentas de leitura/edição de contato à IA.

**Motivação (origem em teste manual):**
- Mensagem "falar com humano" gerou nota privada com `Motivo:`, `Prioridade:`, `Mensagem ao cliente:` vazios (IA omitiu params; código não falha).
- Após aprovação em `WaitingHuman`, nenhuma side-effect rodou (job já tinha rodado parcial no momento do tool call).
- Conversa ficou `pending` no Chatwoot — humano não viu na inbox.
- Não há dropdown UI pra escolher time/atendente destino do handoff.

**Decisão arquitetural:** handoff **não passa mais por aprovação HITL**. IA chama tool → side effects rodam imediatamente (open + assign + private note + customer message). Aprovação HITL continua existindo pra outros casos (response normal).

**Tech stack:** Laravel 13, PHP 8.4, Postgres, Filament 5, Horizon, Chatwoot Application API v1, Pest 4.

---

## Current State

- `RequestHumanHandoff` ação coloca run em `WaitingHuman` E dispara `ApplyHumanHandoffToChatwootJob` (sem gate de aprovação real, só dual side effect)
- `ApplyHumanHandoffToChatwootJob` envia customer message + private note + label + assign team/agent — mas **não abre conversa** e **não valida** campos vazios
- `ApproveAgentRun` só chama `runtime->resume()` Python — **não re-dispatch** o job
- `agent_specialists.handoff_config` jsonb tem: `enabled`, `default_priority`, `customer_message`, `rules`
- `agent_chatwoot_bindings.handoff_*` (team/agent) usa `TextInput` numérico no Filament — sem dropdown
- `SyncChatwootAccountsJob` sincroniza usuários da Platform API, salva email em `users` e role em `workspace_members` — **não persiste** `chatwoot_user_id`
- Não existe sync de times Chatwoot
- Não existem tools de contato (get/update) expostas à IA

## Out Of Scope

- Multi-target handoff (lista de fallback times/agentes)
- Roteamento por availability_status do agente (round-robin)
- Edição de `custom_attributes` do contato (próxima fase)
- Suporte a múltiplos `handoff_config` por specialist (regra → destino)
- Reabertura de handoff (volta ao bot)

## Data Model Changes

### `workspace_members` (alter)

| Coluna | Tipo | Notas |
|---|---|---|
| `chatwoot_user_id` | bigint nullable | ID do agente no Chatwoot (vem do Platform API) |
| `chatwoot_availability` | text nullable | `online`/`busy`/`offline` (futuro round-robin) |
| `chatwoot_confirmed` | boolean default false | `confirmed` flag do payload |
| `chatwoot_role` | text nullable | redundante com `role` local mas preserva valor original ("administrator"/"agent") |

Index: `(workspace_id, chatwoot_user_id)`.

### `chatwoot_teams` (nova)

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint pk | |
| `workspace_id` | bigint fk cascade | |
| `chatwoot_connection_id` | bigint fk cascade | Conexão que serviu o sync |
| `chatwoot_team_id` | bigint | ID do team no Chatwoot |
| `name` | text | |
| `description` | text nullable | |
| `allow_auto_assign` | boolean default false | flag do payload |
| `synced_at` | timestamptz | |
| timestamps | | |

Unique `(chatwoot_connection_id, chatwoot_team_id)`.
Index `(workspace_id)`.

### `agent_specialists.handoff_config` (jsonb shape — sem migration, só normalizer)

```json
{
  "enabled": false,                      // já existe — agora "transferência humana"
  "agent_id": null,                      // NOVO — workspace_members.chatwoot_user_id; null = só abre conversa
  "team_enabled": false,                 // NOVO — toggle separado
  "team_id": null,                       // NOVO — chatwoot_teams.chatwoot_team_id; null = só abre
  "default_priority": "normal",
  "customer_message": "...",
  "rules": []
}
```

### `agent_specialists.contact_tools_config` (jsonb nova coluna)

```json
{
  "update_enabled": false,               // toggle única → habilita get + update
  "update_fields": ["name", "email", "phone_number"]  // hardcoded por enquanto
}
```

Sem custom_attributes nesta fase (decisão do usuário).

---

## Bloco 2 — Bugfixes handoff (PRIORIDADE)

### Task 2.1: `ChatwootAgentBotClient::toggleConversationStatus`

**Arquivo:** `app/Services/Chatwoot/ChatwootAgentBotClient.php`

```php
public function toggleConversationStatus(int $conversationId, string $status): void
{
    $response = Http::withHeaders($this->connection->chatwootHeaders())
        ->post($this->url("conversations/{$conversationId}/toggle_status"), [
            'status' => $status,
        ]);

    if ($response->failed()) {
        throw new RuntimeException("Chatwoot toggleConversationStatus({$conversationId}) failed: HTTP {$response->status()}");
    }
}
```

Status válidos: `open`, `resolved`, `pending`, `snoozed`.

### Task 2.2: Remover gate `WaitingHuman` do `RequestHumanHandoff`

**Arquivo:** `app/Actions/AgentTools/RequestHumanHandoff.php`

Mudança chave (linha 45-49):
```diff
- $run->update([
-     'status' => AgentRunStatus::WaitingHuman,
-     'output' => $this->handoffOutput($run, $payload, $specialistId),
-     'finished_at' => null,
- ]);
+ $run->update([
+     'status' => AgentRunStatus::Completed,
+     'output' => $this->handoffOutput($run, $payload, $specialistId),
+     'finished_at' => Carbon::now(),
+ ]);
```

Adicionar `open_conversation` ao `side_effects.actions` (linha 150-156):
```diff
  'actions' => [
+     'open_conversation' => 'pending',
      'customer_message' => 'pending',
      'private_note' => 'pending',
      ...
```

### Task 2.3: Validar payload + default customer_message

**Arquivo:** `app/Actions/AgentTools/RequestHumanHandoff.php`

No início de `execute()`:
```php
if (! filled($payload['reason'] ?? null)) {
    throw ValidationException::withMessages(['reason' => 'Reason is required for human handoff.']);
}
if (! in_array($payload['priority'] ?? null, ['low', 'normal', 'high', 'urgent'], true)) {
    $payload['priority'] = 'normal';
}
```

Fallback de `customer_message`: se vazio, pegar do `handoff_config.customer_message` do specialist. Implementar via lookup do specialist dentro da transação.

### Task 2.4: `ApplyHumanHandoffToChatwootJob` — abrir conversa

**Arquivo:** `app/Jobs/Agent/ApplyHumanHandoffToChatwootJob.php`

Adicionar passo `open_conversation` antes do `team_assignment` (linha 92):

```php
$this->runAction($run, 'open_conversation', function () use ($client, $conversationId): void {
    $client->toggleConversationStatus($conversationId, 'open');
});
```

E adicionar `'open_conversation'` ao array do `markAllSkipped` (linha 171).

### Task 2.5: Job lê destino do specialist (não binding)

`ApplyHumanHandoffToChatwootJob`:
1. Carregar specialist via `traceSpecialistId($run)`
2. Specialist tem `handoff_config.agent_id`/`team_id`? Usar esses.
3. Se nulo, cair pra binding (compat retroativa).

```php
$handoffConfig = is_array($specialist?->handoff_config) ? $specialist->handoff_config : [];
$teamId = $handoffConfig['team_id'] ?? $binding?->handoff_team_id;
$agentId = $handoffConfig['agent_id'] ?? $binding?->handoff_agent_id;
```

### Task 2.6: Schema Python tools — required params

**Arquivo:** `agent-python/src/oryntra_agent/agent/tools.py`

Tornar `reason` e `priority` required no schema da tool `request_human_handoff`. Se vier vazio, raise antes da chamada HTTP.

---

## Bloco 1 — Sync teams + chatwoot_user_id

### Task 1.1: Migration `workspace_members` += chatwoot fields

`database/migrations/2026_05_20_xxxxxx_add_chatwoot_fields_to_workspace_members.php`

```php
Schema::table('workspace_members', function (Blueprint $table) {
    $table->unsignedBigInteger('chatwoot_user_id')->nullable()->after('role');
    $table->string('chatwoot_availability')->nullable();
    $table->boolean('chatwoot_confirmed')->default(false);
    $table->string('chatwoot_role')->nullable();
    $table->index(['workspace_id', 'chatwoot_user_id']);
});
```

### Task 1.2: Migration `chatwoot_teams`

```php
Schema::create('chatwoot_teams', function (Blueprint $table) {
    $table->id();
    $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
    $table->foreignId('chatwoot_connection_id')->constrained()->cascadeOnDelete();
    $table->unsignedBigInteger('chatwoot_team_id');
    $table->string('name');
    $table->text('description')->nullable();
    $table->boolean('allow_auto_assign')->default(false);
    $table->timestamp('synced_at')->nullable();
    $table->timestamps();
    $table->unique(['chatwoot_connection_id', 'chatwoot_team_id']);
    $table->index('workspace_id');
});
```

### Task 1.3: `ChatwootAgentBotClient::listTeams`

```php
public function listTeams(): array
{
    $response = Http::withHeaders($this->connection->chatwootHeaders())
        ->get($this->url('teams'));
    if ($response->failed()) {
        throw new RuntimeException("Chatwoot listTeams failed: HTTP {$response->status()}");
    }
    $data = $response->json();
    return is_array($data) ? array_values($data) : [];
}
```

### Task 1.4: Adaptar `SyncChatwootAccountsJob` pra persistir `chatwoot_user_id`

Linha 107-109 atual:
```php
$workspace->users()->syncWithoutDetaching([
    $user->id => ['role' => $role],
]);
```

Trocar por:
```php
$workspace->users()->syncWithoutDetaching([
    $user->id => [
        'role' => $role,
        'chatwoot_user_id' => $userId,
        'chatwoot_availability' => $userData['availability_status'] ?? null,
        'chatwoot_confirmed' => (bool) ($userData['confirmed'] ?? false),
        'chatwoot_role' => (string) ($accountUser['role'] ?? 'agent'),
    ],
]);
```

### Task 1.5: `SyncChatwootTeamsJob`

Job per workspace (recebe `chatwoot_connection_id`). Roda 1x/dia + manual.

```php
class SyncChatwootTeamsJob implements ShouldQueue
{
    public function __construct(public int $chatwootConnectionId) {}

    public function handle(): void
    {
        $connection = ChatwootConnection::findOrFail($this->chatwootConnectionId);
        $client = new ChatwootAgentBotClient($connection);
        $teams = $client->listTeams();
        foreach ($teams as $team) {
            ChatwootTeam::updateOrCreate(
                [
                    'chatwoot_connection_id' => $connection->id,
                    'chatwoot_team_id' => (int) $team['id'],
                ],
                [
                    'workspace_id' => $connection->workspace_id,
                    'name' => $team['name'],
                    'description' => $team['description'] ?? null,
                    'allow_auto_assign' => (bool) ($team['allow_auto_assign'] ?? false),
                    'synced_at' => now(),
                ],
            );
        }
    }
}
```

Adicionar ao Kernel scheduler (1x/dia, junto com SyncChatwootAccountsJob).

### Task 1.6: Botão "Sincronizar agora" inclui teams

**Arquivo:** `app/Filament/Pages/ChatwootPlatformSettings.php`

Após dispatch do `SyncChatwootAccountsJob`, iterar `ChatwootConnection::all()` e dispatch `SyncChatwootTeamsJob` por conexão.

### Task 1.7: Model `ChatwootTeam`

`app/Models/ChatwootTeam.php` simples Eloquent.

---

## Bloco 4 — Backend tools

### Task 4.1: Enum `NativeTool`

Adicionar:
```php
case RequestTeamHandoff = 'request_team_handoff';
case ChatwootGetContact = 'chatwoot_get_contact';
case ChatwootUpdateContact = 'chatwoot_update_contact';
```

### Task 4.2: `NativeToolRegistry`

Adicionar labels/descriptions.

### Task 4.3: `ChatwootAgentBotClient` — contact methods

```php
public function getContact(int $contactId): array
{
    $response = Http::withHeaders($this->connection->chatwootHeaders())
        ->get($this->url("contacts/{$contactId}"));
    if ($response->failed()) {
        throw new RuntimeException("Chatwoot getContact({$contactId}) failed: HTTP {$response->status()}");
    }
    return $response->json('payload') ?? [];
}

public function updateContact(int $contactId, array $attributes): array
{
    $response = Http::withHeaders($this->connection->chatwootHeaders())
        ->patch($this->url("contacts/{$contactId}"), $attributes);
    if ($response->failed()) {
        throw new RuntimeException("Chatwoot updateContact({$contactId}) failed: HTTP {$response->status()}");
    }
    return $response->json('payload') ?? [];
}
```

### Task 4.4: Action `App\Actions\AgentTools\UpdateChatwootContact`

- Whitelist: `name`, `email`, `phone_number` (hardcoded)
- Bloqueia `identifier`, `custom_attributes`, `additional_attributes`
- Log trace event no `agent_runs.output.trace`

### Task 4.5: Action `App\Actions\AgentTools\GetChatwootContact`

Simples wrapper read-only.

### Task 4.6: Action `App\Actions\AgentTools\RequestTeamHandoff`

Análoga a `RequestHumanHandoff`, mas:
- Tool é `request_team_handoff` no allowlist
- Side effects: `open_conversation` + `team_assignment` + `customer_message` + `private_note` + `label`
- **Não** chama `assignAgent` (foco em team)
- Reusar `ApplyHumanHandoffToChatwootJob`? Sim, mas com flag `target_type` no payload jsonb `handoff` pra job decidir quais actions rodam.

### Task 4.7: Python tools

**Arquivo:** `agent-python/src/oryntra_agent/agent/tools.py`

- Schema `request_human_handoff`: `reason` (required), `priority` (required, enum), `customer_message` (optional)
- Schema `request_team_handoff`: idem
- Schema `chatwoot_get_contact`: `contact_id` (required)
- Schema `chatwoot_update_contact`: `contact_id` (required), `name`, `email`, `phone_number` (≥1 required)

---

## Bloco 3 — Filament Specialist UI

### Task 3.1: Atualizar Tab "Transferência humana"

**Arquivo:** `app/Filament/Resources/Agents/RelationManagers/SpecialistsRelationManager.php`

Após Toggle `handoff_config.enabled`, adicionar:
```php
Select::make('handoff_config.agent_id')
    ->label('Atendente destino')
    ->options(fn (): array => self::chatwootAgentOptions())
    ->searchable()
    ->placeholder('Nenhum — apenas abrir conversa')
    ->visible(fn (Get $get): bool => (bool) $get('handoff_config.enabled')),
```

Helper:
```php
private static function chatwootAgentOptions(): array
{
    $tenant = Filament::getTenant();
    if ($tenant === null) return [];
    return DB::table('workspace_members')
        ->where('workspace_id', $tenant->getKey())
        ->whereNotNull('chatwoot_user_id')
        ->join('users', 'users.id', '=', 'workspace_members.user_id')
        ->pluck('users.name', 'workspace_members.chatwoot_user_id')
        ->all();
}
```

### Task 3.2: Tab nova "Transferência para time"

```php
Tab::make('Transferência para time')
    ->icon('heroicon-o-user-group')
    ->schema([
        Section::make('Configuração')
            ->columns(2)
            ->schema([
                Toggle::make('handoff_config.team_enabled')
                    ->label('Permitir transferência para time')
                    ->live()
                    ->default(false),
                Select::make('handoff_config.team_id')
                    ->label('Time destino')
                    ->options(fn (): array => self::chatwootTeamOptions())
                    ->searchable()
                    ->placeholder('Nenhum — apenas abrir conversa')
                    ->visible(fn (Get $get): bool => (bool) $get('handoff_config.team_enabled')),
            ]),
    ]),
```

### Task 3.3: Tab nova "Contatos Chatwoot"

```php
Tab::make('Contatos Chatwoot')
    ->icon('heroicon-o-identification')
    ->schema([
        Section::make('Edição de contato')
            ->schema([
                Toggle::make('contact_tools_config.update_enabled')
                    ->label('Permitir IA editar contato')
                    ->helperText('Quando ativo, IA pode ler (chatwoot_get_contact) e atualizar nome/email/telefone do contato. Não permite editar custom attributes.')
                    ->default(false),
            ]),
    ]),
```

### Task 3.4: `normalizeSpecialistFormData` reconcilia 3 toggles

Adicionar reconciliação pra:
- `handoff_config.team_enabled` ↔ `request_team_handoff`
- `contact_tools_config.update_enabled` ↔ `chatwoot_get_contact` + `chatwoot_update_contact`

(Mesmo padrão do `enabled` ↔ `request_human_handoff` existente).

### Task 3.5: Migration `agent_specialists.contact_tools_config`

```php
Schema::table('agent_specialists', function (Blueprint $table) {
    $table->jsonb('contact_tools_config')->nullable()->after('handoff_config');
});
```

---

## Bloco 6 — Testes Pest

- `tests/Feature/AgentTools/RequestHumanHandoffTest.php`: assert status fica `Completed`, não `WaitingHuman`
- `tests/Feature/AgentTools/RequestHumanHandoffTest.php`: assert default customer_message fallback
- `tests/Feature/Jobs/ApplyHumanHandoffToChatwootJobTest.php`: assert `toggle_status('open')` chamado antes de assignments
- `tests/Feature/Jobs/ApplyHumanHandoffToChatwootJobTest.php`: assert specialist `handoff_config.agent_id` tem prioridade sobre binding
- `tests/Feature/Jobs/SyncChatwootTeamsJobTest.php`: upsert + idempotência
- `tests/Feature/AgentTools/UpdateChatwootContactTest.php`: rejeita campos fora da whitelist
- `tests/Feature/Filament/SpecialistsRelationManagerTest.php`: 3 toggles reconcilia tools_allowlist
- `tests/Feature/Chatwoot/SyncChatwootAccountsJobTest.php`: verifica chatwoot_user_id persistido no pivot

---

## Order Of Operations

1. Bloco 2 (bugfixes — destrava teste manual)
2. Bloco 1 (sync — pré-requisito de dropdowns)
3. Bloco 4 (tools backend)
4. Bloco 3 (UI)
5. Bloco 6 (testes ao longo de cada bloco — não bloco final)

## Acceptance Criteria

- Mensagem "quero falar com humano" gera handoff que: abre conversa Chatwoot, envia mensagem ao cliente, nota privada com `reason`/`priority`/`customer_message` preenchidos
- Specialist com `handoff_config.agent_id` setado: conversa atribuída ao agente correto via API
- Specialist com `team_enabled + team_id`: conversa atribuída ao time correto
- IA pode chamar `chatwoot_update_contact` apenas se toggle ativo no specialist
- `update_contact` rejeita `custom_attributes` e `identifier`
- Botão "Sincronizar agora" popula `chatwoot_teams` + atualiza `workspace_members.chatwoot_user_id`
- Pest verde
- Pint verde (`vendor/bin/pint --dirty --format agent`)
