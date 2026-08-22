# Plano completo de segurança: Vitality PLUS

## Objetivo

Transformar a segurança da API Laravel e da SPA em controles verificáveis: identidade, autorização, sessão, limites de abuso, validação de entrada, secrets, observabilidade e gates de entrega. A execução é incremental; nenhuma fase substitui as proteções existentes.

## Fase 0 — Baseline e decisão de contrato

1. Registrar domínios de frontend/API de dev, staging e produção, proxy reverso e responsável por cada ambiente.
2. Definir contrato de negação por recurso: preferencialmente `404` para objeto alheio (anti-enumeração) e `403` apenas quando a identidade do objeto não for sensível.
3. Inventariar todas as rotas por classe: pública, autenticada, proprietária, administrativa, cara (IA) e upload.
4. Manter `php artisan test` verde com banco SQLite em memória, sem cache/configuração local.

**Aceite:** inventário versionado, domínios confirmados e suíte isolada executando em checkout limpo.

## Fase 1 — Autorização por objeto

Padronizar o padrão `Policy + authorize` (ou query obrigatoriamente escopada a `user_id`) em toda rota que recebe identificador. Cobrir diário, refeições, dieta, meta, recomendação, meal plans, drafts e favoritos. Remover/arquivar rotas legadas que não são mais consumidas, ou submetê-las à mesma matriz.

**Testes obrigatórios:** usuário A tenta ler, criar referência, editar, arquivar/favoritar e excluir cada recurso de B; resposta é a contratada e nenhum dado de B é alterado.

**Aceite:** a matriz A × B está em `tests/Feature/Security` e é obrigatória na CI.

## Fase 2 — Autenticação, sessão e rate limit

### Política de limites

| Superfície | Chave recomendada | Limite inicial | Efeito ao exceder |
| --- | --- | --- | --- |
| Login | email normalizado + IP | 5/minuto | `429`, sem sessão |
| Cadastro | IP, com observação por fingerprint/egresso | 5/hora | `429`, sem usuário |
| Recuperação de senha | email + IP | 3/hora | resposta não enumerável; `429` ao exceder |
| Reenvio de verificação | usuário autenticado + IP | 3/hora | `429`, sem novo email |
| Avatar | usuário + IP | 10/hora | `429`, sem gravar objeto |
| Preview/regeneração Gemini | usuário + IP | definir por custo e plano comercial; começar conservador | `429`, sem chamada externa/draft adicional |
| APIs de leitura | usuário + IP | alto, observado inicialmente | `429` apenas após medir tráfego legítimo |

Implementar os limitadores em `AppServiceProvider`, nomeá-los por intenção e associá-los explicitamente às rotas. Para endpoints caros, limitar antes de chamar Gemini e registrar contador/latência/custo sem registrar dados sensíveis. Ajustar limites após observar staging, mantendo proteção contra contas/IPs compartilhados.

Preservar login genérico, regeneração de sessão no login e `logout` com `invalidate()` e `regenerateToken()`. Expirar por inatividade de modo coerente entre abas e renovar apenas com atividade válida.

**Aceite:** Feature Tests comprovam `429`, ausência de efeito colateral, mensagem genérica, login → logout → `401` e renovação/expiração conforme política aprovada.

## Fase 3 — Sessão Sanctum, CORS, CSRF e CSP em staging

Configurar allowlist exata para `SANCTUM_STATEFUL_DOMAINS` e `FRONTEND_URL`; definir `SESSION_DOMAIN` só quando necessário; exigir `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true` e `SameSite=Lax` (ou `None` + `Secure` apenas se a arquitetura for cross-site). Confirmar confiança do proxy para que HTTPS seja reconhecido corretamente.

Adicionar CSP restritiva no servidor/proxy, inicialmente em report-only se necessário. Validar também `X-Content-Type-Options: nosniff`, política de frame, referrer policy e HSTS no domínio HTTPS.

**Aceite:** Playwright em staging confirma login, CSRF, renovação/logout, headers e bloqueio de origem não permitida sem bloquear os recursos legítimos.

## Fase 4 — Entrada, upload e APIs administrativas

Substituir validações amplas por Form Requests nas rotas legadas restantes; validar tipo, limites, cardinalidade e ownership de referências. No avatar, manter validação MIME/tamanho e verificar conteúdo real de imagem, nome aleatório, disco sem execução e remoção segura. Para `admin`, testar usuário comum (`403`) e administrador, registrar ações críticas e revisar o processo de promoção a admin.

**Aceite:** requests inválidos não persistem estado; upload inválido não cria arquivo; rotas administrativas negam usuário normal.

## Fase 5 — Secrets, dependências e operação

Manter `GEMINI_API_KEY` somente em secret manager/pipeline, nunca em `.env` versionado ou logs. Criar rotação e alerta de falha/autorização do provedor. Executar auditoria de dependências no CI e atualizar Laravel/Sanctum/PHP com processo de patch. Centralizar logs de autenticação, `429`, falhas de autorização, uploads e chamadas Gemini, com retenção e mascaramento de PII.

**Aceite:** secret scan e dependency audit passam na CI; logs permitem investigar abuso sem expor credenciais ou dados de saúde.

## Fase 6 — Gates de CI e governança

Criar jobs obrigatórios, em ambiente limpo: lint/format, `php artisan test`, suíte `Security`, auditoria de dependências e, quando habilitado, E2E de staging. Publicar JUnit, cobertura e artefatos de falha. Toda rota nova deve declarar classificação, autorização e teste negativo no PR template.

**Aceite final:** nenhum merge ignora a suíte de segurança; os cenários A × B, abuso de autenticação/rate limit e encerramento de sessão fazem parte da CI obrigatória.

## Ordem recomendada

1. Fases 0–2 (bloqueia regressões de maior impacto).
2. Fase 6 para tornar as garantias obrigatórias.
3. Fases 3–5 em staging/produção, com rollout observável e rollback documentado.
