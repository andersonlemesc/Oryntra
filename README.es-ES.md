

<div align="center">

# Oryntra

**Plataforma de agentes de IA para Chatwoot.**

Capa de producto en Laravel + runtime de IA en Python/LangGraph, con RAG, memoria,
multientidad e integración nativa con Chatwoot vía webhook y API.

[![CI Laravel](https://github.com/andersonlemesc/Oryntra/actions/workflows/ci-laravel.yml/badge.svg)](https://github.com/andersonlemesc/Oryntra/actions/workflows/ci-laravel.yml)
[![CI Python](https://github.com/andersonlemesc/Oryntra/actions/workflows/ci-python.yml/badge.svg)](https://github.com/andersonlemesc/Oryntra/actions/workflows/ci-python.yml)
[![CI Security](https://github.com/andersonlemesc/Oryntra/actions/workflows/ci-security.yml/badge.svg)](https://github.com/andersonlemesc/Oryntra/actions/workflows/ci-security.yml)
[![License: Apache 2.0](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](LICENSE)

</div>

---

<div align="center">

[![Oryntra — demostración en video](https://img.youtube.com/vi/4n6mnRIG1pc/maxresdefault.jpg)](https://youtu.be/4n6mnRIG1pc)

▶️ **[Ver la demostración de Oryntra](https://youtu.be/4n6mnRIG1pc)**

</div>

---

## ¿Qué es?

Oryntra añade una capa de **agentes de IA** al soporte en Chatwoot, sin ser un
fork. Chatwoot continúa como inbox/canal; Oryntra se encarga de prompts, RAG, memoria,
reglas, debounce, multimedia, multientidad y de la orquestación con modelos de IA.

- **Aplicación principal (Laravel):** panel admin (Filament), autenticación, multi-tenancy,
  webhooks de Chatwoot, configuración de agentes y trabajos de orquestación.
- **Runtime de IA (Python):** servicio **privado** (nunca expuesto a internet) con LangGraph,
  RAG, embeddings, transcripción y checkpoints.
- **Datos:** Postgres + pgvector. **Cola/cache/locks:** Redis. **Almacenamiento:** MinIO/S3.

Documento completo de visión y arquitectura: [`docs/visao-e-arquitetura.md`](docs/visao-e-arquitetura.md).

## Características principales

- 🤖 **Agentes configurables** — modo automático o copiloto (sugerencia como nota privada).
- 🧠 **Memoria a largo plazo** por contacto, con clasificación por tipo (preferencia, hecho, restricción…).
- 📚 **RAG** sobre base de conocimientos por workspace (documentos, productos).
- 🔀 **Enrutamiento supervisor → especialista** (LangGraph StateGraph).
- 🙋 **Human-takeover lock** — cuando un humano toma el control de la conversación, el bot deja de responder hasta que se resuelva.
- 🔑 **BYOK** — cada workspace utiliza su propia clave OpenAI/Anthropic/Gemini.
- 🏢 **Multientidad** — `workspace_id` en cada consulta de negocio; aislamiento por tenant.
- 🔁 **Idempotencia y locks** por `chatwoot_message_id` y `conversation_id`.

## Stack

| Capa | Tecnología |
|--------|-----------|
| Control plane | PHP 8.4, Laravel 13, Filament 5, Horizon, Reverb |
| Runtime IA | Python 3.12, FastAPI, LangGraph |
| Base de datos | PostgreSQL 16 + pgvector |
| Cola / caché | Redis |
| Almacenamiento | MinIO / S3 |
| Infra | Docker Compose, Nginx, PgBouncer |

## Arquitectura (resumen)

```
Internet / Chatwoot
        |
        v
  Laravel (público)            red interna Docker         Python (privado)
  panel, auth, webhooks  ───────────────────────────►  LangGraph, RAG,
  trabajos de orquestación      X-Internal-Token solamente      embeddings, checkpoints
        |                                                       |
        +───────────────► Postgres + pgvector ◄────────────────+
                          Redis · MinIO/S3
```

Reglas de arquitectura críticas (tenancy, secretos cifrados, Python privado, locks) en
[`AGENTS.md`](AGENTS.md).

## Inicio rápido (desarrollo)

Requisitos previos: Docker + Docker Compose.

```bash
# 1. Configurar entorno
cp .env.example .env
# edite .env y complete los secretos necesarios

# 2. Levantar la stack
docker compose up -d

# 3. Generar APP_KEY y migrar
docker compose exec laravel-app php artisan key:generate
docker compose exec laravel-app php artisan migrate

# 4. Crear usuario admin del panel
docker compose exec laravel-app php artisan make:filament-user
```

Panel: `http://localhost:8080/admin` · Mailpit (correos de desarrollo): `http://localhost:8026`.

## Pruebas y calidad

```bash
# Laravel — las pruebas se ejecutan en Postgres (base de datos oryntra_test)
docker compose exec laravel-app ./vendor/bin/pest
docker compose exec laravel-app ./vendor/bin/pint          # formato
docker compose exec laravel-app ./vendor/bin/phpstan analyse   # análisis estático (nivel 8)

# Python
docker compose exec agent-python pytest
docker compose exec agent-python ruff check .
docker compose exec agent-python mypy src/
```

CI ejecuta los tres pipelines en cada push/PR: **Laravel** (Pint, Larastan, Pest), **Python**
(Ruff, mypy, pytest) y **Security** (composer audit, pip-audit, TruffleHog, Gitleaks).

## Estructura del monorepo

```
/
├── laravel/          # app Laravel (panel, webhooks, jobs)
├── agent-python/     # servicio Python privado (LangGraph, RAG)
├── docker/           # Dockerfiles y configs
├── docs/             # visión, arquitectura, ADRs, integraciones, runbooks
└── .github/          # CI/CD workflows
```

## Despliegue

Autoalojado vía Docker Compose. Overlay de producción en
[`docker-compose.prod.yml`](docker-compose.prod.yml). Topología:

- **Postgres y Redis gestionados** (externos) — env apunta a los hosts gestionados.
- **Traefik externo** termina TLS y enruta por `Host`; `nginx` y `reverb` (websocket en
  la ruta `/app`) reciben labels Traefik y nada se publica directamente en el host.
- **MinIO interno** — solo en la red Docker.

```bash
cp .env.production.example .env   # edite secretos, dominio y hosts gestionados
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d \
  nginx laravel-app laravel-horizon laravel-scheduler laravel-reverb \
  agent-python minio
```

Despliegue automatizado por SSH al publicar un release: ver [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml).

### Instalación mediante Docker Swarm

Para quienes solo quieran instalar el sistema a partir de las imágenes publicadas (Docker Hub
`andersonlemes/oryntra-*`), hay un stack Swarm de ejemplo en
[`docker-compose.prod.yml`](docker-compose.prod.yml) — solo la aplicación, con Postgres, Redis y S3
externos y Traefik en el perímetro:

```bash
cp .env.stack.example .env && nano .env
set -a && . ./.env && set +a
docker stack deploy -c docker-compose.prod.yml oryntra
```

## Contribución y seguridad

- Cómo contribuir: [`CONTRIBUTING.md`](CONTRIBUTING.md)
- Reportar vulnerabilidad: [`SECURITY.md`](SECURITY.md)
- Código de conducta: [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md)

## Licencia

[Apache License 2.0](LICENSE) © 2026 Anderson Lemes. Ver también [`NOTICE`](NOTICE).
"Oryntra" y el logo son marcas de Anderson Lemes; la licencia no concede derecho de uso de las
marcas (Sección 6 de Apache 2.0).
