# Contribuindo com o Oryntra

Obrigado pelo interesse em contribuir! Este guia resume o fluxo e os padrões do projeto.
As regras canônicas e detalhadas ficam em [`AGENTS.md`](AGENTS.md) — consulte antes de
mudanças não triviais.

## Antes de começar

- Para bugs e ideias, abra uma **issue** descrevendo o cenário, o esperado e o observado.
- Para mudanças maiores, abra uma issue de discussão antes de codar — evita retrabalho.
- Mudanças de dependência precisam de justificativa explícita no PR.

## Ambiente de desenvolvimento

Pré-requisitos: Docker + Docker Compose.

```bash
cp .env.example .env
docker compose up -d
docker compose exec laravel-app php artisan key:generate
docker compose exec laravel-app php artisan migrate
docker compose exec laravel-app php artisan make:filament-user
```

## Fluxo de Git

- **Nunca** commite direto em `main` ou `develop`.
- Crie branches a partir de `develop`: `feature/*`, `bugfix/*`, `hotfix/*`.
- **Conventional commits:** `feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`.
- Um feature = um commit limpo (faça squash se necessário).
- Merge sempre via Pull Request, com a CI verde.

## Padrões de código

**PHP / Laravel**
- PHP 8.4: property promotion, tipos explícitos em parâmetros e retornos.
- Formatação: `./vendor/bin/pint --dirty` (obrigatório antes do commit).
- Análise estática: `./vendor/bin/phpstan analyse` (Larastan, level 8 — zero erros).
- Siga as convenções dos arquivos irmãos (nomes descritivos, estrutura existente).

**Python**
- Python 3.12: type hints em tudo.
- Lint/format: `ruff check .` e `ruff format`.
- Tipos: `mypy src/`.

## Testes

- A maior parte deve ser **feature test**. Use as factories dos models.
- Não delete testes sem aprovação.

```bash
docker compose exec laravel-app ./vendor/bin/pest      # roda em Postgres (oryntra_test)
docker compose exec agent-python pytest
```

## Checklist do Pull Request

Antes de marcar como pronto, garanta:

- [ ] `pint --dirty` sem pendências
- [ ] `phpstan analyse` sem erros
- [ ] `pest` verde (e novos testes para o que mudou)
- [ ] Python: `ruff check .`, `mypy src/`, `pytest` (se tocou no runtime)
- [ ] Commits no padrão conventional
- [ ] Sem segredos, `.env` ou tokens reais no diff

## Segurança

Não abra issue pública para vulnerabilidades. Siga o [`SECURITY.md`](SECURITY.md).

## Licença das contribuições

Ao contribuir, você concorda que sua contribuição é licenciada sob a
[Apache License 2.0](LICENSE) do projeto (Seção 5 da licença).
