# Fluxo de branches

```
feature/xyz  →  PR  →  dev  →  PR  →  main  →  deploy automático (Contabo)
```

- **Nunca commite direto em `dev` ou `main`.** Toda mudança começa numa branch nova a
  partir da `dev`:
  ```bash
  git checkout dev && git pull
  git checkout -b feature/nome-da-mudanca
  ```
- Abra PR de `feature/xyz` → `dev`. O workflow **CI** (`.github/workflows/ci.yml`) roda
  testes (Pest), padrão de código (PHP-CS-Fixer, Prettier) e valida que o build do
  Vite/Docker funciona. Só mergeia com CI verde.
- De tempos em tempos (quando `dev` estiver estável), abra PR de `dev` → `main`.
- **Todo merge em `main` dispara deploy automático em produção** (workflow **Deploy**,
  `.github/workflows/deploy.yml`) — só depois que o CI daquele commit passar. `dev` nunca
  deploya sozinha, só roda CI.

## O que o GitHub garante (proteção de branch)

`main` e `dev` são protegidas — vale pra todo mundo, inclusive administradores:

| Regra | `main` | `dev` |
|---|---|---|
| Só recebe código via PR (push direto é recusado) | ✅ | ✅ |
| Os 4 jobs do CI precisam passar (PHP, JS/CSS, Docker, k6) | ✅ | ✅ |
| PR precisa estar atualizado com a branch antes do merge | ✅ | — (pra os PRs do Dependabot não precisarem ser atualizados a todo momento) |
| Comentários de revisão precisam estar resolvidos | ✅ | ✅ |
| Force push e apagar a branch | bloqueados | bloqueados |

Não há exigência de aprovação de outra pessoa: o projeto tem um mantenedor, e o GitHub não
deixa aprovar o próprio PR.

## Hook de pre-push (verificação local antes do push)

```bash
composer install          # já ativa o hook sozinho (post-install-cmd)
composer hooks:install    # ou ative manualmente: git config core.hooksPath .githooks
```

Antes de cada `git push`, o [`.githooks/pre-push`](.githooks/pre-push) roda localmente, em
poucos segundos, só as verificações que interessam pro que mudou:

- **bloqueia push direto pra `main`/`dev`** (o GitHub também recusaria, mas aqui você descobre antes);
- **bloqueia segredos**: arquivo `.env`, chave privada, `APP_KEY`/senha/token preenchidos nas linhas novas;
- PHP alterado → `php -l` nos arquivos, **Pest** e **PHP-CS-Fixer**; `composer.lock` alterado → `composer audit`;
- JS/CSS alterado → **Prettier**; `package-lock.json` alterado → `npm audit` (dependências do navegador).

Numa emergência (ou falso positivo de segredo): `git push --no-verify` ou
`SKIP_PRE_PUSH=1 git push`. O CI continua rodando tudo de qualquer jeito no PR.

## Deploy em produção

Veja a seção "Produção (Contabo)" no [README](README.md#produção-contabo).
