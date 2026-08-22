# Security Hardening Proposal: Contrato de segurança executável para API e sessão

## Decision

Escolher como cada controle de autorização e sessão continuará provado quando novas rotas forem adicionadas. Esta proposta não substitui correções pontuais: qualquer endpoint que deixar de escopar um recurso ao usuário ainda deve ser corrigido no controller/policy.

## Executive Recommendation

Há três opções: **1. Testes locais por controller**, **2. Matriz reutilizável e gate obrigatório**, e **3. Complemento de staging com navegador**. Recomendo a opção 2 agora e a opção 3 após os domínios de deploy serem definidos.

## Evidence

| Evidência | Documento ou código | O que estabelece |
| --- | --- | --- |
| E1 | Recomendações, seção 3 | O aceite exige A × B, abuso de autenticação e logout → 401 na CI. |
| E2 | `routes/api.php`, `AppServiceProvider.php`, `AuthController.php` | Login e cadastro recebem throttles; logout invalida e regenera o token. |
| E3 | `DiaryEntryController.php`, `DiaryMealController.php`, `DietaController.php`, `MealPlanController.php` | Ownership é aplicado por policy, query com `user_id` ou verificação explícita. |
| E4 | `config/sanctum.php`, `config/session.php`, `config/cors.php` | A proteção real de cookie/origem depende de ambiente. |
| E5 | `phpunit.xml`, testes Feature | Há banco isolado, mas ainda não um contrato transversal obrigatório. |

Eu inspecionei essas fronteiras. O fato observado é que os padrões de enforcement variam; a inferência é que revisão humana sozinha pode deixar uma rota futura sem a mesma proteção. Uma matriz executável reduz esse desvio.

## Current Design And Failure Mode

Hoje a API aplica `auth:sanctum` nas rotas privadas e a maior parte dos recursos usa `user_id` ou `Gate`. Isso é uma base adequada. O modo de falha é regressivo: uma nova ação ligada por model binding pode manipular um objeto de B se esquecer a verificação de ownership; outra mudança pode alterar a semântica de rate limit ou logout sem teste que a bloqueie.

## Desired Invariants

- A não lê, atualiza, arquiva, favorita ou exclui dados privados de B.
- Toda resposta a senha/email inválidos é semanticamente idêntica para conta existente e inexistente.
- O N+1º login/cadastro limitado retorna `429`; não autentica e não cria usuário.
- Depois de logout, `/api/user` e outra rota protegida retornam `401`.
- Em produção, cookies são `Secure` e `HttpOnly`, domínios/origens são allowlist exata e CSP é restritiva.

## Constraints And Non-Goals

Não vamos inferir domínios de produção nem testar secret real em CI. O plano não transforma este teste em autorização; o código continua sendo a fronteira de enforcement.

## Before Architecture

![Fluxo atual](../diagrams/security-contract-ci-before.mmd)

O controle está nos controllers/policies, mas a prova permanece distribuída. O delta desejado é concentrar a especificação de segurança sem centralizar indevidamente a lógica de domínio.

## Options

### Option 1: Testes locais por controller

Adicionar casos A × B em cada arquivo de teste existente. É barato e preserva a estrutura atual, mas dá margem para endpoints novos não receberem o caso correspondente. Segurança melhora localmente; performance e memória não mudam; confiabilidade depende de disciplina de revisão. Rollback é remover somente os testes.

![Opção 1](../diagrams/security-contract-ci-local-feature-tests-after.mmd)

| Change | Before | After | Security consequence | Cost |
| --- | --- | --- | --- | --- |
| Cobertura | Pontual | Casos por controller | Detecta regressões conhecidas | Duplicação e lacunas futuras |

### Option 2: Matriz reutilizável e gate obrigatório

Criar `tests/Feature/Security/AuthorizationContractTest.php`, `AuthenticationAbuseTest.php` e `SessionLifecycleTest.php`, com datasets/auxiliares que criam A, B e recurso de B. A matriz enumera read/update/delete por família de recursos e exige `404` para não vazar existência, exceto quando o contrato já padronizar `403`. O job de CI executa `php artisan test --testsuite=Feature` (ou grupo equivalente) do zero.

A parte atraente é a evidência explícita para o aceite e a manutenção simples quando uma família nova aparece. O custo é manter factories e payloads válidos; não há novo hop de produção, buffer ou serviço. A suíte acrescenta tempo de CI, que deve ser medido com baseline e limite acordado. Rollback é retirar o gate apenas em incidente de infraestrutura, nunca silenciar um caso falho.

![Opção 2](../diagrams/security-contract-ci-security-contract-suite-after.mmd)

| Change | Before | After | Security consequence | Cost |
| --- | --- | --- | --- | --- |
| Ownership | Convenções mistas | Matriz A × B | Regressão IDOR bloqueia merge | Manutenção de datasets |
| Auth | Throttle e respostas no código | Abuso reproduzido | Evita alteração silenciosa | Alguns segundos de CI |
| Sessão | Logout implementado | Logout verificado | Acesso posterior precisa ser 401 | Fixture de sessão |

### Option 3: Complemento de staging com navegador

Executar Playwright contra o domínio real para login, CSRF, cookie attributes observáveis, logout e CSP. É o único modo de verificar proxy, `SameSite`, `Secure`, CORS e política CSP como o navegador percebe. Traz maior confiança operacional, mas exige ambiente isolado, contas de teste e gerenciamento de flakiness. Deve complementar, não substituir, a opção 2.

![Opção 3](../diagrams/security-contract-ci-staging-browser-contract-after.mmd)

| Change | Before | After | Security consequence | Cost |
| --- | --- | --- | --- | --- |
| Ambiente | Configuração não observada | Browser no domínio real | Fecha risco de deploy | Infra e maior latência |

## Comparison

| Dimensão | Opção 1 | Opção 2 | Opção 3 |
| --- | --- | --- | --- |
| Segurança | melhora parcial | melhora alta contra regressão de API | melhora alta para configuração real |
| Performance | neutra em produção | neutra em produção; CI mais lento | neutra em produção; CI mais lento |
| Memória | neutra | neutra | workers de browser em CI |
| Confiabilidade | risco de omissão | contratos repetíveis | dependência de staging |
| Operação | baixa | gate e relatórios | ambiente e credenciais de teste |
| Migração | pequena | incremental | depende da opção 2 |

## Recommendation

Recomendo a opção 2 sob as restrições atuais. Ela é a menor mudança que atende diretamente ao aceite. A opção 3 passa a ser preferível como prioridade imediata se a SPA e API forem cross-site ou se o proxy/TLS já tiver causado incidentes.

## Evidence Coverage And Residual Risk

| Evidência | Efeito da opção 2 | Risco residual |
| --- | --- | --- |
| E1 — aceite de produto | addresses | CI ainda precisa ser configurada como obrigatória |
| E2 — auth e limite | addresses | parâmetros de produção podem diferir |
| E3 — ownership | mitigates | rota nova pode faltar no dataset até ser adicionada |
| E4 — sessão/CORS | unknown | exige staging/browser |
| E5 — testes isolados | addresses | estabilidade deve ser medida na CI |

## Migration And Rollout

Introduzir primeiro os testes sem mudar comportamento. Em seguida, habilitar o job como required check. Corrigir qualquer falha de controle antes de tornar o gate obrigatório. Se a CI estiver indisponível, bloquear deploy manualmente e restaurar o job assim que a infraestrutura voltar.

## Validation Plan

- Rodar `php artisan test` em checkout limpo sem cache e sem banco local.
- Executar a matriz contra recursos de diário, refeições, dieta, metas, recomendações, meal plans e drafts quando expostos por ID.
- Enviar seis tentativas de login e seis de cadastro da mesma chave; validar `429` na tentativa limitada.
- Comparar status e corpo de credencial inválida para email existente/inexistente.
- Autenticar, fazer logout e requisitar duas rotas privadas; validar `401`.
- Em staging, validar cabeçalhos `Set-Cookie`, CORS, CSRF e CSP contra domínios reais.

## Implementation Work Packages

O plano ordenado e os critérios de aceite estão em [implementation/security-contract-suite.md](../implementation/security-contract-suite.md).

## Open Questions

Qual é o código padronizado para acesso cruzado: `404` anti-enumeração ou `403`? Quais domínios e proxies precisam ser suportados? Qual tempo máximo aceitável para o job de segurança?
