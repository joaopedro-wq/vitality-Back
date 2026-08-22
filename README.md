<div align="center">

# 🌱 Vitality PLUS — API

![Laravel](https://img.shields.io/badge/Laravel-11-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![Database](https://img.shields.io/badge/Database-relational-4169E1)
![Sanctum](<https://img.shields.io/badge/Auth-Sanctum%20(session)-3178C6>)
![Gemini](https://img.shields.io/badge/IA-Google%20Gemini-8E75B2?logo=googlegemini&logoColor=white)

**A API que sustenta o Vitality PLUS.** Catálogo nutricional curado, diário alimentar com snapshot
de macros, metas calculadas por fórmula científica e planos de refeição gerados por IA generativa —
tudo por trás de uma API REST autenticada por sessão Sanctum.

</div>

<br>

Este repositório é o **backend** do produto: uma API Laravel 11 consumida pelo frontend Angular em
`../vitality-front` (single-page application, ver o README de lá para telas e fluxo do produto).

## O que essa API faz

- 🍎 **Catálogo nutricional curado** — base inicial da tabela TACO (597 itens brasileiros, por
  100g), enriquecida com nutrientes detalhados da USDA Foundation Foods (e opcionalmente SR
  Legacy). Cada alimento carrega macros, calorias e nutrientes normalizados em tabelas próprias,
  além de uma categoria normalizada (`grupo_normalizado`) que junta rótulos brutos em português e
  inglês num conjunto curto e consistente pro frontend filtrar.
- 🤖 **Planos de refeição gerados por IA** — o cardápio nasce de um preview conversacional com o
  **Google Gemini**: a partir das respostas do usuário (refeições por dia, estilo de preparo,
  restrições) e da meta calórica já calculada, o modelo monta o dia inteiro, alimento por alimento.
  O rascunho (`MealPlanDraft`) pode ser regenerado por refeição, recriado do zero, ou ter um único
  item trocado — cada operação chama a IA de novo mantendo a refeição próxima da meta, com undo de
  uma alteração por vez.
- 📓 **Diário alimentar com snapshot histórico** — cada lançamento (`DiaryEntry`) grava os macros e
  micronutrientes do alimento no momento do consumo. Corrigir o catálogo depois nunca reescreve o
  histórico. A data nutricional roda em `America/Sao_Paulo`, consumo no futuro é recusado, e itens
  repetidos no mesmo lançamento são consolidados.
- 🎯 **Metas calculadas, não digitadas** — recomendação nutricional (TMB por Mifflin-St Jeor + fator
  de atividade, GET, macros) a partir do perfil do usuário. Uma recomendação por conta: um segundo
  `POST` para o mesmo usuário é rejeitado com `400`.
- 👤 **Perfil, avatar e catálogo administrável** — atualização parcial de perfil sem apagar campos
  não enviados, avatar com validação de tipo/tamanho e troca atômica em disco, e um painel
  administrativo (`auth:sanctum` + guard próprio) pra curar o catálogo: criar/editar/arquivar
  alimentos, reimportar a TACO, revisar imagens sugeridas e tags de restrição alimentar.

## Autenticação e segurança

A API usa **Laravel Sanctum stateful** para a SPA Angular. O navegador obtém primeiro o cookie
CSRF em `GET /sanctum/csrf-cookie` e, então, envia cookies de sessão nas chamadas à API. Login e
cadastro (`POST /api/login` e `POST /api/criar-usuario`) são públicos e limitados por rate limit;
as demais rotas usam `auth:sanctum`.

`POST /api/logout` invalida a sessão atual e `POST /api/session/refresh` a renova. O perfil da
sessão é consultado em `GET /api/user` e atualizado em `PUT /api/user`; a API não deve aceitar
consulta ou atualização arbitrária de usuário por ID. Recursos pessoais são escopados ao usuário
autenticado e o catálogo administrativo exige middleware `admin`.

Para desenvolvimento local, `FRONTEND_URL` e `SANCTUM_STATEFUL_DOMAINS` devem apontar para o
frontend. Em produção, configure cookies `Secure`/`SameSite`, CORS, domínio da sessão e CSP no
servidor de borda. Não use Bearer tokens persistidos em `localStorage` no cliente web.

`routes/api.php` e os controllers em `app/Http/Controllers` são a fonte de verdade do contrato.

## Internacionalização da API

A API também participa da internacionalização do produto. O frontend envia o cabeçalho
`Accept-Language` (`pt-BR` ou `en-US`) em cada requisição; a middleware `SetLocale` define o idioma
da requisição e devolve `Content-Language` na resposta. Português do Brasil é o fallback.

Mensagens de sucesso, validação e erros ficam em `lang/pt-BR/messages.php` e
`lang/en-US/messages.php`. Os nomes das refeições padrão e os rótulos gerados pelo planejador
alimentar são normalizados pelo locale antes de serem serializados pela API. Alimentos continuam
com o nome canônico do catálogo para preservar snapshots nutricionais; traduções específicas de
nomes devem ser adicionadas ao catálogo sem alterar o identificador ou os valores nutricionais.

## Stack técnica

| Camada         | Tecnologia                                                                       |
| -------------- | -------------------------------------------------------------------------------- |
| Framework      | Laravel 11, PHP 8.2                                                              |
| Autenticação   | Laravel Sanctum — Bearer token, sem sessão stateful                              |
| Banco de dados | PostgreSQL (dev/prod); SQLite em memória nos testes (forçado em `phpunit.xml`)   |
| IA generativa  | Google Gemini — geração e ajuste de planos de refeição (`GeminiMealPlanService`) |
| Catálogo       | TACO (seed idempotente) + USDA FoodData Central (Foundation Foods / SR Legacy)   |
| Testes         | PHPUnit                                                                          |
| Qualidade      | Laravel Pint                                                                     |

## Rodando localmente

Pré-requisitos: PHP 8.2, Composer 2.6+, PostgreSQL rodando localmente, e o frontend em
`../vitality-front` (opcional, mas é quem consome a API).

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Ajuste no `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=
DB_DATABASE=vitality
DB_USERNAME=
DB_PASSWORD=

FRONTEND_URL=http://localhost:4200
SANCTUM_STATEFUL_DOMAINS=localhost:4200
```

> `.env.example` ainda traz `DB_CONNECTION=mysql` de uma fase anterior do projeto — o ambiente real
> de desenvolvimento roda em PostgreSQL; use os valores acima.

```bash
php artisan migrate --seed   # cria as tabelas e importa a TACO (idempotente)
php artisan serve            # http://localhost:8000
```

Pra habilitar a geração de planos por IA, defina no `.env`:

```env
GEMINI_API_KEY=sua-chave-aqui
MEAL_PLAN_AI_ENABLED=true
```

Sem `GEMINI_API_KEY`, a geração de planos fica indisponível — o resto da API funciona normalmente.

### Enriquecendo o catálogo (opcional)

```bash
# USDA Foundation Foods — baixe o JSON oficial antes, em storage/app/imports/usda/...
php artisan foods:import-usda --dataset=foundation

# Base histórica SR Legacy, opcional
php artisan foods:import-usda --dataset=sr-legacy

# Recalcula a categoria normalizada de todo o catálogo (idempotente)
php artisan foods:normalize-groups
```

### Promovendo um admin

```bash
php artisan user:make-admin email@dominio.com
```

### Testes

```bash
php artisan test
```

Roda sempre contra **SQLite em memória** (forçado em `phpunit.xml`/`.env.testing`), nunca contra o
Postgres de desenvolvimento — `Tests\TestCase` aborta a suíte se outra conexão for selecionada.

## Decisões de arquitetura

- **Bearer token, não sessão** — `EnsureFrontendRequestsAreStateful` (Sanctum) foi removida de
  `bootstrap/app.php` de propósito. Essa middleware promove qualquer request com `Origin` batendo
  em `SANCTUM_STATEFUL_DOMAINS` pra sessão + CSRF, mesmo em rotas de `routes/api.php` — incompatível
  com o fluxo Bearer-only que o frontend usa.
- **Snapshot de macros no diário** — `DiaryEntryItem` guarda os valores nutricionais no momento do
  lançamento, não uma referência viva ao alimento. Corrigir um dado no catálogo (ex.: recalcular
  calorias da TACO) nunca reescreve o histórico de quem já registrou aquele alimento.
- **Meta e recomendação não impedem duplicata no banco** — `NutricaoRecomendada` rejeita um segundo
  `POST` com `400` quando já existe registro pro usuário, mas nada no schema impede duplicata em
  `Meta_diaria`. Quem evita acumular linha a cada salvamento é o frontend: sempre consulta o `index`
  antes de decidir `POST` ou `PUT` (`RecomendacaoService`/`MetaService`, em `../vitality-front`) —
  vale saber disso ao consumir a API de outro cliente, que precisa replicar a mesma checagem.
- **Grupo normalizado é aditivo** — `grupo_normalizado` convive com o `grupo` bruto original (texto
  livre vindo direto do CSV da TACO ou do JSON da USDA, em dois idiomas). Nenhum dos dois substitui
  o outro; o normalizado existe só pra dar ao frontend um conjunto curto e estável de categorias pra
  filtrar.

Ver mais notas de arquitetura, contrato de API completo e histórico de decisões no `CLAUDE.md` do
frontend (`../vitality-front/CLAUDE.md`), que documenta os dois lados da integração.
