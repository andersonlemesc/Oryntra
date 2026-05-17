# 0001 — Monorepo

- **Status:** Aceito
- **Data:** 2026-05-16
- **Decisor:** Anderson Lemes

## Contexto

Oryntra tem duas runtimes: Laravel (PHP) e agent-python (Python). Opções: repos separados (sincronização manual de versões, dois CI, dois deploys, dois changelogs) ou monorepo (estrutura simples, atomicidade entre Laravel ↔ Python, único histórico).

## Decisão

Usar **monorepo** com diretórios `/laravel`, `/agent-python`, `/docker`, `/docs` na raiz.

## Consequências

**Positivas:**
- PRs atômicos atravessando Laravel e Python (mudança de contrato HTTP fica em um único commit)
- CI único orquestra tudo
- Versionamento simples (uma tag = um release de tudo)
- Setup dev em um único `docker compose up`

**Negativas:**
- Build de cada serviço lê repo inteiro (mitigado via `.dockerignore` específicos)
- Workflows GitHub Actions precisam path filters pra rodar só o necessário (ex: `paths: ['laravel/**']`)

## Alternativas rejeitadas

- **Polyrepo (2 repos):** quebra atomicidade em mudanças de contrato HTTP Laravel↔Python. Versionamento manual chato pra solo dev.
- **Submodules git:** complexidade de checkout/sync sem benefício real pra este caso.
