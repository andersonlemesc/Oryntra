# Agent Run Latency & Tokens

## Goal
Adicionar latência e consumo de tokens por execução no trace do agente, gravados corretamente vindo do Python runtime.

## Tasks
- [ ] 1. Inspecionar como LLM invocation retorna usage (input/output tokens) em tool_runtime.py e supervisor.py
- [ ] 2. Criar helper `track_llm_usage()` que extrai tokens e latency de cada invoke
- [ ] 3. Modificar TraceStep creation para preencher `latency_ms` e `tokens` nos steps de `llm_call`
- [ ] 4. Acumular usage no RuntimeUsage (supervisor + specialist buckets) ao final da execução
- [ ] 5. Exibir latency_ms e tokens no tab "Trace" do AgentRunInfolist (PHP)
- [ ] 6. Exibir consumo total de tokens no tab "Resumo" do AgentRunInfolist
- [ ] 7. Criar teste em agent-python validando que usage é populado corretamente
- [ ] 8. Criar teste em Laravel validando que output.usage chega corretamente

## Done When
- [ ] Trace steps mostram latency_ms e tokens por chamada LLM
- [ ] Tab Resumo exibe total de input/output tokens e custo em centavos
- [ ] Testes passando (`pytest` e `pest`)