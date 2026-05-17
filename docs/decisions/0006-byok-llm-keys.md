# 0006 — BYOK (Bring Your Own Key) pra LLMs

- **Status:** Aceito
- **Data:** 2026-05-16

## Contexto

Oryntra precisa decidir quem paga pelas chamadas LLM (OpenAI, Anthropic, Gemini):
- **BYOK:** workspace cadastra própria API key, paga direto ao provider
- **Plataforma + markup:** Oryntra paga LLM, cobra cliente com margem
- **Híbrido:** plano grátis usa platform key, planos pagos podem trazer própria

## Decisão

**BYOK** no MVP. Workspace cadastra chave via `AgentLlmKeyResource` (criptografada com cast `encrypted`). Plataforma não intermedia cobrança LLM.

## Consequências

**Positivas:**
- Zero risco financeiro pra Oryntra (sem cobranças surpresa de cliente)
- Sem metering crítico no MVP (registramos `usage_events` mas sem cobrança)
- Cliente já tem relação com provider, contratos e quotas
- Compliance: dados do cliente não passam por conta Oryntra
- Simples legalmente (sem revender API third-party)

**Negativas:**
- Onboarding mais fricção (cliente precisa criar conta no provider, gerar key)
- Suporte: troubleshooting de erros 401/quota fica entre cliente e provider
- Não captura valor sobre uso (limitação revenue futura)

## Alternativas rejeitadas

- **Platform + markup:** exige metering preciso desde dia 1, billing, cobrança automática (Stripe), tratativa de chargebacks, contrato de revenda com provider. Fora do escopo MVP solo dev.
- **Híbrido:** complexidade dupla, adiar pra fase pós-billing.

## Revisitar quando

- Houver demanda de cliente por "plataforma cuida de tudo"
- For implementar planos pagos com billing (Fase 7+)
- Volume justificar overhead de metering robusto
