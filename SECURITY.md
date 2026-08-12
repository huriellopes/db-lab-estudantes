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
- **Privilégio do usuário da aplicação**: `appuser` tem, de propósito, um conjunto amplo
  de privilégios `ON *.* WITH GRANT OPTION` — necessário pra viabilizar criação dinâmica
  de databases/contas nesse laboratório (ver seção "Modelo de dados e acessos ao banco"
  abaixo pro conjunto exato, reduzido de "praticamente root" pra só o que o código usa).
  Isso significa que a barreira real contra um `db_name`/`mysql_login` malicioso é
  **inteiramente a validação em `App\Support`**, não um limite de privilégio do lado do
  MySQL pra esses valores específicos — por isso essa validação é tratada como código de
  segurança crítico, coberto por testes (`tests/Unit/SchemaNameBuilderTest.php`,
  `tests/Unit/MysqlIdentifierTest.php`).

## Modelo de dados e acessos ao banco

**Tabelas** (`schoolapp`, só o `appuser` acessa): `users`, `schemas_criados` (FK
`ON DELETE CASCADE` pra `users` — só entra em ação em exclusão física, que a app nunca faz
sozinha, já que usa soft delete), `rate_limit_hits`, `password_reset_tokens`. Nenhuma
guarda dado sensível em texto puro (senha sempre `password_hash`, token sempre hash
sha256) nem coluna supérflua.

**Achado corrigido — `appuser` tinha privilégios equivalentes a `root`**: comparei
`SHOW GRANTS` de `appuser` com `root` em produção — eram **idênticos**
(`SUPER`, `FILE`, `SHUTDOWN`, `RELOAD`, `PROCESS`, `REPLICATION SLAVE`/`CLIENT`,
`CREATE TABLESPACE`, `CREATE`/`DROP ROLE`, e todos os privilégios administrativos
dinâmicos do MySQL 8 — `BACKUP_ADMIN`, `BINLOG_ADMIN`, `CONNECTION_ADMIN`,
`ENCRYPTION_KEY_ADMIN`, `SYSTEM_VARIABLES_ADMIN` etc., todos `WITH GRANT OPTION`), muito
além do que `App\Services\SchemaProvisioner` de fato executa (`CREATE`/`DROP DATABASE`,
`CREATE`/`ALTER`/`RENAME`/`DROP USER`, `GRANT` em schemas específicos). Causa: `mysql/init/
01-grants.sql` roda como `root` (assim que o entrypoint oficial do MySQL executa scripts
de init), e um `GRANT ALL PRIVILEGES ON *.*` feito por uma conta que já possui privilégios
dinâmicos (root tem todos) propaga esses privilégios dinâmicos junto — não é intencional,
é como o MySQL 8 expande `ALL PRIVILEGES` nesse caso.

Por que importa: a credencial do `appuser` fica em texto puro no `.env`. Com o privilégio
antigo, um vazamento dela (bug futuro, backup mal protegido) equivalia a vazar a senha de
root — desligar o servidor, configurar replicação pra copiar o binlog inteiro, ler/escrever
arquivo (mitigado em parte por `secure_file_priv` já restrito), mudar variável de sistema.

**Corrigido**: `mysql/init/01-grants.sql` agora concede só o necessário (privilégio
enumerado explicitamente, nunca `ALL PRIVILEGES` — nomear privilégios estáticos não
arrasta os dinâmicos, só `ALL`/`ALL PRIVILEGES` faz isso):

```sql
GRANT CREATE, DROP, ALTER, CREATE USER,
      SELECT, INSERT, UPDATE, DELETE, REFERENCES, INDEX,
      CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE,
      CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE,
      EVENT, TRIGGER
  ON *.* TO 'appuser'@'%' WITH GRANT OPTION;
```

Aplicado com `REVOKE` cirúrgico (não recriando do zero) tanto em dev quanto em produção,
validado rodando a bateria completa de fluxos que dependem do MySQL antes e depois:
registro, login, criar/excluir schema, console SQL, trocar senha, renomear login,
ativar/desativar (admin), resetar senha (admin), soft delete/restaurar (admin) — todos
passando com o privilégio reduzido.

Efeito colateral descoberto no processo: `FLUSH PRIVILEGES` (chamado depois de todo
`CREATE`/`ALTER`/`RENAME USER`/`GRANT` em `SchemaProvisioner`) exige `RELOAD` — mas é
**desnecessário**: confirmado na prática que `CREATE USER`/`GRANT` já atualizam o cache de
privilégios sozinhos (um usuário criado sem nenhum `FLUSH` já loga e usa o `GRANT` na
hora). Removido do código — um motivo a menos pra precisar de `RELOAD`.

**Isolamento entre usuários — testado ativamente, não só lido no código**: com conta
descartável, tentei violar o isolamento na mão:

| Tentativa | Resultado |
|---|---|
| `USE schoolapp` (schema da própria app) | `1044 Access denied` |
| `SELECT * FROM schoolapp.users` direto, sem `USE` | `1142 SELECT command denied` |
| `SHOW DATABASES` | Só `information_schema`/`performance_schema` — nem `schoolapp` nem schema de outra pessoa aparece |
| `SHOW PROCESSLIST` / `performance_schema.processlist` | Só a própria sessão — não vê query de outros usuários |

## MySQL público (decisão explícita — sem túnel SSH)

A partir desta sessão, o MySQL de produção é público (`0.0.0.0:${MYSQL_PORT}`) — decisão
pedida explicitamente, pra qualquer aluno/professor conectar direto por um SGBD local
(TablePlus, DBeaver etc.) sem precisar abrir túnel SSH antes. Isso **reverte** uma decisão
de segurança anterior (documentada nas sessões passadas) e reabre, de fato, riscos que
antes eram só teóricos:

- **Tráfego sem criptografia por padrão**: sem o túnel SSH, a conexão MySQL não é mais
  automaticamente criptografada. O servidor aceita TLS (certificado autoassinado que o
  próprio MySQL gera sozinho) se o cliente pedir, mas não é obrigatório — não forcei
  (`require_secure_transport`) porque isso quebraria a conexão interna da própria app
  (`Database.php`) sem retrabalho considerável. Quem usar "Use SSL" no cliente fica
  protegido contra bisbilhotagem passiva; quem não usar, não.
- **`@'%'` (qualquer host) agora importa de verdade** — antes um risco só teórico
  (mitigado 100% pela rede), agora é a única barreira de rede que resta. A senha de cada
  conta é a defesa real.
- **Sem rate limit no nível do MySQL** — o rate limit que existe (`RateLimiter`) é só na
  aplicação; um ataque de força bruta direto na porta 3306 não passa por ele. O MySQL em
  si não tem lockout nativo por tentativa errada (só `max_connect_errors`, por host, que
  não tentei ajustar pra não arriscar bloquear gente legítima atrás de NAT compartilhado).

**Mitigado**: isolamento entre contas continua garantido pelos `GRANT`s do MySQL (testado
ativamente, ver acima) — isso nunca dependeu da rede, só de cada conta só ter privilégio
no próprio schema. Então mesmo com a porta pública, ninguém lê schema alheio.

**Recomendação pra quem usa a conta**: senha forte de verdade agora importa mais do que
antes — vale reforçar isso pros alunos/professores.

## Outros pontos de configuração do MySQL

- Todas as contas MySQL (`appuser` e as pessoais) usam `@'%'` (qualquer host) — ver seção
  acima, agora é um risco ativo, não só teórico.
- `mysql_native_password` em vez do padrão mais atual do MySQL 8
  (`caching_sha2_password`) — algoritmo de hash mais antigo, oficialmente deprecated desde
  a 8.0.34. **Tentativa de migração feita e revertida nesta sessão**: contas novas com
  `IDENTIFIED WITH caching_sha2_password` funcionam pra logar na app, mas **quebram o
  console SQL** — a conexão por usuário (`Database::connectAs()`) roda sem TLS entre os
  containers, e `caching_sha2_password` exige troca de chave RSA que falha nesse cenário
  (`Access denied`, mesmo com a senha certa — testado e confirmado). Corrigir de verdade
  exigiria TLS entre app/phpMyAdmin e o MySQL (gerar/gerenciar certificado, configurar o
  servidor) — mudança de infraestrutura maior que o escopo desta sessão, fica pra decisão
  futura.

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

## Vazamento de erro (corrigido)

Confirmado na prática (uma exceção de teste, provisória, revertida em seguida): sem
configuração própria de PHP, `display_errors` vinha ligado por padrão na imagem
`php:8.5-fpm` — um erro não tratado devolvia stack trace completo **e caminho de arquivo
do servidor** direto na resposta HTTP, com status 200/500 normal (não era preciso nada
especial pra ver isso, só um bug comum de runtime bastava). Corrigido com
`docker/php-hardening.ini`:

- `display_errors = Off` — erro nunca mais aparece na resposta.
- `log_errors = On` + `error_log = /dev/stderr` — o erro real continua indo pro log
  (`docker logs`, mesmo fluxo de depuração já usado o resto do projeto), só não vaza pra
  quem está navegando.
- `expose_php = Off` — tira o header `X-Powered-By: PHP/x.y.z` (não ajuda em nada e só
  entrega a versão exata pra quem for procurar CVE conhecida).
- `set_exception_handler()` em `public/index.php`: rede de segurança final — qualquer
  exceção que escape de um controller vira uma página 500 normal (`errors/500.twig`) em
  vez de branco ou do erro cru do PHP.

## Mapa de endpoints

39 rotas (`public/index.php`), auditadas uma a uma: guarda de autorização, rate limiting,
validação de entrada e o que cada uma expõe.

| Área | Rotas | Autorização | Rate limit | Validação de entrada | Exposição de dados |
|---|---|---|---|---|---|
| Público | `/`, `GET /login`, `GET /register`, `GET /esqueci-senha` | nenhuma (por design) | — | — | Nada sensível — formulários vazios |
| Login | `POST /login` | nenhuma | ✅ por IP e por identificador (6/5min + 15/5min) | `password_verify` roda sempre (sem timing leak, ver seção acima) | Mensagem de erro genérica, não diferencia usuário inexistente de senha errada |
| Cadastro | `POST /register` | nenhuma | ✅ por IP (8/15min) | `RegistrationValidator` + `ProfileFields` (tamanho, formato, allow-list de username) | — |
| Logout | `POST /logout` | nenhuma (idempotente) | — | — | — |
| Esqueci senha | `POST /esqueci-senha` | nenhuma | ✅ por IP (5/15min) | `filter_var` e-mail | Resposta **idêntica** exista o e-mail ou não |
| Redefinir senha | `GET/POST /redefinir-senha[/{token}]` | nenhuma | — (token de 256 bits já é a proteção — força bruta nele não é viável) | token via hash lookup, senha 6+ chars | Página só diz "válido"/"inválido", nunca a quem pertence |
| Painel próprio | `GET /dashboard`, `GET/POST /profile*`, `GET /conectar` | `requireLogin` | ❌ nenhum (self-service, exige senha atual pra trocar senha) | `ProfileFields`, `MysqlIdentifier`, `TableQuery` (allow-list de sort) | Só dados da própria conta |
| Schemas próprios | `POST /schemas`, `POST /schemas/delete` | `requireLogin` | ❌ nenhum | `SchemaNameBuilder` + posse via `SchemaRecord::findOwned` (não pelo prefixo do login atual) | — |
| Console SQL | `POST /dashboard/sql` | `requireLogin` | ❌ nenhum (ver observação abaixo) | schema via `isValidDbName`; o SQL em si é livre **de propósito** (ver seção própria) | Isolado por schema via `GRANT` do MySQL, não por validação da app |
| Alunos (professor) | `GET/POST /professor/alunos*` | `requireProfessorOrAdmin` | ❌ nenhum | `findStudentOrFail` restringe a `role = Aluno` | **Qualquer professor vê/gerencia todos os alunos do sistema**, sem vínculo turma/professor (ver observação abaixo) |
| Usuários (admin) | `GET/POST /admin/usuarios*` | `requireAdmin` | ❌ nenhum | `findManageableUserOrFail` exclui outros admins e a própria conta | Lista todo mundo — esperado, é o painel de administração |
| Schemas (admin) | `GET /admin/schemas`, `POST /admin/schemas/excluir` | `requireAdmin` | ❌ nenhum | `SchemaNameBuilder` + `findByName` | Lista todos os schemas do sistema com dono — esperado |

**Observações que não são bugs, mas valem registrar:**

- **Rate limit só existe nas 3 rotas públicas** (login, cadastro, esqueci-senha) — as
  únicas alcançáveis sem estar autenticado. Rotas pós-login (trocar senha, resetar senha
  de aluno, console SQL) não têm, mas todas exigem sessão válida antes — não são um alvo
  de força bruta anônima. O console SQL em específico poderia, em teoria, ser usado pra
  martelar o MySQL com queries repetidas num loop automatizado; como cada pessoa só afeta
  o próprio schema/conexão, é mais um risco de recurso próprio que de terceiros, mas é o
  candidato mais razoável a rate limit se isso virar problema na prática.
- **Isolamento entre professores**: `StudentController::index()` lista **todos** os
  alunos do sistema (`UserModel::all(Role::Aluno)`) pra **qualquer** professor — não existe
  o conceito de turma/vínculo. Decisão de design (já sinalizada antes), não bug — mas
  significa que, se o lab crescer pra vários professores de turmas diferentes, um professor
  pode editar/desativar/excluir alunos que não são "dele".
- **Nenhum endpoint expõe `password_hash` ou senha MySQL em texto puro** em nenhuma
  resposta — a sessão (`AuthenticatedUser`) é construída de propósito sem esse campo, e
  nenhum template Twig referencia `.passwordHash` em lugar nenhum.

## Cabeçalhos HTTP de segurança (corrigido)

`docker/nginx.conf` agora manda, em toda resposta (`always`, inclusive erro 403/404/50x):

- `Content-Security-Policy`: `script-src 'self' 'unsafe-eval'` (o `unsafe-eval` é
  necessário — Alpine.js usa `Function()` pra avaliar `x-data`/`@click`/etc., é assim que a
  biblioteca funciona), `style-src`/`img-src`/`font-src`/`connect-src` só `'self'`,
  `object-src 'none'`, `frame-ancestors 'self'`. Ainda bloqueia o principal: script/estilo
  de origem externa e `<script>` injetado via um XSS que porventura apareça.
- `X-Frame-Options: SAMEORIGIN` (clickjacking), `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` bloqueando
  câmera/mic/geolocalização/pagamento (nada disso é usado pela app).

Testado no navegador (não só via curl): toggle de senha, dropdown do navbar e login
completo com conta descartável — zero mensagem no console, tudo funcionando normal com a
CSP ativa.

## Mensagens de erro genéricas pro usuário (corrigido)

Os ~13 pontos que devolviam `$e->getMessage()` cru (fora do console SQL, onde isso é
intencional) agora passam por `Controller::genericError($action, $e)`: loga o erro real
(`error_log`, mesmo destino de sempre) e devolve uma mensagem genérica no padrão já usado
("Não foi possível {$action}. Tente de novo em instantes."). Detalhe de MySQL/PDO
(estrutura de tabela, nome de constraint, etc.) não chega mais no navegador.

## Não coberto por esta auditoria (próximos passos recomendados)

- 2FA / MFA — fora de escopo pra esse tamanho de lab, mas vale considerar se crescer.
- `Strict-Transport-Security` (HSTS) não configurado — dá pra habilitar direto no Nginx
  Proxy Manager (toggle "HSTS Enabled" na tela de SSL do proxy host), preferível a
  configurar aqui: é o NPM que efetivamente termina o TLS, e HSTS mal configurado
  (`max-age` longo) é chato de reverter rápido se algo mudar.
