# 2026-05-21 — Specialist-First Handoff Config

> Fase 7.3. Move label + private note template para o especialista. Binding fica como fallback default.

## Motivacao

Hoje `agent_chatwoot_bindings` define `handoff_label_name`, `handoff_private_note_template`, `handoff_assign_strategy`, `handoff_team_id`, `handoff_agent_id`. Especialista (`agent_specialists.handoff_config`) ja override team/agent — mas label e template nao tem override.

Resultado: bot tem 1 label so. Toda transferencia (vendas, suporte, financeiro) sai com mesma label. Mesma nota privada.

Tambem ha ambiguidade conceitual: admin marca `assign_strategy=none` no binding pensando que desliga atribuicao automatica, mas especialista pode ter `team_id` setado e atribuir mesmo assim.

## Escopo

Mover `label_name` + `private_note_template` para `agent_specialists.handoff_config` (jsonb, sem migration). Binding mantem campos como **fallback** quando especialista nao preenche.

Esclarecer no Filament que binding fields sao defaults, nao gates.

## Fora do escopo

- Renomear coluna `handoff_assign_strategy` (breaking, custo > beneficio agora).
- Mover team_id/agent_id do binding (ja existe override no specialist, fluxo ja funciona).
- Apagar campos do binding (mantemos como fallback).

## Shape do `handoff_config` apos mudanca

```json
{
  "enabled": false,
  "team_enabled": false,
  "summary_llm_enabled": false,
  "default_priority": "normal",
  "customer_message": "...",
  "team_id": null,
  "agent_id": null,
  "label_name": null,                    // NOVO
  "private_note_template": null,         // NOVO
  "rules": []
}
```

## Tasks

### Task 1: Normalizer aceita os 2 novos campos
- Arquivo: `app/Filament/Resources/Agents/RelationManagers/SpecialistsRelationManager.php::normalizeSpecialistFormData`.
- Casts: string nullable. Strip empty string → null.

### Task 2: Filament UI — Tab "Transferencia humana"
- Adicionar `TextInput::make('handoff_config.label_name')` — placeholder "Herda do bot" se vazio.
- Adicionar `Textarea::make('handoff_config.private_note_template')` — placeholder "Herda do bot" se vazio.
- `Hint` tooltip: "Quando vazio, usa configuracao do bot Chatwoot."
- Visibilidade: so quando `handoff_config.enabled = true`.

### Task 3: `ApplyHumanHandoffToChatwootJob` resolve label + template do specialist primeiro
- Hoje le do `$binding->handoff_label_name` e `$binding->handoff_private_note_template`.
- Mudar para: specialist.handoff_config.label_name **ou** binding.handoff_label_name.
- Idem para template.
- Quando specialist e null → fica binding. Comportamento atual preservado.

### Task 4: Binding UI hints
- Filament binding form: hint no `handoff_label_name` e `handoff_private_note_template` dizendo "Padrao quando o especialista nao define."
- Hint no `handoff_assign_strategy`: "Fallback quando especialista nao tem team/agent definidos."

### Task 5: Testes
- Feature test: `ApplyHumanHandoffToChatwootJob` com specialist.label_name preenchido → usa specialist.
- Feature test: specialist.label_name vazio + binding.label_name preenchido → usa binding.
- Mesma matriz para template.

## Acceptance Criteria

- Specialist com `label_name='vendas'` + binding com `label_name='geral'` → conversa recebe label 'vendas'.
- Specialist com `label_name=null` + binding com `label_name='geral'` → conversa recebe label 'geral'.
- Specialist com `private_note_template='Vendas: {reason}'` → nota privada usa esse template.
- Template fallback funciona quando specialist nao define.
- Pest verde. Pint verde.
