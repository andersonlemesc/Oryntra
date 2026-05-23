# 2026-05-23 — Contacts + Long-term Memory (Phase 11)

> Branch sugerida: `feature/contacts-long-term-memory`. Base: `develop`.
> Pre-req: fases 7.2 + 7.3 + Memory de curto prazo entregues (na branch `feature/langgraph-conversation-memory` apos merge).

## Motivacao

Hoje a IA so tem memoria curta (`conversation_messages` no checkpoint LangGraph) que vive enquanto a conversa Chatwoot esta ativa. Quando o cliente volta dias depois, a IA reaprende do zero. Sem rastro de quem ja conversou, sem leads, sem pipeline.

Esta fase introduz:

1. Tabela **`contacts`** como fonte da verdade local — criada automaticamente quando uma conversa chega via webhook Chatwoot.
2. **`contact_memories`** — fatos consolidados (preferencias, restricoes, historico) extraidos pela IA ou registrados manualmente.
3. **Injecao automatica** das memorias no system prompt do especialista (configuravel por especialista).
4. **Filament**: Resource `ContactResource` na nav "Contatos" com tabs Resumo / Memorias / Conversas / Anotacoes / Chatwoot raw. Widget dashboard de leads 24h.
5. **Sync periodico** com Chatwoot para puxar `custom_attributes` que o admin/atendente Chatwoot criou.

## Decisoes confirmadas

| Decisao | Valor |
|---|---|
| Escopo | Tudo (11.1-11.4) numa branch unica |
| Extracao | Job assincrono pos-run (`ExtractContactMemoryJob`) |
| Injecao | Top N por recencia, configuravel (N ou "todas") |
| Lead status | Manual apenas nesta fase |

## Fora de escopo nesta fase

- Embedding semantico / pgvector. Adicionar quando lista crescer e top-N por recencia comecar a perder relevancia.
- Pipeline Kanban drag-drop. Lista + badge + filter ja resolvem o caso comum.
- Auto-classificacao de lead via LLM. Manual evita ruido.
- Merge de contatos duplicados (Chatwoot tem feature "merge"). Detectar localmente fica pra Phase 12.
- Anonimizacao LGPD automatica. Soft delete + cascade ja cobre o basico.

---

## Data Model

### `contacts` (nova)

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigserial pk | |
| `workspace_id` | bigint fk cascade | Tenancy. |
| `chatwoot_connection_id` | bigint fk cascade | A qual conexao Chatwoot este contato pertence. |
| `chatwoot_contact_id` | bigint | ID do contato no Chatwoot. |
| `identifier` | text nullable | Identifier custom (Chatwoot inbox-specific). |
| `name` | text nullable | |
| `email` | text nullable | |
| `phone_number` | text nullable | |
| `thumbnail` | text nullable | URL avatar. |
| `additional_attributes` | jsonb default `{}` | Snapshot do bloco Chatwoot. |
| `chatwoot_custom_attributes` | jsonb default `{}` | Snapshot do bloco Chatwoot. |
| `lead_status` | text default `new` | Enum: new, contacted, qualified, won, lost, dormant. |
| `lead_score` | int nullable | Reservado para futuro ordering, sem regra automatica nesta fase. |
| `first_seen_at` | timestamptz | Definido na criacao. |
| `last_seen_at` | timestamptz | Atualizado a cada webhook envolvendo este contato. |
| `last_message_at` | timestamptz nullable | Timestamp da ultima mensagem do contato (nao da IA). |
| `synced_at` | timestamptz nullable | Ultima sync via SyncChatwootContactsJob. |
| timestamps + `deleted_at` | | Soft delete. |

**Constraints:**
- UNIQUE `(workspace_id, chatwoot_connection_id, chatwoot_contact_id)`
- INDEX `(workspace_id, lead_status)`
- INDEX `(workspace_id, last_message_at DESC)`

### `contact_memories` (nova)

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigserial pk | |
| `contact_id` | bigint fk cascade | |
| `workspace_id` | bigint fk cascade | Denormalizado pra query rapida sem join. |
| `type` | text check | Enum: preference, fact, constraint, history, custom. |
| `content` | text | Conteudo natural. Ex: "Altura 1,72m, peso 80kg". |
| `source` | text check | Enum: agent_extracted, manual, chatwoot_attribute, tool. |
| `confidence` | float nullable | 0-1 se LLM extraiu. |
| `conversation_id` | bigint nullable | Chatwoot conversation_id que originou. |
| `agent_run_id` | bigint fk set null | Run que originou. |
| `expires_at` | timestamptz nullable | Fatos com validade (ex: "esta de mudanca este mes"). |
| `author_user_id` | bigint fk users set null | Se manual, quem registrou. |
| timestamps | | |

**Constraints:**
- INDEX `(contact_id, created_at DESC)`
- INDEX `(workspace_id, type)`

### `agent_runs.contact_id` (alter)

```sql
ALTER TABLE agent_runs ADD COLUMN contact_id bigint NULL
    REFERENCES contacts(id) ON DELETE SET NULL;
CREATE INDEX agent_runs_contact_id_idx ON agent_runs(contact_id);
```

Nullable porque runs antigos nao tem contact ainda. Backfill opcional via job.

### `agent_specialists.memory_config` (alter)

Sem migration (segue o padrao de `handoff_config` e `contact_tools_config` em jsonb).

```sql
ALTER TABLE agent_specialists ADD COLUMN memory_config jsonb DEFAULT '{}'::jsonb;
```

Shape:
```json
{
  "extraction_enabled": false,
  "injection_enabled": false,
  "injection_limit": 20,
  "extraction_types": ["preference", "fact", "constraint"]
}
```

- `extraction_enabled`: dispara `ExtractContactMemoryJob` apos cada run.
- `injection_enabled`: inclui memorias no system prompt do especialista.
- `injection_limit`: top N por `created_at` desc. `null` = todas.
- `extraction_types`: tipos que o LLM deve extrair. UI restringe.

---

## Pipeline de runtime

```
Webhook Chatwoot
  → ChatwootWebhookController
  → ResolveOrCreateContact action (sincrono)
      → SELECT por (workspace_id, chatwoot_connection_id, chatwoot_contact_id)
      → INSERT ou UPDATE last_seen_at + last_message_at
  → AgentRun.contact_id setado

DispatchAgentRunJob
  → AgentRuntimeClient::payload monta payload Python
      → payload['contact']['id'] = contact local id
      → payload['contact']['memories'] = top N por created_at desc (se injection_enabled)
  → POST Python
      → specialist_response_messages inclui "Memorias do contato:" no system
      → specialist_decision_messages idem
  → Resposta volta
  → DB::transaction commit run

DispatchAgentRunJob.afterCommit
  → ExtractContactMemoryJob (se memory_config.extraction_enabled)
      → LLM (credencial do especialista) recebe:
          - transcript (ultimas N mensagens)
          - memorias existentes do contato (deduplicacao)
          - tipos permitidos
      → Retorna lista estruturada de novas memorias
      → INSERT contact_memories (dedup por content normalizado exato)

Cron 1x/h
  → SyncChatwootContactsJob
      → ChatwootAdminApiClient::listContacts(connection, updated_since=synced_at)
      → Atualiza chatwoot_custom_attributes + additional_attributes locais
      → Atualiza name/email/phone se Chatwoot mudou (apenas se diferente)
      → NAO sobrescreve lead_status local
```

---

## Blocos de implementacao

### Bloco A — Contacts table + auto-create (11.1)

- [ ] A.1 Migration `contacts` com indices e constraints.
- [ ] A.2 Migration `agent_runs.contact_id`.
- [ ] A.3 Model `App\Models\Contact` + cast + factory + soft delete.
- [ ] A.4 Action `App\Actions\Contacts\ResolveOrCreateContact::execute(workspaceId, connectionId, chatwootPayload): Contact`.
- [ ] A.5 Hook na hidratacao do AgentRun (provavelmente em `App\Actions\Agents\ResolveAgentForWebhook` ou similar) para popular `agent_runs.contact_id` antes do save.
- [ ] A.6 Filament: nav group `Contatos` + `ContactResource` (list, view, edit).
- [ ] A.7 List: columns name, email, phone, lead_status (badge), last_message_at, agent counter; filtros por lead_status e por agente vinculado.
- [ ] A.8 View Infolist com tab "Resumo" basico.
- [ ] A.9 Edit: lead_status (select) + name/email/phone (editaveis locais).
- [ ] A.10 Tests Pest:
  - Webhook do Chatwoot cria contact novo + popula campos
  - Webhook repetido nao duplica + atualiza last_seen
  - AgentRun.contact_id vinculado
  - ContactResource list filtra por workspace + lead_status

### Bloco B — Memories + manual UI (11.2 parte 1)

- [ ] B.1 Migration `contact_memories`.
- [ ] B.2 Model `App\Models\ContactMemory` + factory.
- [ ] B.3 Tab "Memorias" no ContactResource view:
  - Timeline cronologica desc, filtro por type
  - Badge colorido por source
  - Action "Adicionar memoria" (manual) com type/content
  - Action "Apagar memoria"
- [ ] B.4 Migration sem schema mas commit do enum `ContactMemoryType` + `ContactMemorySource` PHP enum.
- [ ] B.5 Tests Pest: criar memoria manual, listar por contact, soft-delete cascata quando contato e apagado.

### Bloco C — Tool `update_contact_memory` (11.2 parte 2)

- [ ] C.1 Action `App\Actions\AgentTools\UpdateContactMemory::execute(payload): array`.
- [ ] C.2 FormRequest `UpdateContactMemoryRequest` valida payload (workspace_id, agent_id, agent_run_id, specialist_id?, contact_id, type, content, confidence?).
- [ ] C.3 Controller `App\Http\Controllers\Internal\UpdateContactMemoryController`.
- [ ] C.4 Route `POST /api/internal/agent-tools/update-contact-memory` no `internal.runtime` middleware.
- [ ] C.5 Enum `NativeTool::UpdateContactMemory = 'update_contact_memory'` + entrada no `NativeToolRegistry`.
- [ ] C.6 Python `agent-python/src/oryntra_agent/agent/tools.py`:
  - `UpdateContactMemoryRequest` Pydantic
  - `MemoryResponse` Pydantic
  - `update_contact_memory(payload) -> MemoryResponse`
- [ ] C.7 Tests Pest: tool requer specialist allowlist, persiste memoria com source=tool, rejeita type fora do enum.

### Bloco D — Extraction job (11.2 parte 3)

- [ ] D.1 `memory_config` jsonb em `agent_specialists` (model cast).
- [ ] D.2 Filament `SpecialistsRelationManager` ganha tab "Memoria":
  - Toggle "Extracao automatica"
  - Toggle "Injetar no prompt"
  - TextInput numerico "Limite de memorias no prompt" (placeholder "Vazio = todas")
  - CheckboxList "Tipos extraidos" (preference, fact, constraint, history, custom)
  - Helper text explicando custo de tokens
- [ ] D.3 `normalizeSpecialistFormData` trata `memory_config` (defaults + cast).
- [ ] D.4 Job `App\Jobs\Agent\ExtractContactMemoryJob`:
  - Le AgentRun + Contact + memorias existentes do contato
  - Monta prompt:
    - Transcript (ultimas N msgs)
    - Memorias existentes (pra IA evitar duplicar)
    - Tipos permitidos
  - Chama LLM via credencial do especialista (mesma usada na run)
  - Retorno: structured `list[ExtractedMemory]` (type, content, confidence)
  - Dedup: normaliza content (lower + trim + colapso de espacos) e ignora se ja existe identico no contato
  - INSERT contact_memories com source=agent_extracted
- [ ] D.5 `DispatchAgentRunJob` ja existente: apos transaction commit, se especialista tem `memory_config.extraction_enabled`, dispatch `ExtractContactMemoryJob::dispatch($run->id)->afterCommit()`.
- [ ] D.6 Decisao tecnica: Laravel chama LLM diretamente via SDK PHP (`openai-php/client` ou Anthropic SDK)? OU Laravel chama endpoint Python `/internal/memory/extract`?
  - **Recomendacao**: novo endpoint Python `/internal/memory/extract` reaproveita `chat_model_for_credential` e `with_structured_output` ja maduros. Laravel posta transcript + memorias existentes + tipos, recebe lista estruturada.
- [ ] D.7 Tests:
  - Job extrai memorias novas
  - Job nao duplica content identico ja existente
  - Job respeita extraction_types do especialista
  - Job falha silenciosamente sem credencial LLM
  - Endpoint Python valida payload + retorna lista estruturada

### Bloco E — Prompt injection (11.2 parte 4)

- [ ] E.1 `AgentRuntimeClient::payload`:
  - Carrega `Contact->memories()->orderByDesc('created_at')->limit($specialistInjectionLimit)->get()` se specialist tem `injection_enabled`.
  - Inclui em `payload['contact']['memories']` como `[{type, content, source, created_at, conversation_id}]`.
  - Se `injection_enabled=false`, envia array vazio.
- [ ] E.2 Python `schemas.py`:
  - `ContactMemorySnapshot` BaseModel (type, content, source, created_at, conversation_id).
  - `ChatwootRuntimeRequest.contact` aceita `memories: list[ContactMemorySnapshot] = []`.
- [ ] E.3 Python `specialist_response_messages` e `specialist_decision_messages`:
  - Se `payload.contact.memories` nao vazio, prepend section no system:
    ```
    Memorias do contato:
    - [preference] Altura 1,72m, peso 80kg (2026-05-21)
    - [fact] Procura bike eletrica urbana
    ```
- [ ] E.4 Tests Python: prompt inclui memorias quando presentes, nao inclui quando vazio.
- [ ] E.5 Tests Laravel: payload tem memorias top N quando injection_enabled, vazio quando off.

### Bloco F — Lead status manual UI (11.3)

- [ ] F.1 Filament list: badge colorido por lead_status (new=gray, contacted=blue, qualified=amber, won=green, lost=red, dormant=zinc).
- [ ] F.2 Filtro de tabela por lead_status (multi-select).
- [ ] F.3 Bulk actions: "Marcar como qualified", "won", "lost".
- [ ] F.4 Dashboard widget `RecentLeadsStatsWidget`:
  - Card "Leads novos 24h" (count + delta vs 24h anteriores)
  - Card "Em qualified" (count)
- [ ] F.5 Dashboard widget `RecentLeadsTableWidget`:
  - Top 10 contatos `last_message_at` desc com lead_status != dormant
- [ ] F.6 Tests Pest UI: widget renderiza, filtro funciona.

### Bloco G — Sync periodico Chatwoot → contacts (11.4)

- [ ] G.1 `ChatwootAdminApiClient::listContacts(updatedSince): array` — pagina `/api/v1/accounts/{id}/contacts` com `updated_at>` filter.
- [ ] G.2 Job `App\Jobs\Chatwoot\SyncChatwootContactsJob`:
  - Para cada contato retornado, atualiza local respeitando regras:
    - `chatwoot_custom_attributes` e `additional_attributes` sobrescritos pelo Chatwoot
    - `name/email/phone` atualizados apenas se Chatwoot tem valor e local esta vazio OU se ambos preenchidos e diferentes (Chatwoot vence — fonte da verdade externa)
    - `lead_status` NUNCA sobrescrito (e local-only)
  - Atualiza `synced_at`
- [ ] G.3 Schedule cron 1x/h em `routes/console.php`.
- [ ] G.4 Botao "Sincronizar contatos agora" no header da ContactResource list (Filament action).
- [ ] G.5 Tests: job atualiza custom_attributes, preserva lead_status, nao duplica.

### Bloco H — Integrar tools Chatwoot existentes com contacts table

- [ ] H.1 `chatwoot_update_contact` (Fase 7.2 ja existe) agora tambem atualiza linha local em `contacts` (mesma transaction).
- [ ] H.2 `chatwoot_get_contact` (Fase 7.2 ja existe) le do banco local primeiro; se nao encontrar OU `synced_at` for muito antigo (> 5min), chama Chatwoot e atualiza cache local.
- [ ] H.3 Tests ajustados.

---

## Ordem de execucao

1. **Bloco A** (foundation, ja entrega valor visivel no Filament)
2. **Bloco B** (memorias manuais)
3. **Bloco C** (tool explicita IA)
4. **Bloco D** (extracao LLM automatica)
5. **Bloco E** (injecao no prompt — momento "wow" da feature)
6. **Bloco H** (consolidacao com tools existentes)
7. **Bloco F** (UI lead status + dashboard)
8. **Bloco G** (sync periodico — ultimo porque depende de todo o resto estar estavel)

Apos cada bloco: rodar Pest + Pint + restart Horizon + testar manualmente em conversa Chatwoot real.

---

## Pontos de atencao tecnicos

- **Tenancy**: TODO query escopada por workspace_id. Filament global scope ou trait reutilizavel.
- **Race condition no `ResolveOrCreateContact`**: 2 webhooks simultaneos podem tentar criar mesmo contato. Solucao: `firstOrCreate` com UNIQUE constraint, capturar QueryException, re-select.
- **Dedup de memoria**: comparar content normalizado exato e simples mas perde fraseamentos similares. Aceitavel para 11.x. Embedding pgvector fica para fase posterior.
- **Custo LLM**: extraction_enabled=false por default. Admin opta in por especialista. Helper text explicita custo.
- **LGPD/Privacy**: soft delete em contacts cascateia para memorias. Considerar comando admin `php artisan contact:anonymize <id>` que zera dados pessoais mantendo metricas.
- **Backfill agent_runs.contact_id**: opcional, mas util para painel. Job que itera agent_runs sem contact_id, resolve via `chatwoot_contact_id` no `input.contact` snapshot, popula.
- **Conflito de nome**: Chatwoot envia "name" no payload. Se cliente disse nome diferente para IA ("eu sou Anderson"), nao sobrescrever Chatwoot — guardar como memoria type=fact.

---

## Acceptance criteria

- Webhook Chatwoot cria contact automaticamente e vincula a `agent_runs.contact_id`.
- `ContactResource` lista contatos do workspace com badge de lead_status; super_admin ve todos os workspaces.
- View do contato mostra timeline de memorias (manual + agent_extracted + tool).
- Toggle "Extracao automatica" no especialista habilita `ExtractContactMemoryJob` apos run.
- Toggle "Injetar no prompt" + limite N controlam o que o especialista ve no system message.
- Tool `update_contact_memory` funciona via specialist allowlist.
- Tool `chatwoot_update_contact` atualiza Chatwoot + linha local em uma transaction.
- Sync periodico atualiza `chatwoot_custom_attributes` sem perder lead_status local.
- Dashboard exibe widget "Leads novos 24h".
- Conversa de teste em Chatwoot: cliente volta dias depois, IA ja sabe altura/peso/preferencias da conversa anterior.
- Pest verde (>200 testes). Pint verde. Python pytest verde.

---

## Risco / cuidados

- **Branch grande** (8 blocos). Sugestao: commits granulares por bloco para revisar facil.
- **Quota LLM** pode estourar se muitos workspaces ativam extraction sem perceber custo. Mitigacao: helper text explicito + dashboard de custos (fase 18 do roadmap).
- **Memoria contradictoria**: cliente diz "1,72" e depois "1,75". Estrategia atual: append-only. UI mostra mais recente primeiro. Aceitavel.
- **Performance prompt**: 20 memorias x 80 tokens = 1600 tokens de overhead por turno. Aceitavel para LLMs com 8k+ context. Limite default cobre.
