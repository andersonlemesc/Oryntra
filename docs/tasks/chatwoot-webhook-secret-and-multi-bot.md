# Chatwoot: segredo de webhook manual + múltiplos bots por account

## Contexto
- Achado de segurança: `ResolveChatwootWebhookConnection` aceitava webhook **sem assinatura**
  quando `webhook_secret` estava vazio (fallback para presença de `agent_bot_id`+`api_access_token`).
  Conexão de produção (`id=1`) está `active` com `webhook_secret` vazio → webhook sem verificação.
- Produto: trava de 1 conexão por account (`unique[workspace_id, base_url, account_id]` + check no
  CreateChatwootConnection) impede vários bots na mesma account. Chatwoot permite bots distintos
  por inbox; queremos várias conexões/bots por account.

## Decisões do usuário
- Segredo do webhook é **manual**: Chatwoot gera no bot, usuário copia e cola no Oryntra (edição
  da conexão). Sem geração automática / capability URL.
- Segredo vazio → **rejeita** (hard). Fecha o achado já; a conexão atual fica sem receber webhook
  até colar o segredo.
- Liberar múltiplos bots por account no mesmo branch.

## Mudanças
1. **Migration**: dropar `unique[workspace_id, base_url, account_id]` em `chatwoot_connections`.
   Mantém `unique[workspace_id, name]` (diferencia bots) e `unique[workspace_id, agent_bot_id]`.
2. **`ResolveChatwootWebhookConnection`**: `webhook_secret` vazio → `false` (401). Exige HMAC válido.
3. **`ChatwootConnectionForm`**: trocar display `webhook_secret_status` por campo editável
   `webhook_secret` (password, revealable, dehydrated quando preenchido) — padrão do `admin_api_token`.
4. **`CreateChatwootConnection`**: remover check de duplicata por account; aviso pós-criação instrui
   copiar o webhook secret do Chatwoot e colar na edição da conexão.

## Testes (Pest)
- Atualizar "accepts unsigned webhooks..." → agora rejeita (401) quando secret vazio.
- Webhook 401 sem assinatura / assinatura errada; 200 com assinatura válida (já cobertos, manter).
- Permitir 2 conexões na mesma account (nomes distintos).

---

# Hardening do serviço Python (achados #3, #4, #5)

## #3 SSRF nos downloads (media + RAG)
- Novo `agent/net.py::safe_get`: valida scheme http/https, resolve host e bloqueia
  IP link-local (metadata cloud 169.254.169.254, fe80::/10), multicast, reservado e
  unspecified. **Privado/loopback permitidos** (MinIO/Chatwoot na rede Docker interna).
  Redirect manual por hop (Chatwoot serve mídia via redirect) + corpo em streaming com
  corte por `max_bytes` (aborta cedo em vez de bufferizar tudo).
- `media.py::_download` e `rag.py::_download` agora usam `safe_get`.
- Risco residual documentado: DNS-rebinding (validação != conexão). Defense-in-depth.

## #4 Decompression/zip bomb na extração
- `extract.py`: `_pdf_text` limita a `PDF_TEXT_MAX_PAGES=500`; `_docx_text` soma o tamanho
  descompactado das entradas do zip e rejeita acima de `DOCX_MAX_UNCOMPRESSED_BYTES=200MB`.

## #5 Timing no token interno
- `auth.py`: comparação via `hmac.compare_digest` (constant-time).

## Testes (pytest)
- `tests/test_net_safe_get.py`: bloqueia metadata, bloqueia scheme não-http, baixa de host
  permitido, corta por tamanho, segue+revalida redirect, rejeita redirect→metadata.

## Pendente
- #2 Upload sem validação de bytes reais / sem AV — decisão de abordagem (magic-byte vs ClamAV).
- #6 SSRF de IP privado no ExternalToolExecutor é por design (trust-admin).
- #7 Prompt-injection / RAG poisoning — inerente; mitigado por allowlist de tools + schema.
