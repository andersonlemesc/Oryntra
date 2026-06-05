# Chatwoot Captain — Referência para RAG + Copilot (Oryntra)

> Fonte: fork privado em `/home/anderson/chatwoot-privado` (módulo enterprise `enterprise/app/{models,services,jobs,controllers}/captain`).
> Objetivo: documentar como o Chatwoot implementa RAG, extração de PDF, geração de FAQ e ferramentas de Copilot, identificando o que vale reaproveitar / alinhar com a Fase 10 de Oryntra (`docs/tasks/2026-05-20-rag-knowledge-base-phase-10.md`) e a decisão `docs/decisions/0005-pgvector-over-dedicated-vector-db.md`.

---

## 1. Stack de RAG — visão geral

| Componente | Chatwoot Captain | Oryntra (Fase 10 planejada) |
|---|---|---|
| Extensão vetorial | `pgvector` (extension `vector`) | mesma |
| Gem/lib ORM vetorial | `neighbor` + `pgvector` (Ruby) | `pgvector-php` (Laravel) |
| Provedor LLM | `ruby_llm` (multi-provider) + `ruby-openai` (legado p/ Files API) | OpenAI default, BYOK configurável |
| Modelo de embedding | `text-embedding-3-small` (1536 dims) | mesmo (default) |
| Modelo de chat | `gpt-4.1` default; `gpt-4.1-mini` p/ PDF | configurável |
| Dimensão coluna vetor | `vector(1536)` | `vector(1536)` |
| Índice vetorial | `ivfflat` com `vector_l2_ops` no schema, mas `has_neighbors normalize: true` + busca `cosine` no app | IVFFlat com `vector_cosine_ops` (plano Fase 10) |
| Distância usada na busca | `cosine` (`nearest_neighbors(:embedding, e, distance: 'cosine')`) | `cosine` |
| Top-K | hardcoded `.limit(5)` | configurável via `rag_config.top_k` |
| Limiar de similaridade | `DISTANCE_THRESHOLD = 0.3` (para deduplicação de FAQ) | `min_score` configurável |
| Tenancy | scope por `account_id` + `assistant_id` | scope por `workspace_id` (mais granular) |

### Pontos canônicos no código

- **Constantes LLM**: `lib/llm_constants.rb`
  ```ruby
  DEFAULT_MODEL = 'gpt-4.1'
  DEFAULT_EMBEDDING_MODEL = 'text-embedding-3-small'
  PDF_PROCESSING_MODEL = 'gpt-4.1-mini'
  ```
- **Embedding service**: `enterprise/app/services/captain/llm/embedding_service.rb`
  ```ruby
  RubyLLM.embed(content, model: model).vectors
  ```
  Instrumentado via OpenTelemetry / Langfuse (`span_name: 'llm.captain.embedding'`).
- **Modelo com vetor**: `enterprise/app/models/captain/assistant_response.rb`
  ```ruby
  has_neighbors :embedding, normalize: true

  def self.search(query, account_id: nil)
    embedding = Captain::Llm::EmbeddingService.new(account_id: account_id).get_embedding(query)
    nearest_neighbors(:embedding, embedding, distance: 'cosine').limit(5)
  end
  ```
- **Migration de criação**: `db/migrate/20250104200055_create_captain_tables.rb`
  - Habilita extension `vector` no início (`enable_extension 'vector'`), e falha a migration explicitamente se não conseguir.
  - Cria coluna `t.vector :embedding, limit: 1536`.
  - Índice `ivfflat`, `opclass: :vector_l2_ops` (apesar do código usar cosine — `normalize: true` no `has_neighbors` torna L2 equivalente a cosine para vetores normalizados; vale validar no Oryntra).

---

## 2. Estratégia de "chunking" — **não há chunking tradicional**

Diferença arquitetural importante:

| Abordagem | Chatwoot Captain | Oryntra Fase 10 (plano) |
|---|---|---|
| Unidade indexada | **Par Q&A (FAQ)** gerado por LLM a partir do documento | **Chunk de texto** (~500-1k tokens) do documento bruto |
| Tabela vetorial | `captain_assistant_responses` (`question`, `answer`, `embedding`) | `document_chunks` (`content`, `embedding`) |
| Embedding feito sobre | `"#{question}: #{answer}"` | conteúdo bruto do chunk |
| Curadoria humana | FAQs têm `status` (`pending`/`approved`), `edited` boolean | não previsto |
| Documento original | `captain_documents.content` (text até 200k chars), com `pdf_file` (Active Storage) | `agent_documents` + `MinIO` |
| Fingerprint p/ resync | `Digest::SHA256` do conteúdo normalizado (`gsub(/\s+/, ' ').strip`) | não previsto |

### Trade-offs

**Vantagens da abordagem FAQ-first do Captain:**
- Sem perda de contexto por fronteira de chunk.
- Atomicidade semântica: cada FAQ é resposta auto-contida (system prompt força isso explicitamente).
- Permite revisão humana antes de virar resposta do agente (`status: pending` → `approved`).
- Deduplicação por similaridade vetorial entre FAQs já existentes (threshold 0.3 cosine).
- Menos custo de retrieval (menos vetores que chunking).

**Desvantagens:**
- Custo upfront alto: cada documento gera múltiplas chamadas LLM para gerar FAQs.
- Para PDFs longos faz paginação (10 páginas por chunk, max 20 iterações).
- Risco de o LLM "perder" detalhe que não vira FAQ.
- Re-geração necessária quando o source muda (controlado por SHA256 fingerprint).

**Recomendação Oryntra:** considerar **modelo híbrido**: chunking padrão para corpus grande (manual, base de conhecimento), com geração opcional de FAQ derivada de conversas resolvidas (vide §4.1). Documentar como ADR antes de mudar a Fase 10.

---

## 3. Extração de PDF — **Chatwoot NÃO usa lib local de PDF**

Investigamos `Gemfile`/`Gemfile.lock`: zero gems de PDF (`pdf-reader`, `hexapdf`, `origami`, `combine_pdf`, etc.).

**Como funciona** (`enterprise/app/services/captain/llm/pdf_processing_service.rb`):

```ruby
def upload_pdf_to_openai
  with_tempfile do |temp_file|
    response = @client.files.upload(parameters: { file: temp_file, purpose: 'assistants' })
    response['id']
  end
end
```

1. PDF anexado via Active Storage (`has_one_attached :pdf_file`, limite 10MB).
2. Upload do PDF cru para **OpenAI Files API** (`purpose: 'assistants'`) — retorna `openai_file_id`, persistido em `metadata.openai_file_id`.
3. Geração de FAQ usa o `file_id` no payload multimodal:
   ```ruby
   { type: 'file', file: { file_id: @document.openai_file_id } }
   ```
4. Paginação delegada ao próprio modelo: o prompt pede explicitamente "páginas X a Y" e o LLM lê do file uploadado.

### Implicações para Oryntra

- A Fase 10 plana usar pipeline local (extrair texto + chunk + embed). Chatwoot delega a parsing para o OpenAI.
- **Prós da abordagem Chatwoot**: zero código de parsing; suporta PDFs com layout complexo, tabelas, imagens (vision); funciona para outros formatos suportados pela Files API.
- **Contras**: lock-in OpenAI; custo por chamada; perde portabilidade BYOK (decisão `0006-byok-llm-keys`); upload do arquivo bruto fora do tenant.
- **Escolha do Oryntra:** **`spatie/pdf-to-text`** (wrapper do `pdftotext` do `poppler-utils`).
  - Binário C nativo: streaming + baixo RSS, escala melhor que `smalot/pdfparser` (puro PHP carrega tudo em memória).
  - Dependência: `poppler-utils` instalado no container (`apt-get install -y poppler-utils` no `Dockerfile`).
  - Trade-off: extração só de texto (sem layout/tabelas avançado). Para tabelas, considerar fallback futuro (`tika`/`marker`/`docling`) por feature flag.
- **Alternativas avaliadas:**
  - PHP puro: `smalot/pdfparser` — sem deps nativas, mas alto consumo de memória em PDFs grandes. **Descartado.**
  - Python sidecar: `pypdf`, `pdfplumber`, `unstructured` — útil se precisarmos OCR/layout depois.
  - Para layouts complexos: `marker`/`docling` (open-source) ou Tika via container.

**Recomendação atual:** `spatie/pdf-to-text` + `poppler-utils` para extração. Manter BYOK + tenancy. Gateway opcional "PDF avançado via OpenAI Files API" pode entrar depois como feature flag por workspace.

---

## 4. Features do Copilot reaproveitáveis

### 4.1. **FAQ a partir de conversas resolvidas** ⭐ (pedido pelo usuário)

Arquivo: `enterprise/app/services/captain/llm/conversation_faq_service.rb`

Pipeline:
1. Após conversa resolvida (com interação humana real — `conversation.first_reply_created_at` presente), job dispara `ConversationFaqService`.
2. Manda histórico (`conversation.to_llm_text`) ao LLM com prompt `conversation_faq_generator`:
   > "You are a support agent looking to convert the conversations with users into short FAQs..."
3. Para cada FAQ gerada, embedda `"#{question}: #{answer}"` e compara contra FAQs existentes do assistant (cosine + threshold `0.3`).
4. Se for nova (sem vizinho próximo), cria `Captain::AssistantResponse` com `status: pending` e `documentable: conversation` (rastreia origem).
5. Curador humano aprova/edita pela UI Filament equivalente.

**Adaptação Oryntra:**
- Criar `Oryntra\Agent\Services\ConversationFaqService` análogo.
- Trigger: hook do webhook Chatwoot `conversation_resolved` (já temos integração).
- Tabela: pode reusar `document_chunks` (com `source_type: 'conversation_faq'`) ou nova `agent_faqs` espelhando o modelo do Chatwoot.
- UI Filament: lista de FAQs pendentes para revisão (campo `status: pending|approved`).
- Adicionar `chatwoot_conversation_id` em `documentable` para drill-down.

### 4.2. **Ferramentas do Copilot (function-calling sobre dados internos)**

Arquivo central: `enterprise/app/services/captain/copilot/chat_service.rb`

Tools registrados:
- `search_documentation` — RAG sobre FAQs (já é a busca vetorial).
- `Copilot::GetConversationService` — pega detalhe de uma conversa.
- `Copilot::SearchConversationsService` — busca por status/contact/priority/labels (até 100 resultados).
- `Copilot::GetContactService` — detalhe de contato.
- `Copilot::SearchContactsService` — busca contatos.
- `Copilot::GetArticleService` / `SearchArticlesService` — Help Center.
- `Copilot::SearchLinearIssuesService` — integração Linear (exemplo de integração externa).

Padrão da `BaseTool` (`enterprise/app/services/captain/tools/base_tool.rb`): cada tool declara `param :nome, type:, desc:, required:` e expõe `execute(**)`. Tools podem se auto-desabilitar via `active?` (checa permissão do user, ex: `conversation_manage`).

**Adaptação Oryntra:**
- Já temos `NativeToolRegistry` (Fase 8). Adicionar tools análogos:
  - `search_chatwoot_conversations(status, contact_id, priority, labels)`.
  - `get_chatwoot_contact(contact_id)`.
  - `search_chatwoot_contacts(query)`.
- Manter permission check por workspace_id no `active?`.
- Persistência de threads (`copilot_threads` + `copilot_messages`) já alinhada com nossa `agent_runs` + step events.

### 4.3. **System prompts curados** (`captain/llm/system_prompts_service.rb`, 25KB)

Vale ler integralmente para inspiração. Tópicos cobertos:
- `faq_generator(language)` — extração FAQ orientada à completude/auto-contenção/anti-deflexão.
- `conversation_faq_generator(language)` — FAQ a partir de conversa.
- `notes_generator(language)` — converte conversa em notas para CRM.
- `attributes_generator` — extrai atributos de contato.
- `assistant_action_classifier` — decide handoff vs continuar (classifier dedicado, retorna `action_reason` enum estruturado).
- `assistant_response_generator` — prompt de resposta do agente.
- `copilot_response_generator` — prompt do copilot interno (para agente humano).
- `paginated_faq_generator(start_page, end_page, language)` — para PDF.
- `widget_tagline_service` — gera tagline contextual.
- `translate_query_service` — traduz query do usuário para o idioma do tenant antes da busca.

Particularmente úteis:
- **Translate-then-search**: `SearchDocumentationService` traduz a query do usuário para o idioma da conta antes de embedar e buscar. Mitiga problema de RAG multilíngue. **Recomendado portar para Oryntra**.
- **Action classifier separado**: handoff decision não é mesclado com resposta — é uma chamada LLM separada com prompt minimalista que retorna `{action, action_reason, source}`. Reduz alucinação. **Já parcialmente coberto pelo nosso HumanHandoffTool (Fase 7)**, vale revisar.

### 4.4. **Web crawler para sincronizar docs de URLs públicas**

Arquivos:
- `enterprise/app/services/captain/documents/single_page_fetcher.rb` — fetcher com fallback (Firecrawl prioritário, `SimplePageCrawlService` como fallback).
- `enterprise/app/services/captain/documents/sync_service.rb` — orquestra fetch → fingerprint SHA256 → update se mudou.
- `enterprise/app/services/captain/tools/firecrawl_service.rb` — wrapper da Firecrawl API (`onlyMainContent: true`, formato markdown, exclude tags de chrome).
- `enterprise/app/services/captain/tools/simple_page_crawl_service.rb` — Nokogiri + `SafeFetch` (mitiga SSRF), converte HTML→markdown, suporta sitemap.xml.

Sync periódico via `Captain::Documents::ScheduleSyncsJob` (re-sync de docs externos staled).

**Adaptação Oryntra:** vale considerar para Fase 10.x — permite admins adicionarem URLs (changelog, docs públicas, base de conhecimento externa) que se atualizam automaticamente.

### 4.5. **Outras ideias úteis observadas**

- **Custom HTTP tools**: `enterprise/app/services/captain/tools/custom_http_tool.rb` — admins definem ferramentas customizadas via UI (URL, headers, schema de params). Equivalente ao que MCP faz, mas in-house.
- **Custom tool registry**: `captain_custom_tools` table + `tool_registry_service`. Permite tenants definirem tools sem deploy.
- **Scenarios**: `Captain::Scenario` — "cenários" pré-definidos (ex: "cancelamento", "reembolso") com `handoff_key`/`description`. Aparecem no system prompt para guiar a IA. Útil para playbooks de atendimento.
- **Guardrails + response_guidelines**: campos jsonb no `Captain::Assistant` injetados no system prompt. Equivalente ao nosso `agent_specialists.config`.
- **PermissionFilterService**: usado nas tools de Copilot para nunca expor conversas fora do escopo do agente humano. **Importante portar** para nossas tools de Copilot.

---

## 5. Modelos de dados (espelho resumido)

```
captain_assistants
  id, account_id, name, description, config jsonb,
  guardrails jsonb, response_guidelines jsonb

captain_documents
  id, account_id, assistant_id,
  external_link (URL ou "PDF: filename_ts"),
  content text (max 200k), content_fingerprint sha256,
  metadata jsonb (openai_file_id, sync_step, last_sync_error_code),
  status enum (in_progress, available),
  sync_status enum (syncing, synced, failed),
  last_sync_attempted_at, last_synced_at
  has_one_attached :pdf_file (Active Storage)

captain_assistant_responses  ← TABELA VETORIAL
  id, account_id, assistant_id,
  question, answer,
  embedding vector(1536),
  status enum (pending, approved),
  edited boolean,
  documentable_type / documentable_id (polimórfico:
    aponta para Captain::Document OU Conversation)

captain_scenarios
  id, assistant_id, title, description, handoff_key, enabled

captain_custom_tools
  id, account_id, slug, name, description,
  enabled, http_method, url, headers jsonb, parameters jsonb

captain_copilot_threads + captain_copilot_messages
  thread por (account, user, assistant) com histórico
```

---

## 6. Fluxos-chave (sequência simplificada)

### A. Ingestão de URL → FAQ aprovado
```
admin cria Captain::Document(external_link)
 → after_create_commit enqueue Captain::Documents::CrawlJob
   → SyncService → SinglePageFetcher (Firecrawl ou Nokogiri) → markdown
   → fingerprint SHA256 → se mudou, update content + status=available
 → after_commit enqueue Captain::Documents::ResponseBuilderJob
   → FaqGeneratorService → LLM gera lista de FAQs
   → cada FAQ vira Captain::AssistantResponse
     → after_commit Captain::Llm::UpdateEmbeddingJob
       → embedda "Q: A" e salva no vetor
```

### B. Ingestão de PDF
```
admin upload PDF (≤10MB)
 → before_validation seta external_link = "PDF: filename_ts"
 → CrawlJob detecta pdf_document? → PdfProcessingService
   → upload PDF cru à OpenAI Files API → grava openai_file_id
 → ResponseBuilderJob detecta pdf+file_id → usa PaginatedFaqGeneratorService
   → loop 10 págs/chunk, max 20 iters, dedup interna por similaridade léxica
   → cria FAQ → embedda como em (A)
```

### C. Conversa resolvida → FAQ candidato
```
conversation.resolved + first_reply_created_at presente
 → ConversationFaqService.generate_and_deduplicate
   → LLM gera FAQs do histórico
   → para cada FAQ: embedda, busca vizinhos (cosine, threshold 0.3)
     → se ≥ um vizinho: descarta (duplicate)
     → senão: cria como Captain::AssistantResponse(status: pending, documentable: conversation)
 → curador humano revisa na UI → approved
```

### D. Query do agente
```
conversation.pending? + bot → Captain::Conversation::ResponseBuilderJob
 → AssistantChatService.generate_response
   → tools = [SearchDocumentationService, ...custom_tools]
   → ChatHelper.request_chat_completion (RubyLLM, tool-calling loop)
     → SearchDocumentationService.execute(query)
       → TranslateQueryService.translate(query, target: account_locale)
       → assistant.responses.approved.search(translated) → top-5 cosine
       → formata "Q: ... A: ... Source: ..."
   → resposta final → cria Message outgoing
```

### E. Copilot (assistente do agente humano)
```
agent humano abre painel Copilot na conversa
 → Captain::Copilot::ResponseJob.perform(...)
   → Captain::Copilot::ChatService (CopilotThread persistido)
     → tools incluem busca de conversas/contatos/artigos/Linear
     → LLM responde stream-wise (SSE / Action Cable)
```

---

## 7. O que adotar / o que evitar no Oryntra

### Adotar ✅
- **`text-embedding-3-small` (1536 dims) como default** — já planejado, alinhado.
- **Translate-then-search** para RAG multilíngue (`TranslateQueryService` análogo).
- **FAQ derivado de conversas resolvidas** com fluxo `pending → approved` em Filament. **Pedido explícito do usuário**.
- **Fingerprint SHA256 do conteúdo** para detectar mudanças e evitar re-embedding desnecessário.
- **Documentable polimórfico** no vetor: rastreia origem (Document, Conversation, ManualEntry, etc.).
- **Action classifier separado** para decisão de handoff (já parcialmente em Fase 7).
- **PermissionFilterService** em todas as tools do Copilot (workspace + role).
- **Web sync com fingerprint + Firecrawl opcional** se quisermos suportar URLs no futuro.
- **Custom tools persistidos no banco** (alternativa simples ao MCP para tenants).

### Evitar / decidir caso-a-caso ⚠️
- **Pular chunking e ir direto a FAQ-only**: arriscado para corpus diverso (docs técnicas, contratos). Decisão arquitetural — abrir ADR se mudarmos da Fase 10.
- **Upload PDF cru à OpenAI Files API**: viola spirit de BYOK (decisão `0006`). Manter parser local como default, deixar como opção feature-flag por workspace.
- **`vector_l2_ops` no índice + cosine no app**: funciona porque usam `normalize: true`, mas é confuso. **Usar `vector_cosine_ops` no índice** (já é o plano da Fase 10).
- **`.limit(5)` hardcoded**: Oryntra já planeja `rag_config.top_k` configurável. Manter assim.
- **OpenAI Assistants Files API** (`purpose: 'assistants'`) está sendo descontinuada em favor de `purpose: 'user_data'`. Validar antes de portar.

### Não adotar ❌
- Misturar tabelas `captain_documents.content` (200k chars) com tabela de vetores: nosso plano com `agent_documents` + `document_chunks` separados é mais saudável.
- Acoplamento direto LLM ↔ Active Storage: nosso pipeline via MinIO + jobs Horizon é mais limpo.

---

## 8. Gems / libs relevantes (referência)

```ruby
# Gemfile do Chatwoot — extrato
gem 'neighbor'        # ActiveRecord <-> pgvector
gem 'pgvector'        # adapter pgvector p/ pg gem
gem 'ruby-openai'     # cliente OpenAI direto (usado p/ Files API legado)
gem 'ruby_llm', '>= 1.14.1'   # multi-provider (OpenAI, Anthropic, Google, Mistral, DeepSeek)
gem 'ruby_llm-schema'
```

Equivalentes Laravel/PHP:
- `pgvector/pgvector-php` — bindings pgvector p/ PHP/Doctrine/Eloquent.
- `openai-php/laravel` — cliente OpenAI.
- `prism-php/prism` ou `llphant/llphant` — multi-provider LLM PHP.
- `spatie/pdf-to-text` (escolhido) — wrapper do `pdftotext`/`poppler-utils`. Requer `apt-get install -y poppler-utils` no Dockerfile.

---

## 9. Próximos passos sugeridos

1. **Revisar Fase 10** (`docs/tasks/2026-05-20-rag-knowledge-base-phase-10.md`) à luz desta referência — decidir explicitamente se mantemos chunking ou pivotamos para FAQ-first. Documentar como ADR (`docs/decisions/0008-rag-strategy.md`).
2. **Abrir card** para Fase 10.x "FAQ de conversas resolvidas" (alta prioridade, foi pedido explícito).
3. **Validar índice IVFFlat + cosine** na migration da Fase 10 — usar `vector_cosine_ops` direto, sem depender de normalização.
4. **Portar `TranslateQueryService`** quando adicionarmos suporte multilíngue de RAG.
5. **Considerar `Captain::Scenario`** como inspiração para playbooks no `agent_specialists.config`.
6. **Avaliar Firecrawl** como alternativa de fetcher para URLs (Fase futura, não bloqueia 10).
