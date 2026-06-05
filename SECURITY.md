# Política de Segurança

## Versões suportadas

O projeto está em desenvolvimento ativo. Correções de segurança são aplicadas sempre na
branch `main` (último release). Versões anteriores não recebem patches retroativos.

## Reportando uma vulnerabilidade

**Não abra issue pública** para vulnerabilidades.

Use um dos canais privados:

- **GitHub Security Advisories** (preferido): aba *Security → Report a vulnerability* do
  repositório, que abre um relatório privado.
- **E-mail:** andersonlemes50@gmail.com — assunto `[SECURITY] Oryntra`.

Inclua, se possível:

- descrição da falha e impacto;
- passos para reproduzir (PoC);
- versão/commit afetado;
- mitigação sugerida, se houver.

## O que esperar

- **Confirmação de recebimento:** em até 72 horas.
- **Avaliação inicial e severidade:** em até 7 dias.
- **Correção:** prazo proporcional à severidade; coordenamos a divulgação com você.
- Crédito ao reporter no advisory, salvo se preferir anonimato.

## Escopo

São de interesse, entre outros: bypass de tenancy (`workspace_id`), vazamento de segredos
(tokens Chatwoot, chaves LLM), exposição do runtime Python, falhas de verificação de
assinatura de webhook, RCE, SQL injection, e quebra de isolamento entre workspaces.

## Boas práticas para quem opera o Oryntra

- Nunca exponha o serviço Python (`agent-python`) à internet — apenas rede interna.
- Mantenha os segredos fora do Git (`.env` é ignorado por padrão).
- Rotacione tokens e chaves periodicamente.
- Mantenha as dependências atualizadas (a CI roda `composer audit` e `pip-audit`).
