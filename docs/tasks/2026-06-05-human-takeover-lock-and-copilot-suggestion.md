# Human takeover lock + modo copilot (suggestion)

**Data:** 2026-06-05
**Status:** implementado

## Resolucao das dúvidas abertas

- **Handoff/resolve no copilot:** decidido no Laravel. `deliverSuggestionToChatwoot()` sempre entrega
  como nota privada; nenhuma acao publica dispara. Nao foi preciso mexer no runtime Python.
- **`send_document` no copilot:** posta nota privada textual (caption, ou fallback
  "Sugestao: enviar documento ao cliente."). Nao envia arquivo.
- **Gatilho de lock:** só resposta publica do humano (`sender_type ∈ {user, agent}`); `agentbot` e
  notas privadas nao disparam. Auto-atribuicao ficou fora.

## Contexto

Configuração de agente tem `response_mode` (`App\Enums\AgentResponseMode`): `automatic`,
`suggestion_only`, `human_approval`. O enum está persistido e no form Filament, mas **morto** —
`FinalizeAgentRunJob::deliverResponseToChatwoot()` entrega sempre, sem consultar o modo.

Dois problemas reais a resolver:

1. **Modo automático sem trava de intervenção humana.** Não existe guard de status/assignee/takeover
   no pipeline de ingestão (`ClassifyChatwootWebhookEvent`). Se um atendente humano entra numa conversa
   que o bot está atendendo (pra puxar pra ele), o bot continua respondendo as próximas mensagens do
   cliente. As únicas travas hoje são: `private`, `message_type != incoming`, `sender_type != contact`.
   Confirmado: a resposta *outgoing* do humano é ignorada, mas a *próxima incoming do cliente* é
   reprocessada. Mesmo bug latente após handoff.

2. **`human_approval` não faz sentido por-mensagem.** Aprovar toda resposta duplica o que o handoff por
   baixa confiança já faz. `suggestion_only` (copilot) é o modo útil de "humano acompanha e responde
   baseado na IA", mas precisa abrir a conversa (senão cai em `pending` e ninguém vê) e entregar como
   nota privada.

## Decisões (fechadas com o usuário)

- **Modos finais:** `automatic` (autopilot) e `suggestion` (copilot). **Deletar `human_approval`.**
- **Lock de takeover (só no modo automático):** dispara **apenas** por **resposta pública do humano**
  (`message_created`, `outgoing`, `sender_type ∈ {user, agent}`, `private:false`). NÃO usar
  auto-atribuição como gatilho — evita a armadilha de inbox com auto-assignment travar o bot na criação.
- **Unlock:** `conversation_status_changed → resolved` limpa o lock. Resolve o caso de inbox configurado
  pra *reabrir a mesma conversa* (mesmo `conversation_id` reusado; lock já caiu no resolved).
- **Copilot:** entrega como **nota privada**, abre a conversa (`toggleConversationStatus('open')`),
  e **sugere sempre** (toda msg do cliente gera nova sugestão private). Lock de takeover **não se aplica**
  ao copilot.

## Sinais do Chatwoot (confirmados em docs/integrations/chatwoot/README.md)

- `message_created` — gatilho do agente e do lock (resposta pública do humano).
- `conversation_status_changed` — `status`: 0=open, 1=resolved, 2=pending, 3=snoozed → unlock no resolved.
- `senderType()` faz lowercase: bot próprio = `agentbot`, humano = `user`/`agent`, cliente = `contact`.
- Ambos eventos chegam hoje mas morrem em `ignore('unsupported_event')` / `ignore('not_incoming_message')`.

## Escopo / passos

### 1. Persistência do estado por-conversa

- **Migration nova:** `chatwoot_conversation_states`
  - `id`, `workspace_id`, `chatwoot_connection_id`, `conversation_id`
  - `human_takeover_at` (timestamp, nullable)
  - timestamps
  - **Unique** `(chatwoot_connection_id, conversation_id)`
  - FKs pra workspaces/chatwoot_connections (seguir convenção das migrations existentes)
- **Model novo:** `App\Models\ChatwootConversationState` com `upsert`/helper
  `markHumanTakeover()` e `clearHumanTakeover()`.

### 2. Detecção de transições de estado (lock/unlock)

Plugar em `ProcessChatwootWebhookEventJob::handle()` **dentro do `Cache::lock`** (já serializa por
conversa), antes do gating de `should_process`.

- **Nova action** `App\Actions\Chatwoot\ApplyConversationStateFromWebhook` recebendo o `ChatwootWebhookEvent`:
  - Se `event_name === 'conversation_status_changed'` e status normalizado é `resolved` →
    `clearHumanTakeover()`. (registrar `ignored_reason = conversation_resolved_unlock`.)
  - Se `event_name === 'message_created'` e `message_type === 'outgoing'` e `private === false`
    e `sender_type ∈ {user, agent}` → `markHumanTakeover()`.
    (registrar `ignored_reason = human_takeover_recorded`.)
  - **Importante:** `sender_type === 'agentbot'` NÃO dispara (é o próprio bot).
- `ClassifyChatwootWebhookEvent::normalize()` precisa também extrair `conversation.status`
  (mapear int→string: 0=open,1=resolved,2=pending,3=snoozed) pros eventos `conversation_*`.
  Hoje só extrai campos de `message_created`.

Ordem no job: rodar `ApplyConversationStateFromWebhook` → se ele tratou o evento (takeover/unlock),
marcar `ignored` com o reason e `return`. Senão segue o fluxo `message_created` normal.

### 3. Guard de ingestão (lock ativo bloqueia o bot)

Após `resolveAgent->execute()` (já temos o agente e seu `response_mode`), antes do
`enqueueAgentRun->execute()`:

- Se `agent->response_mode === AgentResponseMode::Automatic`
  **e** existe `human_takeover_at` pra `(connection, conversation)` →
  `ignore('human_takeover_active')` e `return`.
- Modo `suggestion` → ignora o guard (copilot segue sugerindo).

(Alternativa de design: mover esse check pra dentro de `ResolveAgentForChatwootEvent`, que já retorna
`ignored_reason`. Decidir na implementação — o job tem o lock; a action é mais testável.)

### 4. Entrega por modo (copilot)

Em `FinalizeAgentRunJob::deliverResponseToChatwoot()` (L152+), ramificar pela `response_mode` do agente:

- `Automatic` → comportamento atual (`sendConversationMessage`, público).
- `Suggestion`:
  - No **primeiro turno** da conversa, `toggleConversationStatus('open')` (abrir pra atendente ver).
  - Entregar conteúdo via `ChatwootAgentBotClient::addPrivateNote()` (nunca público).
  - Marcar `response_delivery.mode = 'suggestion'`.
  - **Nunca** chamar `sendConversationMessage` no copilot.
- `send_document` em copilot: enviar como nota privada com a sugestão de qual documento mandar
  (não disparar `SendDocument` público). *Decidir:* MVP pode só postar texto "sugiro enviar doc X".

Handoff/resolve no copilot: humano já dirige; `request_human_handoff`/`resolve_conversation`
devem ser neutralizados ou não expostos. *Verificar* se o runtime Python decide isso por modo ou se
basta o Laravel pular os side-effects. MVP: manter ferramentas, mas no copilot a entrega final é sempre
nota privada (nenhuma ação pública dispara).

### 5. Remover `human_approval`

- `App\Enums\AgentResponseMode`: remover `case HumanApproval` e o braço do `label()`.
- `AgentForm.php` (L80-85): remover opção do select; ajustar tooltip ("Automatico = IA responde direto.
  Sugestao = IA posta nota privada pro atendente, humano responde.").
- **Migration nova** pra alterar o CHECK constraint de `agents.response_mode`
  (`2026_05_17_191703_create_agents_table.php` define
  `CHECK (response_mode IN ('automatic','suggestion_only','human_approval'))`) →
  remover `human_approval`. Antes, migrar dados: `UPDATE agents SET response_mode='automatic'
  WHERE response_mode='human_approval'`.
- Renomear valor? Manter `suggestion_only` como string do enum (sem rename de DB) pra evitar migração
  de dados desnecessária; só o label muda.

## Edge cases

- **Bot próprio vs humano:** `sender_type` lowercased — garantir match em `user`/`agent`, excluir
  `agentbot`. Adicionar fixture de teste com `agentbot` pra provar que NÃO trava.
- **Auto-assignment do inbox:** não é gatilho (decisão), então não afeta. Documentar.
- **Reabrir mesma conversa:** unlock no `resolved` cobre. Se o cliente volta e a conversa reabre
  (`pending`/`open`) sem ter passado por `resolved`, o lock persiste de propósito (humano ainda dono).
- **Resolved disparado pelo próprio bot** (`resolve_conversation`): limpa lock — inofensivo, conversa
  encerrada.
- **Snoozed (3):** não destrava.
- **Corrida:** `Cache::lock` por conversa no job já serializa detecção + ingestão.

## Testes (Pest, feature)

- `human public reply (user, outgoing, public) grava human_takeover_at`.
- `agentbot outgoing NÃO grava takeover`.
- `private note de humano NÃO grava takeover`.
- `incoming do cliente com takeover ativo + modo automatico é ignorado (human_takeover_active)`.
- `incoming do cliente com takeover ativo + modo suggestion NÃO é ignorado`.
- `conversation_status_changed resolved limpa o lock` → próxima incoming volta a processar.
- `copilot entrega como nota privada e abre a conversa, nunca público`.
- `handoff (ApplyHumanHandoffToChatwootJob) também grava human_takeover_at` (conserta bug latente).
- Remoção de `human_approval`: agentes legados migram pra `automatic`; form não oferece a opção.

## Fora de escopo

- Toggle manual no Filament pra devolver conversa ao bot (unlock só por resolved, por ora).
- Lock por auto-atribuição / `conversation_updated` assignee.
- Copilot parar de sugerir após N respostas do humano (decidido: sugere sempre).
- UI de inbox de sugestões fora do Chatwoot.

## Arquivos-chave

- `laravel/app/Enums/AgentResponseMode.php`
- `laravel/app/Filament/Resources/Agents/Schemas/AgentForm.php` (L80-85)
- `laravel/app/Actions/Chatwoot/ClassifyChatwootWebhookEvent.php` (normalize + status)
- `laravel/app/Jobs/Chatwoot/ProcessChatwootWebhookEventJob.php` (detecção + guard)
- `laravel/app/Actions/Chatwoot/ResolveAgentForChatwootEvent.php` (guard alternativo)
- `laravel/app/Jobs/Agent/FinalizeAgentRunJob.php` (entrega por modo, L152+)
- `laravel/app/Jobs/Agent/ApplyHumanHandoffToChatwootJob.php` (gravar takeover, L70)
- `laravel/app/Services/Chatwoot/ChatwootAgentBotClient.php` (`addPrivateNote`, `toggleConversationStatus`)
- migrations: nova `chatwoot_conversation_states`, nova alteração do CHECK em `agents.response_mode`
