# Avaliação de segurança — SQL Injection

Auditoria de toda a superfície de acesso ao MySQL na aplicação (`app/Models`,
`app/Services`, `app/Controllers`), feita em 2026-08-12.

## Resumo

- **DML (SELECT/INSERT/UPDATE/DELETE)**: 100% via `PDO::prepare()` com placeholders (`?`)
  e `execute([...])`. Nenhuma interpolação de string em `app/Models/*.php`. Sem risco de
  SQL injection nesse caminho.
- **DDL (CREATE/DROP/ALTER/GRANT)**: concentrado inteiramente em
  `App\Services\SchemaProvisioner`. O MySQL **não aceita identificadores (nomes de
  database/usuário) como bind parameters** — por isso esses comandos são montados com
  interpolação de string (`"CREATE DATABASE \`{$dbName}\`"`). Esse é o único ponto de
  risco real de injeção na aplicação, e é mitigado por validação estrita (allow-list)
  **antes** de qualquer valor alcançar essas linhas — nunca por escaping.

## Pontos de interpolação em DDL e sua mitigação

| Comando | Valor interpolado | Validado por | Onde |
|---|---|---|---|
| `CREATE USER` | `$login` | `App\Support\MysqlIdentifier::build()` — gera o login (nunca aceita string livre do usuário) | `AuthController::register()` |
| `ALTER USER` (senha) | `$login` | Lido de `users.mysql_login`, coluna só escrita por código validado | `UserManager::resetPassword()` |
| `DROP USER` | `$login` | idem | `AuthController::register()` (rollback), `UserManager::deleteCompletely()` |
| `RENAME USER` (login do phpMyAdmin) | `$oldLogin`, `$newLogin` | `$oldLogin` idem (coluna já validada); `$newLogin` por `MysqlIdentifier::isValidCustomLogin()` (regex `^[a-z][a-z0-9_]{2,31}$` + lista de nomes reservados) | `ProfileController::updateMysqlLogin()` |
| `CREATE DATABASE` + `GRANT` | `$dbName` | `SchemaNameBuilder::isValidLabel()` no label do usuário **antes** de montar o nome | `SchemaController::store()` |
| `DROP DATABASE` | `$dbName` | `SchemaNameBuilder::isValidDbName()` (regex `^[a-z0-9_]{1,64}$`) **+** `SchemaRecord::findOwned()`/`findByName()` (o valor só passa se bater exatamente com um registro que a própria app gravou) | `SchemaController::destroy()`, `AdminController::destroySchema()`, `UserManager::deleteCompletely()` |

Padrão usado em todos os casos: **nunca escapar, sempre validar contra uma allow-list de
caracteres (`[a-z0-9_]`) antes de interpolar**. Senhas usadas em `IDENTIFIED BY` (que
aceitam bind normal de string) usam `$pdo->quote()`, não interpolação direta.

## Correção aplicada nesta auditoria

`SchemaController::destroy()` checava posse comparando o prefixo do `db_name` com o
`mysql_login` **atual** da sessão. Isso ficaria incorreto assim que a troca de login do
phpMyAdmin (`RENAME USER`) foi implementada — o nome do database não muda quando a conta
é renomeada, então o prefixo fica "desatualizado" para sempre. Trocado para depender
apenas de `SchemaRecord::findOwned($dbName, $userId)`, que é a fonte de verdade real
(tabela `schemas_criados`, por `user_id`) e não sofre desse problema.

## Console SQL (`POST /dashboard/sql`) — SQL arbitrário, de propósito

Essa rota roda **qualquer SQL** que a pessoa digitar — o oposto do padrão acima (allow-list
+ bind parameter). É seguro pelo desenho da conexão, não por validação de input:

- `App\Core\Database::connectAs()` abre uma conexão PDO **nova**, autenticada com o login e
  senha MySQL **reais da própria pessoa** — nunca a conexão admin (`appuser`) usada pelo
  resto da app. Os `GRANT`s que `SchemaProvisioner::createDatabase()` já aplica (por schema,
  na criação) são a única barreira, e são aplicados pelo MySQL, não pela aplicação: um
  `USE outro_schema` ou `SELECT * FROM outro_schema.tabela` volta `1044 Access denied`
  direto do servidor, mesmo que o comando em si seja sintaticamente válido.
- A senha usada nessa conexão é cacheada (criptografada, `App\Support\Crypto`, libsodium)
  na sessão no login — nunca em texto puro em disco (ver `README.md`, seção "Console SQL").
- `App\Support\SqlScriptSplitter` só separa comandos por `;` (respeitando strings/
  comentários) pra rodar um de cada vez — não interpreta nem sanitiza o conteúdo de cada
  comando, e isso é intencional: qualquer comando que o login MySQL da pessoa tenha
  permissão de rodar é permitido, do mesmo jeito que seria via phpMyAdmin ou um cliente
  MySQL comum.

## Outras camadas relevantes (fora do escopo estrito de SQL, mas parte da mesma auditoria)

- **XSS**: Twig com `autoescape: 'html'` ligado por padrão (`App\Core\View`) — toda
  variável impressa com `{{ }}` é escapada automaticamente.
- **Senhas**: `password_hash()`/`password_verify()` (bcrypt), nunca texto puro persistido
  na tabela `users`.
- **Privilégio do usuário da aplicação**: `appuser` tem `ALL PRIVILEGES ON *.* WITH GRANT
  OPTION` — decisão deliberada para viabilizar criação dinâmica de databases/contas nesse
  laboratório local (ver aviso no `README.md`). Isso significa que a barreira real contra
  um `db_name`/`mysql_login` malicioso é **inteiramente a validação em `App\Support`**,
  não um limite de privilégio do lado do MySQL — por isso essa validação é tratada como
  código de segurança crítico, coberto por testes (`tests/Unit/SchemaNameBuilderTest.php`,
  `tests/Unit/MysqlIdentifierTest.php`).

## Autenticação: proteções contra força bruta e CSRF

- **CSRF**: token por sessão (`App\Support\Csrf`, synchronizer token pattern), verificado
  de forma centralizada em `public/index.php` pra toda requisição POST — antes de qualquer
  rota rodar, não precisa repetir em cada controller. Formulários clássicos mandam via
  campo oculto `_csrf` (`csrf_field()` no Twig); requisições Axios mandam via header
  `X-CSRF-Token` (lido de uma `<meta>` no `<head>`, setado como header padrão em
  `resources/js/app.js`). Sem o token certo, a resposta é `419` (JSON pra AJAX, página de
  erro pro resto).
- **Rate limiting** em `/login` (por IP **e** por identificador, ver `App\Services\
  RateLimiter`/`App\Support\RateLimitDecision`) e `/register` (por IP) — guardado no MySQL
  (tabela `rate_limit_hits`), sem depender de Redis. `App\Support\ClientIp` resolve o IP
  real via `X-Forwarded-For` (a app fica atrás do Nginx Proxy Manager).
- **Timing leak corrigido**: login com identificador inexistente agora roda
  `password_verify()` contra um hash fixo (`AuthController::DUMMY_HASH`) mesmo sem usuário
  — antes, essa checagem era pulada inteira quando o usuário não existia, e dava pra
  enumerar contas medindo o tempo de resposta (bcrypt ativo vs. não).
- **Cookie de sessão**: `HttpOnly` + `SameSite=Lax` sempre, `Secure` quando a requisição
  chega por HTTPS de verdade (`App\Support\RequestScheme`, via `X-Forwarded-Proto`).

## Redefinição de senha por e-mail (self-service)

- Token de 256 bits (`random_bytes(32)`), guardado no banco só como hash (sha256,
  `App\Models\PasswordResetToken`) — o texto puro só existe no e-mail enviado. Expira em
  1h, uso único (`used_at`), e pedir um novo invalida qualquer token anterior da mesma
  conta automaticamente.
- Resposta de `/esqueci-senha` é **sempre a mesma mensagem**, exista o e-mail ou não —
  evita enumeração de contas por aqui (confirmado manualmente: e-mail real e inexistente
  devolvem o mesmo 200 com o mesmo texto).
- Rate limit próprio (por IP) além do token em si já ser inadivinhável.
- `App\Core\Mailer` (SMTP via PHPMailer) não derruba a aplicação se `MAIL_HOST` não
  estiver configurado — só loga e segue (dev/instâncias novas funcionam sem SMTP; o fluxo
  de reset fica inoperante até alguém preencher isso).

## Não coberto por esta auditoria (próximos passos recomendados)

- Cabeçalhos de segurança HTTP (CSP, `X-Frame-Options`, etc.) não configurados no nginx.
- 2FA / MFA — fora de escopo pra esse tamanho de lab, mas vale considerar se crescer.
- Isolamento entre professores: qualquer professor gerencia qualquer aluno do sistema (sem
  vínculo turma/professor) — decisão de design, não bug, mas vale confirmar que é
  intencional se o lab crescer pra múltiplos professores.
