# 0004 — Fortify para auth (em vez de só Filament)

- **Status:** Aceito
- **Data:** 2026-05-16

## Contexto

Filament tem auth básico (login, registro, password reset, profile). Falta 2FA, recovery codes, password confirmation, "logout outras sessões". SaaS B2B exige esses fluxos.

Opções:
- **Filament-only:** simples, mas implementar 2FA manual é trabalhoso
- **Filament + Fortify:** Fortify é backend headless oficial Laravel pra esses fluxos; Filament UI consome
- **Custom:** reinventar a roda

## Decisão

Instalar **Laravel Fortify** com features: `two-factor-authentication`, `recovery-codes`, `password-confirmation`, `email-verification`, `update-passwords`. Filament profile page consome rotas Fortify (campos 2FA QR code, recovery codes, password update).

## Consequências

**Positivas:**
- Fluxos de segurança maduros, mantidos pela Laravel oficial
- 2FA via TOTP (Google Authenticator, 1Password, etc.)
- Recovery codes criptografados (cast `encrypted`)
- Padrão Laravel moderno (mesmo backend do React Starter Kit)

**Negativas:**
- Mais 1 dep no composer
- Integração Filament ↔ Fortify exige ajuste no profile page (custom Livewire component)

## Alternativas rejeitadas

- **Filament-only + plugin 2FA:** alguns plugins desatualizados/comunidade pequena.
- **Sanctum + custom:** Sanctum é pra APIs, não cobre 2FA. Custom = trabalho.
