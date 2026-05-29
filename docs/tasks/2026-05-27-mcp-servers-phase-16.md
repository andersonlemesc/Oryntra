# Fase 16 — MCP servers por workspace (stub de decisões)

> Stub de decisão, não plano de execução. O detalhamento por Task (com rodadas de
> perguntas) acontece quando a fase iniciar. Aqui só ficam registradas as decisões
> já tomadas para não se perderem.

## Contexto

A IA poder consumir servidores **MCP** cadastrados por workspace — cada tool do MCP
vira tool dinâmica do especialista. Caso de uso âncora: **n8n "MCP Server Trigger"**,
que expõe as tools do fluxo já tipadas (com schema), eliminando a declaração manual de
parâmetros que o nó Webhook cru exige.

## Já entregue na Fase 15 (reaproveitar, não refazer)

- Tabela unificada `external_tools` com coluna `kind` — MCP entra como `kind = 'mcp'`.
- `external_tool_call_logs` (auditoria genérica) já serve para chamadas MCP.
- `ExternalToolExecutor` / dispatch — o MCP vira mais um "executor" sobre a mesma plumbing.
- Python: `build_external_tool` monta `StructuredTool` dinâmico a partir de um
  `param_schema` — a tradução `schema MCP -> param_schema` reusa isso direto.
- Aba "APIs externas" no especialista + reconcile no `tools_allowlist`.

## Decisões fixas

| Tema | Decisão |
|---|---|
| **Transporte** | **Somente Streamable HTTP** (spec 2025-03-26, endpoint sem `/sse`). Alvo = n8n novo (versão sem SSE). |
| **HTTP+SSE (legado, `/sse`)** | **Fora de escopo por enquanto.** n8n antigo expõe `.../mcp/<id>/sse`; quem estiver nessa versão deve subir o n8n ou pôr um bridge (`mcp-proxy`/`supergateway`) convertendo SSE → Streamable HTTP. O client Oryntra fala só Streamable HTTP. |
| **Onde roda o client MCP** | **Laravel** (mantém o invariante "Python nunca toca o mundo externo"). Laravel faz handshake + JSON-RPC via `Http`. |
| **Auth** | Bearer / Header, guardado em `credentials` (cast `encrypted`), igual aos connectors HTTP. |
| **Descoberta de tools** | `list_tools` do MCP traz nome+descrição+schema → cria/sincroniza connectors `kind=mcp` sem declaração manual de params (vantagem sobre o Webhook cru). |
| **Rede** | Mesmo modelo confia-no-admin da Fase 15: URL fixada pelo admin, http interno permitido, só scheme http/https. |

## Esboço do fluxo (Streamable HTTP)

```
Admin cadastra MCP server (URL + auth) -> kind='mcp' em external_tools
  Laravel: POST initialize  (recebe Mcp-Session-Id no header)
         -> POST tools/list  (JSON-RPC) -> sincroniza tools como connectors
LLM chama a tool -> Laravel POST tools/call {name, arguments} (com Mcp-Session-Id)
         -> resposta -> texto pro loop ReAct (log em external_tool_call_logs)
```

Streamable HTTP = POST JSON-RPC num endpoint único + header `Mcp-Session-Id`.
Sem canal SSE separado (request/response basta para tool call; streaming server→client
fica opcional/fora de escopo v1).

## Questões em aberto (resolver no kickoff da fase)

- Modelo de cadastro: 1 linha `external_tools` por **server** (que expande N tools) vs 1 por **tool**? (provável: server + sync gera tools filhas / ou expansão em runtime no payload).
- Sync: botão manual no Filament + schedule (espelhar `SyncChatwootLabelsJob`) vs descoberta on-the-fly a cada run.
- Ciclo de sessão MCP: nova sessão por run vs cache de sessão.
- Tradução de erros MCP (JSON-RPC error) para texto da tool.
- Versionar/validar o `protocolVersion` no `initialize`.

## Referência

- Fase 15 (base reaproveitada): plano local `jaunty-rolling-mango`, entregue em `ROADMAP.md`.
- n8n MCP Server Trigger expõe Streamable HTTP nas versões novas (sem `/sse`); SSE no legado.
