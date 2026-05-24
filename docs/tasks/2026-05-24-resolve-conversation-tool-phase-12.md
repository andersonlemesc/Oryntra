# 2026-05-24 — Resolve Conversation Tool (Phase 12)

> Tool nativa `resolve_conversation` permite IA encerrar conversa Chatwoot quando resolve sozinha. Espelha shape do `request_human_handoff`.

## Motivacao

Hoje IA so tem 2 terminais: `request_human_handoff` (transfere atendente) e `request_team_handoff` (transfere time). Se IA resolve duvida do cliente, conversa fica em `pending` indefinidamente — admin precisa fechar manual.

Fluxo desejado: IA atende suporte. Resolveu → chama `resolve_conversation` → Chatwoot vira `resolved`, label opcional adicionada, conversa fechada. Nao resolveu → segue fluxo de handoff existente.

## Decisoes (definidas com usuario)

- **Mensagem final**: payload opcional + fallback em `agent_specialists.resolution_config.customer_message`.
- **Allowlist**: especialista precisa ter `resolve_conversation` em `tools_allowlist` (mesmo padrao do handoff).
- **Campos obrigatorios**: `reason` (motivo curto) + `resolution_summary` (o que foi resolvido).
- **Label automatica**: configuravel em `resolution_config.label_name`, default `resolved-by-ai` quando habilitado.
- **AgentRun status final**: `Completed` (mesmo do handoff). Output guarda `resolution` key paralelo ao `handoff`.
- **Idempotencia**: job verifica status atual; se ja `resolved`, no-op e marca `already_resolved` no output.
- **Rules**: `resolution_rules` repeater igual ao `handoff_rules` (trigger_keywords, reason, customer_message, label).
- **Ordem no job**: `customer_message` → `label` → `toggle_status(resolved)`.
- **Schema**: nova coluna `agent_specialists.resolution_config` (jsonb, default `{}`).

## Shape do `resolution_config`

```json
{
  "enabled": false,
  "customer_message": "Otimo, fico feliz em ter ajudado. Vou encerrar entao.",
  "label_name": "resolved-by-ai",
  "rules": [
    {
      "trigger_keywords": ["obrigado", "resolveu", "era so isso"],
      "reason": "Cliente confirmou resolucao",
      "customer_message": null,
      "label_name": null
    }
  ]
}
```

## Tasks

### Task 1: Migration + Model
- Arquivo: `database/migrations/2026_05_24_*_add_resolution_config_to_agent_specialists_table.php`.
- Coluna: `resolution_config jsonb default '{}'`.
- `AgentSpecialist`: adicionar em `$fillable`, `$casts` e PHPDoc `@property`.

### Task 2: NativeTool enum + registry
- `App\Services\AgentTools\NativeTool`: novo case `ResolveConversation = 'resolve_conversation'`.
- `NativeToolRegistry::tools()`: label "Encerrar conversa", description "Encerra conversa marcando como resolvida no Chatwoot quando IA solucionou.".

### Task 3: Action `ResolveConversation`
- Arquivo: `app/Actions/AgentTools/ResolveConversation.php`.
- Espelha `RequestHumanHandoff`: valida payload, assert especialista tem `resolve_conversation` no allowlist, lock for update no AgentRun, escreve `output.resolution` + `output.trace`, marca run como `Completed`, dispara job.
- Payload: `workspace_id`, `agent_id`, `agent_run_id`, `thread_id`, `conversation_id`, `specialist_id`, `reason` (req), `resolution_summary` (req), `customer_message` (opt), `label_name` (opt).
- Fallback de `customer_message` e `label_name`: usar `specialist.resolution_config` quando vazio.

### Task 4: Job `ApplyResolveConversationToChatwootJob`
- Arquivo: `app/Jobs/Agent/ApplyResolveConversationToChatwootJob.php`.
- Resolve `ChatwootAgentBotClient` do binding ativo (mesmo padrao do handoff job).
- Le status atual da conversa via Chatwoot (`GET conversations/{id}`). Se ja `resolved` → marca `output.resolution.side_effects.status = already_resolved` e retorna.
- Senao: ordem `sendMessage(customer_message)` → `addLabel(label_name)` → `toggleConversationStatus(id, 'resolved')`.
- Cada step atualiza `output.resolution.side_effects.actions.{customer_message,label,resolve}` (`pending` → `done` / `skipped` / `failed`).
- Erros nao param o job — registra `failed` e segue. Job nunca falha publicamente (igual handoff job).

### Task 5: Supervisor rules (Python)
- `agent-python/src/oryntra_agent/agent/`: estender modelos para incluir `resolution_config` e `resolution_rules`.
- Funcao `matching_resolution_rule(payload, specialist)` analoga a `matching_handoff_rule`.
- Quando rule bate → emit tool call `resolve_conversation` automaticamente com `reason`, `customer_message` resolvidos.
- Tool exposta ao LLM com schema Pydantic (`ResolveConversationRequest` / `Response`).

### Task 6: Filament UI
- `SpecialistsRelationManager`: nova Tab "Encerramento" (icone `heroicon-o-check-circle`).
- Campos: `Toggle resolution_config.enabled`, `TextInput resolution_config.label_name` (placeholder `resolved-by-ai`), `Textarea resolution_config.customer_message`, `Repeater resolution_config.rules`.
- Allowlist: adicionar `resolve_conversation` na CheckboxList `tools_allowlist` (vem automatico via `NativeToolRegistry::options()`).
- `normalizeSpecialistFormData`: aceitar `resolution_config` (mesmo padrao do handoff_config).

### Task 7: Tests (Pest)
- `tests/Feature/AgentTools/ResolveConversationToolTest.php`:
  - Allowlist exigida (specialist sem `resolve_conversation` → ValidationException).
  - Reason + resolution_summary obrigatorios.
  - AgentRun fica `Completed`, output tem `resolution.reason`, `resolution.resolution_summary`, trace com 2 entradas (tool_call, tool_result).
  - Fallback `customer_message` do `resolution_config` quando payload vazio.
  - Fallback `label_name` idem.
- `tests/Feature/AgentTools/ApplyResolveConversationToChatwootJobTest.php`:
  - Ordem: send_message → add_label → toggle_status.
  - Idempotencia: status atual `resolved` → marca `already_resolved`, nao chama toggle.
  - Erro em sendMessage nao impede label nem toggle.
  - Sem customer_message → skip send_message, segue para label.

### Task 8: ROADMAP update
- `docs/tasks/ROADMAP.md`: mover esta fase de candidata para "Fases entregues" quando merged. Renumerar candidatas (Phase 12 vira ocupada).

## Acceptance Criteria

- Especialista com `resolve_conversation` no allowlist + `resolution_config.enabled=true` pode chamar tool.
- Tool sem allowlist → ValidationException.
- Conversa Chatwoot status `pending` → vira `resolved` apos tool call.
- Cliente recebe mensagem de despedida antes do fechamento.
- Label `resolved-by-ai` (ou customizada) aplicada antes do toggle.
- Idempotencia: 2x resolve nao quebra.
- AgentRun fica `Completed` com `output.resolution` populado.
- Rules: keyword "obrigado" no input → tool resolve auto sem LLM precisar decidir.
- Pest + Pint + Larastan verdes.

## Fora do escopo

- CSAT survey custom (Chatwoot inbox config trata).
- Re-engagement automatico apos reopen pelo cliente (Chatwoot reabre default).
- Analytics dashboard de taxa de resolucao IA (vira em phase posterior).
- Notificacao admin quando IA resolve (similar a Phase 15 do roadmap).

## Fase 12.1 - Tool sempre invocavel via tool_loop (follow-up)

Detectado depois que a IA, mesmo com `resolve_conversation` no allowlist e `resolution_config.enabled=true`, **nunca disparava a tool** quando o especialista tinha contact tools. Causa: o caminho `run_specialist_with_tool_calling` so expoe `EXECUTABLE_TOOLS` (contact tools) e retorna texto direto - o `generate_specialist_decision_with_llm` (que entendia `action=resolve_conversation`) era inalcancavel.

Mudancas:

- `tool_runtime.py`: `resolve_conversation` agora pertence a `EXECUTABLE_TOOLS` (separado de `CONTACT_TOOLS`). Factory `_make_resolve_conversation_tool(ctx, terminal_state)` cria StructuredTool com schema Pydantic (reason, resolution_summary, customer_message opt, label_name opt). Tool dispara `resolve_conversation()` -> Laravel e marca `terminal_state["resolved"] = True` + captura dados.
- `build_specialist_tools`: aceita `terminal_state` opcional. Contact tools so quando `contact_id` presente; resolve tool sempre que `resolve_conversation` no allowlist + terminal_state nao-nulo.
- `run_specialist_tool_loop`: aceita `terminal_state`. Apos cada batch de tool calls, checa `terminal_state["resolved"]` e curto-circuita o loop retornando `ToolLoopResult(resolved=True, resolution=...)`.
- `ToolLoopResult`: gain `resolved: bool` + `resolution: dict | None`.
- `ToolRuntimeContext`: gain `thread_id: str` (resolve_conversation precisa pra payload Laravel).
- `supervisor.run_specialist_with_tool_calling`: nao retorna mais None quando `contact_id is None` - permite executar so com resolve_conversation. Cria `terminal_state` e passa pra `build_specialist_tools` + loop.
- `supervisor.routed_specialist_response`: antes do branch de texto, checa `tool_result.resolved` -> retorna `resolution_response_from_tool_call` com `customer_message` capturado (ou fallback do specialist).
- Nova funcao `resolution_response_from_tool_call` que monta trace contendo o tool_call do resolve + step `specialist_response` com source `resolve_tool`.
- Prompt do especialista no tool_loop ganha linha explicita instruindo quando chamar resolve_conversation e que nao gere mais texto apos.

Tests: `tests/test_tool_runtime.py` ganha 3 cenarios novos (resolve sem contact_id, sem terminal_state, short-circuit do loop quando resolve chama).

## Fase 12.2 - Sync de labels Chatwoot

Antes a label era TextInput livre - admin podia digitar nome de label que nao existia no Chatwoot, causando falha silenciosa no `POST /labels`. Agora label vem de tabela sincronizada.

- Migration `chatwoot_labels` (id, workspace_id, chatwoot_connection_id, chatwoot_label_id, title, description, color, show_on_sidebar, synced_at, timestamps). Unique `(chatwoot_connection_id, title)`.
- `ChatwootLabel` model.
- `ChatwootAdminApiClient::listLabels()`.
- `SyncChatwootLabelsJob` (upsert por title, remove stale, no-op se sem admin token).
- `SyncChatwootMetadataJob` adicionado ao chain (sync manual via botao Filament tambem dispara labels).
- `Schedule::call(...)` horario novo: `chatwoot:sync-labels-hourly`.
- Filament `SpecialistsRelationManager`: TextInput `handoff_config.label_name`, `resolution_config.label_name` e `rules.*.label_name` -> Select com options `chatwootLabelOptions()` (pluck title from chatwoot_labels por workspace).
- Tests `tests/Feature/Jobs/Chatwoot/SyncChatwootLabelsJobTest.php` (upsert + remove stale, skip blank titles, no-op missing connection, no-op missing admin token).
