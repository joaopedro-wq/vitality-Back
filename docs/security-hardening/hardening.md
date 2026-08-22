# Security Hardening Review: vitality-Back

## Evidence Basis

Usamos a varredura Codex Security `37851c84-7460-464d-ba9c-d14267dd7533`, o código revisado e as recomendações do produto. A varredura parcial não confirmou uma vulnerabilidade explorável; ela mostrou que os controles existem em vários pontos, enquanto a prova regressiva deles ainda não cobre toda a matriz de recursos. Consulte [context.md](context.md).

## Constraints

Vamos preservar Sanctum e Laravel Feature Tests, sem depender de banco local. A CI deve falhar se um cenário de isolamento, abuso de autenticação ou encerramento de sessão regressar. A configuração real de domínio e proxy é uma decisão de ambiente, portanto não pode ser provada apenas pelo repositório.

## Opportunity Portfolio

| Opportunity | Evidence | Options | Recommendation | Proposal |
| --- | --- | --- | --- | --- |
| Contrato executável de autorização, autenticação e sessão | E1 — requisito de produto; E2/E3 — controles dispersos; E4/E5 — configuração e testes | testes locais; suíte reutilizável; browser em staging | Suíte reutilizável como gate; staging como complemento | [proposta](proposals/security-contract-ci.md) |

## Recommendation Summary

Recomendo a opção 2: uma suíte Feature dedicada que trate autorização, rate limit e sessão como contrato da API. Ela reduz o risco de um novo endpoint ignorar uma policy ou uma query `user_id`, sem exigir uma reescrita do domínio. A opção 3 é necessária para fechar a diferença entre testes Laravel e cookies reais, CORS/CSRF e CSP em produção.

## Next Decisions

1. Aprovar a opção 2 e executar o plano em [implementation/security-contract-suite.md](implementation/security-contract-suite.md).
2. Definir os domínios reais e os valores esperados de `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE` e `SESSION_SAME_SITE`.
3. Adicionar o job obrigatório de CI antes de considerar o critério de aceite concluído.
4. Seguir o roteiro completo em [implementation/security-roadmap.md](implementation/security-roadmap.md), que inclui rate limits para superfícies além de login e cadastro.
