# Fase 17 — Google Calendar como tool do agente (stub de decisões)

> Stub de decisão, não plano de execução. O detalhamento por Task (com rodadas de
> perguntas) acontece quando a fase iniciar. Aqui só ficam registradas as decisões
> já tomadas para não se perderem.

## Contexto

A IA ganha integração com **Google Calendar**: cada workspace cadastra uma ou mais
conexões OAuth (modelo "credentials reutilizáveis", à la n8n) e, por especialista,
o admin ativa a tool escolhendo **1 conexão + 1 calendar**. A IA pode listar,
criar, editar, deletar eventos e buscar horários livres na agenda do cliente.

**Não escopo:** sincronizar eventos para o banco local, agenda interna do Oryntra,
webhooks push (`watch` API), notificações em tempo real de alterações externas.
A tool é 100% live contra a Google Calendar API — sem persistência de eventos.

## Decisões fixas

| Tema | Decisão |
|---|---|
| **Lib** | `google/apiclient` oficial (SDK completo, padrão indústria, suporta refresh nativo). |
| **OAuth flow** | OAuth2 web flow com `access_type=offline` + `prompt=consent` (garante refresh_token sempre). Scopes: `https://www.googleapis.com/auth/calendar` (RW) + `calendar.events`. |
| **Modelo de conexão** | N conexões por workspace (estilo n8n credentials). Tabela `google_calendar_connections` (account_id, label, google_email, access/refresh tokens encrypted, expires_at, scopes, is_active, created_by_user_id). |
| **Ownership** | Por **workspace/account**, não por user. Conexão é shared resource gerenciado pelo admin. |
| **Permissões** | Mesma regra de `ChatwootConnection` / `ExternalTool` (admin/owner via panel). |
| **Refresh** | Lazy: intercepta 401 → renova via refresh_token → retry uma vez. Sem job/cron. `google/apiclient` já suporta nativo (`setAccessToken` + `fetchAccessTokenWithRefreshToken`). |
| **Revogação externa** | Erro `invalid_grant` marca `is_active=false` + notifica via Filament. Admin re-conecta manualmente. |
| **Filament** | `GoogleCalendarConnectionResource` (nav group "Integrações", próximo de `ExternalToolResource`). Botão "Conectar nova" inicia OAuth → callback grava conexão. Edit: label, default calendar. Disconnect: revoga no Google + soft-delete. |
| **Por especialista** | Aba "Google Calendar" (igual aba "Documentos" da Fase 14.4) com toggles `enabled`, select de `connection_id` e select de `calendar_id` (alimentado por `list_calendars` da conexão escolhida). |
| **HITL** | Nenhum. Tool auto-executa todas as operações (create/update/delete inclusive). Auditoria compensa via log dedicado. |
| **Auditoria** | Nova tabela `google_calendar_audit_logs` (account_id, connection_id, agent_run_id, action, calendar_id, event_id_google, payload, error, timestamp). Não reusa `external_tool_call_logs` — semântica e campos próprios (event_id, attendees, datas). |
| **Attendees** | Convites enviados por padrão (`sendUpdates=all`). Param opcional na tool permite override. |
| **Timezone** | Usa `workspace.timezone` injetado no contrato runtime (Fase 12.4 já fez). Tool passa `timeZone` em todas as calls Google. Memória `open_source_neutral` respeitada (sem TZ hardcoded). |
| **Onde roda o client Google** | **Laravel** (mantém invariante "Python nunca toca o mundo externo"). Python recebe schema da tool e roteia call para endpoint interno `/api/internal/agent-tools/call-google-calendar`. |

## Tools que a IA vai receber

1. `gcal_list_events(time_min, time_max, query?)` — lista eventos
2. `gcal_create_event(summary, start, end, attendees[]?, description?, location?, notify_attendees?)` — cria
3. `gcal_update_event(event_id, ...campos)` — edita
4. `gcal_delete_event(event_id, notify_attendees?)` — remove
5. `gcal_find_free_slots(duration_min, range_start, range_end, working_hours?)` — busca via freebusy API

`connection_id` e `calendar_id` **não** são parâmetros da tool — vêm fixos da config do especialista (resolvidos no Laravel antes de chamar Google). Modelo nunca os vê nem inventa.

## Esboço do fluxo

```
Admin Filament → "Connect Google Calendar"
  → redirect Google OAuth (consent screen)
  → callback /oauth/google-calendar/callback
  → salva access_token + refresh_token (encrypted) em google_calendar_connections
  → admin define label + default_calendar

Especialista Filament → aba "Google Calendar"
  → toggle enabled + select connection + select calendar
  → reconcile tools_allowlist (gcal_* entries)

Runtime:
  LLM chama gcal_create_event(summary=..., start=..., end=..., attendees=[...])
  → Python StructuredTool faz POST /api/internal/agent-tools/call-google-calendar
  → Action::CallGoogleCalendar resolve specialist → connection → calendar
  → GoogleCalendarClient::createEvent (refresh lazy se 401)
  → grava google_calendar_audit_logs
  → retorna {event_id, html_link, status} ao loop ReAct
```

## Esforço estimado

~1 semana focada. Pequeno-médio. Sem sync, sem webhooks, sem dashboard de eventos = corta ~40% do trabalho típico de integração de calendar.

**Pre-req:** Google Cloud project com OAuth consent screen configurado + Client ID/Secret nos env vars (`GOOGLE_CALENDAR_CLIENT_ID`, `GOOGLE_CALENDAR_CLIENT_SECRET`, `GOOGLE_CALENDAR_REDIRECT_URI`).

## Questões em aberto (resolver no kickoff)

- Verified app vs unverified: Google limita ~100 users em apps não-verificados em scopes sensíveis (`calendar` é sensível). Decidir: começar unverified (DEV) → ir p/ verification antes de prod aberto?
- `calendar_id` na config do especialista: validar contra `list_calendars` no Filament (UX bom mas custa request OAuth ao abrir form) vs free text (rápido mas risco de inválido)?
- Multi-calendar future (1 conexão + N calendars): manter porta aberta no schema (`config.calendar_ids[]` em vez de `config.calendar_id`) ou só refatorar quando precisar?
- Refresh token expiração silenciosa: notificação ao admin (email/Filament notification) quando uma conexão morre? Hoje conexão Chatwoot quebrada também só mostra status no panel.
- Cleanup de conexões revogadas: hard delete depois de N dias inativos vs manter pra auditoria histórica?
- Pintar eventos criados pela IA: prefixar `summary` com tag (`[Oryntra]`) ou usar `extendedProperties.private.created_by=oryntra` (mais limpo, filtrável via API)?
- Recurrence (eventos recorrentes): scope v1 ou só single events? Modelo p/ propor "reunião toda segunda" pede `RRULE` — complexidade extra.

## Referência

- Padrão de conexão multi-instance: `ChatwootConnection` + `ChatwootConnectionResource`.
- Padrão de tool ativável por especialista: aba "Documentos" da Fase 14.4 (`document_tools_config`).
- Padrão de credentials encrypted + auth dispatch: `ExternalTool` da Fase 15.
- Padrão de audit log: `ChatwootWebhookEvent` (genérico) ou `external_tool_call_logs` (executor) — Calendar terá tabela própria por ter semântica de evento estruturada.
- Memória `feedback_open_source_neutral`: TZ vem do workspace, não hardcoded.
