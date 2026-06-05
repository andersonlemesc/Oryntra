Arquitetura de Plataforma de Agentes de IA para Chatwoot
Laravel + Python/LangGraph + RAG + Multiempresa
Documento consolidado a partir da conversa tecnica
Data: 16 de maio de 2026
1. Resumo executivo
A ideia central e criar uma aplicacao separada do Chatwoot, com marca propria, capaz de adicionar uma camada de agentes de IA ao atendimento. O Chatwoot permanece como inbox/canal de atendimento, enquanto a nova aplicacao cuida de prompts, RAG, memoria, regras, logs, debounce, midia, multiempresa e integracao com modelos de IA.
A recomendacao final e construir uma plataforma self-hosted ou SaaS-like com painel em Laravel e runtime de IA em Python usando LangGraph. O Laravel atua como camada de produto, autenticacao, multi-tenancy, configuracao e orquestracao. O Python fica como servico interno privado, responsavel por agente, RAG, embeddings, transcricao, analise de imagem e checkpoints.
•	Aplicacao principal: Laravel, painel administrativo, webhooks, usuarios, workspaces e jobs.
•	Servico de IA: Python com LangGraph, rodando internamente, sem exposicao publica.
•	Banco: Postgres com pgvector para dados, memoria, RAG e checkpoints.
•	Fila/cache: Redis para debounce, locks, filas e rate limit.
•	Storage: MinIO/S3 para arquivos, PDFs, audios e imagens.
2. Visao do produto
O projeto pode ser posicionado como uma plataforma de agentes de IA para Chatwoot. Em vez de ser um mod/fork do Chatwoot, ele deve ser um produto separado integrado via API, webhook e sincronizacao opcional de contas/admins.
Decisao	Recomendacao
Formato do produto	Aplicacao separada, nao mod principal do Chatwoot.
Proposta de valor	Painel self-hosted para criar agentes profissionais no Chatwoot.
Publico principal	Agencias, consultores e empresas que implantam Chatwoot.
Diferencial sobre n8n	Debounce, memoria, RAG, logs, guards e multiempresa prontos.
Modelo comercial	White-label, planos por empresa, conversas, mensagens ou uso de IA.

3. Arquitetura recomendada
A arquitetura recomendada separa a camada administrativa da camada de IA.
Internet / Chatwoot
        |
        v
Laravel publico
- painel
- auth
- webhooks
- configuracoes
- jobs de orquestracao
        |
        | rede interna
        v
Python privado
- LangGraph
- RAG
- embeddings
- transcricao
- analise de imagem
        |
        v
Postgres + pgvector / Redis / Storage
3.1 Servicos principais
Servico	Responsabilidade	Exposicao
Laravel app	Painel, API publica, webhooks, usuarios, workspaces, configuracoes e logs.	Publico
Laravel worker	Jobs de sync, debounce, envio de mensagens, processamento assíncrono e status.	Privado
Laravel scheduler	Tarefas recorrentes, sincronizacao, limpeza, relatorios e manutencao.	Privado
Python agent service	LangGraph, RAG, embeddings, midia, checkpoints e geracao de resposta.	Privado
Postgres/pgvector	Dados do app, memoria, documentos, chunks, vetores e checkpoints.	Privado
Redis	Debounce, locks, filas temporarias, cache e rate limit.	Privado
MinIO/S3	Arquivos originais: PDFs, DOCX, audios, imagens e anexos.	Privado ou gerenciado

4. Fluxo de mensagens do Chatwoot
Cliente envia mensagem
        |
        v
Chatwoot recebe
        |
        v
Webhook chama Laravel
        |
        v
Laravel valida, identifica workspace e salva evento
        |
        v
Redis faz debounce por conversa
        |
        v
Worker consolida mensagens picadas
        |
        v
Laravel chama Python internamente
        |
        v
LangGraph processa memoria, RAG, midia e regras
        |
        v
Python retorna resposta estruturada
        |
        v
Laravel envia resposta via API do Chatwoot
O Chatwoot nao deve chamar o Python diretamente. O ponto publico deve ser o Laravel. O Python deve ficar acessivel apenas na rede interna, por exemplo http://agent-python:8000 dentro do Docker Compose.
5. Divisao Laravel x Python
Area	Laravel	Python/LangGraph
Autenticacao	Login, sessoes, roles, workspace_members.	Nao deve cuidar disso.
Chatwoot	Recebe webhook, guarda token, envia mensagens, sincroniza contas.	Pode receber dados ja normalizados.
Prompts	CRUD, versoes, templates, configuracao por workspace/agente.	Consome prompt/config no momento da execucao.
Debounce	Orquestra buffer, jobs e locks via Redis.	Recebe input consolidado.
RAG	Upload, status, permissao, tela de documentos.	Parsing, chunking, embeddings, busca semantica.
Memoria	Tabelas de cliente, resumo, preferencias, historico.	Consulta e atualiza memoria via tools/servicos.
Midia	Salva metadados e arquivos.	Transcreve audio, analisa imagem, parseia documentos.
Logs	Exibe, filtra e audita.	Gera traces e resultados da execucao.

6. Quando usar jobs no Laravel
Jobs no Laravel devem ser usados para tarefas demoradas, assíncronas, recorrentes ou sujeitas a retry. O Laravel nao precisa processar IA pesada; ele deve orquestrar.
Job sugerido	Uso
SyncChatwootAccountsJob	Sincronizar contas, admins, usuarios, inboxes e relacoes do Chatwoot.
HandleChatwootWebhookJob	Processar webhook recebido sem travar a requisicao publica.
ProcessDebouncedConversationJob	Aguardar janela de debounce e consolidar mensagens.
RunAgentJob	Chamar o servico Python para executar o agente.
SendChatwootMessageJob	Enviar resposta via API do Chatwoot com retry e log.
ProcessDocumentJob	Disparar processamento de documento no Python e atualizar status.
ReindexKnowledgeBaseJob	Reprocessar base RAG ou atualizar embeddings.
UpdateCustomerMemoryJob	Atualizar resumo e memoria do cliente apos a conversa.
CleanupOldBuffersJob	Limpar buffers antigos, locks expirados e arquivos temporarios.

7. Papel do LangGraph
O LangGraph deve ser usado como orquestrador do agente. Ele nao substitui banco, fila, storage ou painel. Ele organiza o fluxo de tomada de decisao, uso de ferramentas, checkpoints, guards e human-in-the-loop.
•	State: carrega mensagens, memoria, contexto RAG, anexos, intencao, flags e resultado final.
•	Checkpoints: salvam snapshots do estado do grafo por thread_id em banco, como Postgres.
•	thread_id: deve incluir workspace_id, chatwoot_account_id e conversation_id para evitar colisao.
•	Guards: validacoes antes/depois do LLM e antes de ferramentas sensiveis.
•	Human-in-the-loop: pausa execucao quando uma acao precisa de aprovacao humana.
thread_id = "workspace:{workspace_id}:account:{account_id}:conversation:{conversation_id}"
8. Memoria do cliente
Nem toda memoria deve virar embedding. A divisao recomendada e:
Tipo de memoria	Onde salvar	Exemplos
Memoria operacional	Postgres normal	nome, email, telefone, plano, tags, status, preferencias.
Resumo conversacional	Postgres normal	resumo do historico, problemas recorrentes, ultima interacao.
Base de conhecimento	pgvector / vector DB	FAQ, politicas, manuais, documentos, paginas e contratos.
Historico bruto	Postgres/logs	mensagens, eventos, anexos, respostas e auditoria.

Embedding deve ser usado para busca semantica em conhecimento nao estruturado. Dados objetivos do cliente devem ficar em tabelas normais e ser consultados diretamente.
9. RAG e processamento de documentos
Mesmo que o usuario envie arquivos pelo painel Laravel, a recomendacao e deixar o Python processar tudo relacionado a IA: extracao de texto, chunks, embeddings e busca semantica.
Usuario sobe PDF no Laravel
        |
        v
Laravel salva arquivo e cria documents.status = pending
        |
        v
Laravel dispara job
        |
        v
Job chama Python /documents/process
        |
        v
Python extrai texto, limpa, divide em chunks e gera embeddings
        |
        v
Python salva chunks + vetores no Postgres/pgvector
        |
        v
Laravel atualiza status no painel
Tabela	Campos principais
documents	id, workspace_id, filename, storage_path, status, uploaded_at, processed_at.
document_chunks	id, workspace_id, document_id, content, embedding, metadata.
embedding_jobs	id, workspace_id, document_id, status, error_message, started_at, finished_at.

10. Debounce de mensagens
Debounce deve ficar antes do agente, normalmente usando Redis e jobs. O objetivo e agrupar mensagens picadas e reduzir chamadas ao LLM.
Usuario: Oi
Usuario: preciso de ajuda
Usuario: com meu pedido
Usuario: ele atrasou

Sem debounce: 4 chamadas LLM
Com debounce: 1 chamada LLM com texto consolidado
•	Debounce por workspace_id + conversation_id.
•	Idempotencia por message_id para evitar duplicacao de webhook.
•	Lock por conversa para impedir duas respostas simultaneas.
•	Janela configuravel por workspace/agente, por exemplo 5 a 15 segundos.
•	Processar apenas mensagens incoming do cliente; ignorar outgoing do bot/atendente.
11. Audio, imagem e midia
O Chatwoot pode enviar anexos no webhook ou permitir busca posterior via API. O LangGraph pode orquestrar o tratamento de midia, mas a transcricao/analise e feita por tools ou servicos externos.
Tipo	Tratamento recomendado
Audio	Baixar arquivo, transcrever com Whisper, Gemini, Speech-to-Text, Deepgram ou similar, salvar transcricao.
Imagem	Analisar com modelo vision e transformar em descricao textual auditavel.
Documento	Salvar original, extrair texto, chunking, embeddings e indexacao no RAG.

Texto + audio + imagem depois do debounce
        |
        v
media_preprocessor
        |
        +-- audio -> transcricao
        +-- imagem -> descricao
        +-- documento -> texto/chunks
        v
normalized_input para o agente
12. Guards, regras e human-in-the-loop
LangGraph pode lidar bem com regras porque permite criar nos e rotas condicionais. Os guards devem aparecer antes do LLM, antes de tools sensiveis e antes de enviar resposta ao cliente.
Regra	Comportamento
Conversa atribuida a humano	Nao responder automaticamente.
Cliente pediu humano/cancelamento	Transferir para humano ou criar aprovacao.
RAG com baixa confianca	Nao inventar; pedir esclarecimento ou transferir.
Desconto acima do limite	Pausar para aprovacao humana.
Dados sensiveis	Mascarar, bloquear ou transferir.
Fora do horario comercial	Responder fallback ou encaminhar conforme regra.

13. Multiempresa e revendedores
Como o Chatwoot ja e multi-tenant, a plataforma deve nascer com workspace_id em todas as tabelas. Isso permite que agencias, consultores ou revendedores gerenciem varios clientes no mesmo painel.
Plataforma
  |
  +-- Revendedor / Agencia
        |
        +-- Workspace Cliente A
        +-- Workspace Cliente B
        +-- Workspace Cliente C
•	Banco unico com workspace_id e organization_id no MVP.
•	RAG sempre filtrado por workspace_id.
•	Tokens e API keys criptografados no banco.
•	Logs, arquivos, memoria e checkpoints isolados por workspace.
•	thread_id do LangGraph deve incluir workspace_id para evitar colisao.
Role	Permissao sugerida
Super Admin	Controla toda a plataforma.
Revendedor/Admin	Gerencia varios workspaces/clientes.
Cliente Admin	Configura agentes, prompts, documentos e conexoes do proprio workspace.
Gestor/Atendente	Ve logs, conversas e operacoes permitidas.

14. Sincronizacao com Chatwoot
Uma estrategia forte e criar jobs que sincronizam contas e admins do Chatwoot para provisionar workspaces e usuarios automaticamente.
Chatwoot DB/API
        |
        v
SyncChatwootAccountsJob
        |
        +-- cria/atualiza workspaces
        +-- cria/atualiza usuarios admins
        +-- vincula usuarios aos workspaces
        +-- sincroniza inboxes e conexoes
Para self-hosted, ler direto do banco do Chatwoot pode ser pratico, mas cria acoplamento ao schema interno. Para Chatwoot Cloud ou cenarios externos, usar API e mais portavel. O ideal e suportar os dois modos no roadmap.
Modo	Quando usar	Cuidado
API do Chatwoot	Chatwoot Cloud ou sem acesso ao banco.	Pode ter limitacoes dependendo da API/token.
Banco do Chatwoot	Self-hosted na mesma infra.	Pode quebrar se o schema mudar em atualizacoes.

15. Seguranca e isolamento
•	Python agent service deve ficar privado, acessivel apenas pelo Laravel na rede interna.
•	Usar token interno mesmo em rede privada, por exemplo X-Internal-Token.
•	Criptografar tokens do Chatwoot, chaves OpenAI/Gemini e credenciais sensiveis.
•	Validar webhook do Chatwoot por token/assinatura quando possivel.
•	Aplicar workspace_id em todas as consultas, inclusive RAG e logs.
•	Implementar idempotencia por message_id e lock por conversation_id.
•	Nao copiar senhas do Chatwoot na sincronizacao; preferir convite, magic link ou senha propria.
16. Escalabilidade
Ter varios servicos nao significa que a aplicacao sera pesada. O painel tende a ser pouco acessado. O volume real estara em webhooks, filas, mensagens consolidadas, transcricao, RAG e chamadas LLM.
Componente	Como escalar
Laravel app	Escalar horizontalmente se webhooks ou painel aumentarem.
Laravel workers	Adicionar workers para jobs, sync, debounce e envio de mensagens.
Python workers	Escalar horizontalmente; devem ser stateless e usar banco/Redis para estado.
Postgres	Dimensionar CPU, memoria, indices, conexoes e pgvector.
Redis	Usar para locks, debounce e filas; monitorar memoria e expiracao.

O Python pode comecar como API interna simples. Em volumes maiores, pode evoluir para workers de agente consumindo uma fila.
MVP:
Laravel worker -> HTTP interno -> Python FastAPI /agent/run

Escala:
Laravel cria task -> fila -> varios Python workers processam -> callback/status
17. Roadmap sugerido
Fase	Escopo
Fase 1 - Laravel base	Auth, workspaces, conexao Chatwoot, webhook, logs, resposta mock, estrutura multiempresa.
Fase 2 - Python minimo	Endpoint interno /agent/run, LangGraph simples, prompt configuravel e resposta real.
Fase 3 - Debounce e memoria	Redis, locks, consolidacao de mensagens, memoria operacional e resumo por cliente.
Fase 4 - RAG	Upload, documentos, parsing, chunking, embeddings, pgvector e busca por workspace.
Fase 5 - Midia	Audio, imagem, transcricao, descricao visual e normalizacao de entrada.
Fase 6 - Guards e HITL	Regras configuraveis, aprovacao humana, limite de desconto, fallback e transferencias.
Fase 7 - SaaS/white-label	Revendedores, planos, billing, metricas, isolamento avancado e branding.

18. Checklist do MVP
•	Projeto Laravel com autenticacao, usuarios e workspaces.
•	CRUD de conexao Chatwoot por workspace.
•	Webhook publico por connection_uuid.
•	Recebimento e log de mensagens incoming.
•	Envio de mensagem via API do Chatwoot.
•	Debounce simples em Redis por workspace + conversation_id.
•	Servico Python privado com endpoint /agent/run.
•	Prompt configuravel por agente.
•	Logs de entrada, saida, erro e tempo de resposta.
•	Estrutura de tabelas com workspace_id desde o inicio.
19. Modelo inicial de tabelas
users
organizations
workspaces
workspace_members
chatwoot_connections
agents
agent_prompts
agent_rules
conversations
messages
customer_memory
documents
document_chunks
agent_runs
agent_logs
langgraph_checkpoints
Quase todas as tabelas de negocio devem ter workspace_id. Para multiempresa, esse campo e a principal fronteira de isolamento no MVP.
20. Exemplo de Docker Compose conceitual
services:
  laravel-app:
    build: ./laravel
    ports:
      - "80:80"
      - "443:443"

  laravel-worker:
    build: ./laravel
    command: php artisan queue:work

  laravel-scheduler:
    build: ./laravel
    command: php artisan schedule:work

  agent-python:
    build: ./agent-python
    expose:
      - "8000"

  postgres:
    image: pgvector/pgvector:pg16

  redis:
    image: redis:7

  minio:
    image: minio/minio
21. Decisoes consolidadas
Tema	Decisao
Mod do Chatwoot	Nao recomendado como caminho principal; melhor app separado com marca propria.
Framework de agente	LangGraph em Python para runtime de agentes.
Painel	Laravel, por produtividade e experiencia previa.
Comunicacao Laravel-Python	HTTP interno no MVP; fila/workers para escala.
Python publico	Nao. Deve ficar privado na rede interna.
Memoria	Dados estruturados em Postgres; RAG em pgvector.
Embeddings	Gerados no Python, nao no Laravel.
Multiempresa	Sim, desde o inicio, com workspace_id em todas as tabelas.
Escala	Debounce, locks por conversa e Python stateless com multiplos workers.


Nota: este documento e uma consolidacao arquitetural da conversa. Ele deve ser refinado antes de virar especificacao tecnica final, especialmente nas decisoes de schema, seguranca, billing e deploy.
