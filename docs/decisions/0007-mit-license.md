# 0007 — Licença MIT

- **Status:** Aceito
- **Data:** 2026-05-16

## Contexto

Oryntra será open-source. Escolha de licença afeta adoção, contribuições, proteção contra fork SaaS proprietário.

Opções:
- **MIT:** mais permissivo, máxima adoção
- **Apache 2.0:** permissivo + cláusula patente
- **AGPL-3.0:** protege contra "AWS-style" — qualquer SaaS baseado tem que abrir código
- **BSL (Business Source License):** source-available, vira open-source em X anos

## Decisão

**MIT.** Maximiza adoção e contribuições. Aceita risco de fork proprietário em troca de comunidade maior.

## Consequências

**Positivas:**
- Curva zero pra adoção corporativa (departamentos jurídicos não bloqueiam MIT)
- Comunidade contribui mais fácil (sem dúvidas de licença)
- Compatível com qualquer outro projeto open-source

**Negativas:**
- Permite forks proprietários (alguém pode rodar como SaaS sem contribuir de volta)
- Mitigação: vantagem competitiva via velocidade de desenvolvimento + comunidade + serviço hosted oficial

## Alternativas rejeitadas

- **AGPL:** muitas empresas bloqueiam AGPL por medo de viralidade; mata adoção corporativa
- **BSL:** complexo legalmente, dúvidas sobre se "open-source" de verdade
- **Apache 2.0:** ganho marginal sobre MIT (cláusula patente) não justifica perda de simplicidade

## Revisitar quando

- Houver fork proprietário causando dano competitivo real
- Mudar pra modelo dual-license (MIT + commercial)
