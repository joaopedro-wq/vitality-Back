# Implementation Plan: Matriz reutilizável e gate obrigatório

## Selected Design And Constraints

Opção 2 da proposta, ancorada na revisão `345ff7d3606b1b3993e580371b5f99d4d75443c4`. Usar `RefreshDatabase`, SQLite em memória e Sanctum de teste; não usar dados ou secrets locais.

## Source Revision And Drift Check

Antes de codificar, comparar a revisão atual com `345ff7d3606b1b3993e580371b5f99d4d75443c4`. Se rotas, guards ou modelos mudarem, atualizar a matriz antes da implementação.

## Affected Components

- `tests/Feature/Security/AuthorizationContractTest.php`
- `tests/Feature/Security/AuthenticationAbuseTest.php`
- `tests/Feature/Security/SessionLifecycleTest.php`
- testes Feature existentes para factories/payloads reutilizados
- workflow de CI do repositório
- opcionalmente config de staging e teste browser no frontend

## Ordered Work Packages

1. Criar helpers de teste para A, B, autenticação Sanctum e recursos pertencentes a B.
2. Implementar tabela de casos `GET/PATCH|PUT/DELETE` para diário, refeições, dieta, meta, recomendação e meal plans. Cada caso deve confirmar status anti-enumeração definido e que o banco não mudou.
3. Cobrir drafts e ações por ID de meal plan (`edit-draft`, update, archive, favorite/unfavorite, destroy), confirmando isolamento de A/B.
4. Testar `api-login`: cinco tentativas permitidas e a próxima `429`; validar que senha/email inválidos retornam a mesma mensagem/status independentemente da existência da conta.
5. Testar `api-register`: ultrapassar o limite e confirmar `429` e ausência de usuário adicional.
6. Cobrir também o rate limit de recuperação de senha, reenvio de verificação, upload de avatar e geração/alteração de plano por IA; cada caso deve verificar `429`, `Retry-After` quando disponível e ausência de efeito colateral.
7. Testar login válido, `/api/user` autenticado, logout, e duas rotas protegidas retornando `401`.
8. Adicionar job obrigatório que rode `php artisan test` em ambiente limpo, publique JUnit quando disponível e falhe o merge.
9. Em staging, executar teste browser para cookies, CSRF, CORS e CSP depois de definir domínios reais.

## Compatibility And Migration

Preservar os códigos atuais até uma decisão explícita de contrato. Se houver mistura de `404` e `403`, normalizar por família de rota e ajustar o frontend/documentação no mesmo PR.

## Tactical Protections During Migration

Não remover `auth:sanctum`, throttles, checks por `user_id`, `Gate`, regeneração de sessão ou middleware `admin`. Os testes detectam regressão; eles não substituem nenhum desses controles.

## Tests And Security Validation

Aceite por caso: resposta negada, nenhum campo/objeto de B no corpo, e estado persistido idêntico antes/depois. Para auth: `429`, resposta genérica e nenhuma sessão autenticada. Para endpoints caros, `429` deve impedir chamada adicional ao provedor Gemini e criação de draft. Para logout: `401` após invalidar a sessão.

## Performance And Resource Benchmarks

Medir duração do job Feature em CI antes/depois. Meta inicial: registrar baseline e manter a variação abaixo do orçamento aprovado; paralelizar somente se o banco isolado permanecer determinístico.

## Rollout And Rollback

Abrir o job primeiro como visível; após uma execução verde reproduzível, torná-lo required check. Em falha de infraestrutura, corrigir o ambiente; não marcar o job como opcional para liberar regressão. Rollback de código é o revert do PR mantendo os controles existentes.

## Acceptance Criteria

- A não lê, edita nem exclui cada recurso privado de B coberto pela API.
- Login e cadastro respeitam limite e retornam `429` quando excedido.
- Recuperação, upload e endpoints de IA possuem limite definido, testado e sem efeito colateral após `429`.
- Credenciais inválidas não permitem enumerar contas.
- Login → logout → rota protegida retorna `401`.
- `php artisan test` roda do zero em CI, sem banco/cache local, e esse job é obrigatório.
- Staging valida domínios reais, cookies seguros, CORS/CSRF e CSP antes do deploy de produção.

## Open Decisions

Definir política única `404` versus `403`, domínios de produção, tempo de CI e se verificação de email deve proteger rotas sensíveis.
