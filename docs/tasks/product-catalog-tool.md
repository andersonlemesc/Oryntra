# Catalogo de Produtos — Fase 13.1

## Goal
Migrar o catalogo de produtos do role_prompt do especialista para tabela `products` por workspace, com importacao CSV e tool `query_products` que a IA pode invocar durante a conversa.

## Tasks
- [ ] 1: Criar migration `create_products_table` (workspace_id, name, sku, description, price, category, metadata jsonb, active) + indexes
- [ ] 2: Criar model `Product` com cast `metadata => array` e factory com state `active`
- [ ] 3: Criar `ImportProductsFromCsv` Action (parse CSV, upsert por sku+workspace, retorna count imported/updated)
- [ ] 4: Criar `query_products` NativeTool (query builder com filtros: name, sku, category, active, price_range)
- [ ] 5: Criar `ProductSearchService` (search by text, filter by category/price, returns formatted array)
- [ ] 6: Criar internal endpoint `POST /api/internal/agent-tools/query-products` (validates payload, returns products)
- [ ] 7: Adicionar tool ao NativeToolRegistry e garantir que chega no Python como `query_products` tool
- [ ] 8: Criar Filament `ProductResource` (upload CSV, list, view) dentro do grupo Agentes
- [ ] 9: Remover catalogo embed do role_prompt do Vendas (substituir por instrucao generica, IA usa tool)
- [ ] 10: Criar teste pest `ImportProductsFromCsvTest` e `query-products` controller test

## Done When
- [ ] Admin faz upload CSV com produtos → produtos aparecem na listagem do workspace
- [ ] AI consegue invocar `query_products` durante conversa e obter resposta com produtos
- [ ] Testes passando (pest + pytest)