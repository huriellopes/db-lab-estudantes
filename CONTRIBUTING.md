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

## Deploy em produção

Veja a seção "Produção (Contabo)" no [README](README.md#produção-contabo).
