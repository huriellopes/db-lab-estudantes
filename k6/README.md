# Teste de carga (k6)

`load-test.js` simula dois perfis de tráfego contra a aplicação:

- **guest_browsing** — páginas públicas, sem login: `/login`, `/register`, `/esqueci-senha`.
- **authenticated_browsing** — páginas que exigem sessão: `/dashboard`, `/guia`,
  `/guia/{slug}`, `/laboratorio/modelagem`, `/profile`, `/conectar`.

A sessão autenticada é criada **uma única vez**, em `setup()` (registro + login de uma conta
descartável `k6-load-*@example.test`) e reaproveitada por todos os VUs do segundo cenário —
não é um atalho, é necessário: `App\Services\RateLimiter` limita `POST /login` a 15
tentativas/5min por IP e `POST /register` a 8/15min por IP, e todo o tráfego do k6 sai do
mesmo IP visto pela app. Um login por VU estouraria esse limite com poucos VUs.

Só faz `GET` nas páginas autenticadas — nada de `POST /dashboard/sql`, `/schemas` etc. — pra
não gerar dado real (schemas MySQL, e-mails, linhas em `saved_queries`...) que precisaria de
limpeza depois. O objetivo é validar que a aplicação continua respondendo bem sob carga, não
testar toda regra de negócio (isso é papel do `composer test`).

## Rodando localmente

```bash
cp .env.example .env   # se ainda não tiver um
docker compose up -d --build

k6 run k6/load-test.js
```

Perfil maior (mais VUs, plateau mais longo):

```bash
LOAD_VUS=30 LOAD_DURATION=1m k6 run k6/load-test.js
```

Contra outra URL (ex.: ambiente de homologação):

```bash
BASE_URL=https://homologacao.seu-dominio.com k6 run k6/load-test.js
```

## Variáveis de ambiente

| Variável        | Padrão                  | Descrição                                          |
|-----------------|--------------------------|-----------------------------------------------------|
| `BASE_URL`      | `http://localhost:8080` | URL base da aplicação                                |
| `LOAD_VUS`      | `10`                     | VUs simultâneos no platô de cada cenário             |
| `LOAD_DURATION` | `20s`                    | Duração do platô de cada cenário                     |

(Não use `K6_VUS`/`K6_DURATION` — esses nomes são reservados pelo próprio k6 e sobrescrevem
os cenários configurados em `options.scenarios`, forçando o modo simples de execução.)

## Thresholds

O `k6 run` sai com código de erro (falha o job de CI) se:

- mais de 1% das requisições falharem (`http_req_failed`);
- o p95 das páginas públicas passar de 800ms;
- o p95 das páginas autenticadas passar de 1000ms;
- mais de 1% dos `check()`s falharem (`errors`).

## No CI

O job `load-test` (`.github/workflows/ci.yml`) sobe `mysql` + `app` via `docker compose` num
`.env` descartável (gerado no próprio job, nunca as credenciais reais), espera `/login`
responder e roda este script contra `http://localhost:8080`. Roda depois que o job `docker`
confirma que a imagem builda, nos mesmos gatilhos do resto do CI (push/PR pra `dev`/`main`).
