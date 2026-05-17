# 0003 — Filament em vez de Inertia + React

- **Status:** Aceito
- **Data:** 2026-05-16

## Contexto

Painel admin do Oryntra é ~90% CRUD: workspaces, agents, prompts, documents, connections, logs viewer, runs. Solo dev quer entregar MVP rápido.

Opções:
- **Inertia + React/Vue:** liberdade total de UI, escrever cada tela manual
- **Filament 3:** framework de admin sobre Livewire, CRUD declarativo (Resources gerados em horas)
- **Híbrido:** ambos no mesmo app

## Decisão

Usar **Filament 3** como único framework de UI admin. Multi-tenancy via `HasTenants` nativo. Páginas públicas futuras (landing) em Blade puro fora do Filament.

## Consequências

**Positivas:**
- CRUD de Resource em horas vs semanas em React custom
- Multi-tenancy built-in casa com requisito `workspace_id`
- Filament Shield gera policies/roles automáticas
- Menos código = menos bugs
- Stack 100% PHP/Blade → portfolio Laravel coeso

**Negativas:**
- UI tem visual padrão Filament (mitigável com themes)
- Páginas custom precisam Livewire/Blade — curva pra quem só sabe React
- Comunidade menor que React, mas crescendo

## Alternativas rejeitadas

- **Inertia + React:** estimado 3-4x mais código pro mesmo CRUD. Não justificado pra admin B2B.
- **Híbrido:** complexidade de manter duas stacks frontend num MVP solo.
