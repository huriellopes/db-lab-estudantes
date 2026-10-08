# Segurança

## Como reportar uma vulnerabilidade

Encontrou uma falha? **Não abra uma issue pública.** Use o botão **"Report a vulnerability"**
na aba **Security** deste repositório (relato privado do GitHub). Descreva o problema, como
reproduzir e o impacto que você enxerga. A resposta inicial costuma sair em poucos dias, e o
crédito pela descoberta entra no PR da correção, se você quiser.

Este documento explica **como a aplicação se protege** e as decisões de projeto por trás
disso. Pendências e detalhes do ambiente de produção não ficam aqui de propósito: não faz
sentido publicar um mapa pra quem quer atacar.

## Histórico

Começou como auditoria de SQL injection (2026-08-12) de toda a superfície de acesso ao MySQL
(`app/Models`, `app/Services`, `app/Actions`) e virou o registro vivo das decisões de
segurança do projeto. **Última revisão: 2026-10-07.**

## Revisão de 2026-10-07 — o que foi corrigido

Varredura do projeto inteiro (código, Docker, nginx, CI). Cada item tem PR próprio, com
teste automatizado e/ou E2E no `docker compose` descritos no PR:

| # | Achado | Gravidade | Correção |
|---|---|---|---|
| [#51](https://github.com/huriellopes/db-lab-estudantes/pull/51) | Login MySQL liberado num rename podia ser cadastrado por outra pessoa, que herdava (via GRANT com wildcard) os schemas antigos | 🔴 Alta | Prefixo de schema fixo por conta (`users.schema_prefix`); login sem `__`/`_` no fim |
| [#52](https://github.com/huriellopes/db-lab-estudantes/pull/52) | Rate limit de login/cadastro contornável forjando `X-Forwarded-For` | 🔴 Alta | IP real resolvido no nginx (`real_ip`); `ClientIp` só lê `REMOTE_ADDR` |
| [#53](https://github.com/huriellopes/db-lab-estudantes/pull/53) | Sessão não sentia desativação/rebaixamento; trocar a senha não derrubava sessões nem "lembrar de mim" | 🔴 Alta | Revalidação por request, `session_version`, timeouts, reset de senha atômico |
| [#54](https://github.com/huriellopes/db-lab-estudantes/pull/54) | MySQL acessível sem trava de força bruta nem limite de conexões; console sem limite de memória/tempo | 🔴 Alta | `connection_control`, `MAX_USER_CONNECTIONS`, root só local, console com teto de linhas/tempo/execuções |
| [#55](https://github.com/huriellopes/db-lab-estudantes/pull/55) | Senha mínima de 6 caracteres; seeder promovia a admin quem tivesse o e-mail certo; erro de conexão vazava detalhes | 🟠 Média | `PasswordPolicy`, `user:promote-admin` com confirmação, 500 genérico |
| [#56](https://github.com/huriellopes/db-lab-estudantes/pull/56) | Axios com advisories altas no bundle; imagens/actions sem versão fixa; Node 20 (EOL); código gravável pelo PHP | 🟡 Média/baixa | `npm audit fix`, versões fixas + SHA, Dependabot, `composer`/`npm audit` no CI, código só leitura |


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
| `CREATE USER` | `$login` | Username escolhido no cadastro, validado por `MysqlIdentifier::isValidCustomLogin()` (regex `^[a-z][a-z0-9_]{2,31}$`, sem `__`, sem `_` no fim, fora da lista de nomes reservados) | `RegisterAction`, `StoreAdminUserAction` (via `UserManager::provisionNewUser()`) |
| `ALTER USER` (senha, lock/unlock) | `$login` | Lido de `users.mysql_login`, coluna só escrita por código validado | `UserManager::resetPassword()`, `setActive()`, `softDelete()`, `restore()` |
| `DROP USER` | `$login` | idem | `UserManager::provisionNewUser()` (rollback de cadastro que falhou no meio) |
| `RENAME USER` (login do phpMyAdmin) | `$oldLogin`, `$newLogin` | `$oldLogin` idem (coluna já validada); `$newLogin` por `MysqlIdentifier::isValidCustomLogin()` | `UpdateMysqlLoginAction` |
| `CREATE DATABASE` + `GRANT` | `$dbName` | `SchemaNameBuilder::isValidLabel()` no label do usuário **antes** de montar o nome | `StoreSchemaAction` |
| `GRANT` com wildcard do prefixo | `$schemaPrefix` | `users.schema_prefix` (fixado na criação da conta, mesma validação do login) + todo `_` escapado (`SchemaNameBuilder::grantPattern()`) | `SchemaProvisioner::createMysqlAccount()` |
| `DROP DATABASE` | `$dbName` | `SchemaNameBuilder::isValidDbName()` (regex `^[a-z0-9_]{1,64}$`) **+** `SchemaRecord::findOwned()`/`findByName()` (o valor só passa se bater exatamente com um registro que a própria app gravou) | `DestroySchemaAction`, `DestroyAdminSchemaAction` |

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
- **Sem schema selecionado, dá pra criar um** (`CREATE DATABASE <login>__algo;`, sem `USE`
  antes): cada conta pessoal ganha, na criação, um `GRANT ... ON `<login>\_\_%`.* ...`
  (mesmo prefixo que `SchemaNameBuilder` já exige nos schemas criados pelo formulário —
  ver "Isolamento do banco da app" acima). Depois de cada execução,
  `RunSqlAction::reconcileSchemas()` roda `SHOW DATABASES LIKE` (na conexão pessoal
  — só devolve o que a própria conta enxerga) e sincroniza `schemas_criados` com o que
  existe de fato, então um `CREATE`/`DROP DATABASE` feito assim aparece/some de "Meus
  schemas" sem precisar recarregar nada à parte.

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
ativar/desativar (admin), resetar senha (admin), excluir para o arquivo / restaurar / excluir definitivamente (admin) — todos
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

## MySQL acessível diretamente (decisão de projeto)

O MySQL aceita conexão direta, de propósito: alunos e professores usam o SGBD que preferirem
(DBeaver, TablePlus, Workbench), sem túnel SSH. Isso torna a porta uma superfície pública,
então a segurança não pode depender da rede. Ela se apoia em camadas que valem de qualquer
lugar:

- **Isolamento pelo próprio MySQL**: cada conta pessoal só tem `GRANT` nos próprios schemas
  (padrão `<prefixo>\_\_%`, com todo `_` literal escapado — sem o escape, `ana_costa`
  alcançaria também `anaXcosta__...`). Nenhuma conta pessoal tem privilégio sobre o banco
  interno da aplicação. Testado ativamente: `USE` em schema alheio ou no banco da app volta
  `1044 Access denied` do servidor.
- **Prefixo de schema fixo por conta** (`users.schema_prefix`): trocar o login MySQL não libera
  o prefixo antigo pra outra pessoa herdar os schemas (ver a revisão de 2026-10-07).
- **Força bruta**: plugin `connection_control` — depois de 5 senhas erradas seguidas pro mesmo
  usuário vindo do mesmo host, cada nova tentativa espera 1s, 2s, 3s... até 30s. Escolhido no
  lugar de `FAILED_LOGIN_ATTEMPTS`/`PASSWORD_LOCK_TIME`, que trava a **conta** inteira por no
  mínimo 1 dia: aí qualquer pessoa travaria o console e o phpMyAdmin de um colega só errando a
  senha dele de propósito. As opções vão com o prefixo `--loose-`, sem o qual o MySQL aborta na
  criação de um volume novo (o plugin ainda não está carregado no `--initialize`).
- **Senha forte**: a mesma senha abre a conta MySQL, por isso a `PasswordPolicy` (mínimo de 10
  caracteres, lista de senhas comuns, não pode conter username/e-mail).
- **Recursos**: `MAX_USER_CONNECTIONS 10` por conta e, no console SQL, `max_execution_time` de
  10s por SELECT, leitura unbuffered com teto de 300 linhas (`App\Support\CappedResult`) e 60
  execuções por minuto por usuário. `LOAD DATA LOCAL INFILE` desligado (erro 3948).
- **Root só local**: `MYSQL_ROOT_HOST=localhost` — a imagem oficial cria `root@'%'` por padrão.
  O `appuser` não consegue alterar o root: root tem `SYSTEM_USER`, e o `CREATE USER` do
  `appuser` não alcança contas com esse privilégio.
- **`log-bin-trust-function-creators=1`**: com o binary log ligado (padrão do MySQL 8), só
  contas com `SUPER` criam `TRIGGER`/`FUNCTION` (erro 1419), então nenhum aluno conseguia
  praticar triggers. A trava protege replicação baseada em comandos (`binlog_format=STATEMENT`);
  aqui o formato é `ROW`. O trigger roda com as permissões do próprio aluno (`DEFINER` = ele
  mesmo, sem `SET_USER_ID`), só nos schemas dele.
- **TLS**: o servidor aceita conexão criptografada (certificado gerado pelo próprio MySQL)
  quando o cliente marca "Use SSL" — recomendado pra quem conecta por rede pública.

### Por que `mysql_native_password`

`caching_sha2_password` (padrão mais novo) exige TLS ou troca de chave RSA na conexão. A
conexão por usuário do console SQL (`Database::connectAs()`) roda dentro da rede do Docker sem
TLS, e com `caching_sha2_password` ela falha com `Access denied` mesmo com a senha certa
(testado). A migração fica atrelada a ligar TLS entre os containers.

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
  real via `X-Forwarded-For` (a app fica atrás de um proxy reverso que termina o TLS).
- **Timing leak corrigido**: login com identificador inexistente agora roda
  `password_verify()` contra um hash fixo (`LoginAction::DUMMY_HASH`) mesmo sem usuário
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

## "Manter conectado" (remember-me)

- Checkbox opcional no login. Token de 256 bits (`random_bytes(32)`), guardado no banco só
  como hash (sha256, `App\Models\RememberToken`) — mesmo padrão do reset de senha. Cookie
  **separado** do de sessão do PHP (`remember_token`), `HttpOnly` + `SameSite=Lax` sempre,
  `Secure` quando a requisição chega por HTTPS de verdade — mesmas flags do cookie de
  sessão (`App\Support\RequestScheme`).
- Validade de 30 dias (`RememberToken::TTL_DAYS`), mas **rotativo**: toda vez que o cookie é
  usado pra reabrir sessão sozinho (`Auth::attemptRememberLogin()`, chamado uma vez no
  bootstrap de `public/index.php`), o token é trocado por um novo — se o cookie vazar,
  quem roubou só consegue usar até a próxima vez que a pessoa dona da conta abrir o site;
  depois disso o token roubado já não existe mais.
- Um token por dispositivo/navegador (ao contrário do reset de senha, que invalida
  qualquer token anterior) — múltiplas sessões "lembradas" simultâneas são esperadas
  (celular, notebook do trabalho...). Logout derruba só o token do dispositivo atual.
- De propósito **não** cacheia a senha MySQL (`Auth::login()` sem `$plainPassword`) quando
  a sessão é reaberta pelo cookie: quem autenticou foi o cookie, não a pessoa digitando a
  senha — o console SQL do dashboard continua exigindo um login de verdade pra funcionar
  (mensagem já existente em `RunSqlAction` cobre esse caso).
- Conta desativada entre uma visita e outra: `attemptRememberLogin()` confere `active`
  antes de reabrir a sessão, então desativar uma conta já barra o cookie dela também, sem
  precisar revogar o token manualmente.

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

- **Rate limit**: nas rotas públicas (login, cadastro, esqueci-senha), por IP real e por
  conta; no console SQL, 60 execuções por minuto por usuário; e na confirmação de senha do
  console, por usuário. As demais rotas pós-login exigem sessão válida e não são alvo de força
  bruta anônima.
- **Isolamento entre professores**: `IndexStudentsAction` lista **todos** os
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
  biblioteca funciona), `style-src 'self' 'unsafe-inline'` (o `unsafe-inline` é pelo mesmo
  motivo, só que pra estilo: o laboratório de modelagem posiciona as entidades arrastáveis
  via `:style` calculado em JS — sem isso o navegador aceita a mudança no atributo `style`
  mas recusa aplicá-la visualmente), `img-src`/`font-src`/`connect-src` só `'self'`,
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

## Arquivo de dados excluídos (`/admin/excluidos`)

Nada de negócio (contas, schemas, consultas salvas, diagramas) é apagado direto: vai para
`deleted_models` via `App\Services\Archiver`. Pontos de segurança:

- **Objetos programáveis não são recriados pela app.** Um schema em quarentena perde views,
  triggers, rotinas e eventos (o `RENAME TABLE` entre databases não os leva). Recriá-los com o
  aluno como `DEFINER` exigiria dar `SET_USER_ID` ao `appuser`, e recriá-los sem isso faria o
  código do aluno rodar com os privilégios da app sobre **todos** os schemas (escalada de
  privilégio). Em vez disso, as definições viram uma consulta salva do próprio aluno, sem
  `DEFINER`: ao rodá-la no console, o objeto nasce com ele como dono.
- **`deleted_models.values` guarda a linha inteira, inclusive `password_hash`**, porque sem ele
  um usuário restaurado ficaria sem senha. A tela mascara campos de senha/token/hash
  (`App\Support\ArchiveSnapshot`) e só admin tem acesso a ela. O arquivo entra nos backups
  do banco da aplicação como qualquer outra tabela.
- **Quarentena fora do alcance do aluno.** O database `_lixeira_s<id>` não casa com o `GRANT`
  por prefixo (`<prefixo>\_\_%`) da conta do aluno, então ele não lê nem altera os dados
  arquivados.
- **Nomes reservados.** E-mail, login, prefixo e nome de schema de itens arquivados não podem ser
  reutilizados até a exclusão definitiva: a conta MySQL bloqueada continua existindo, e liberar o
  login permitiria que outra pessoa "herdasse" o nome.
- **`DROP DATABASE` por fora** (console, phpMyAdmin, SGBD) não pode ser impedido sem tirar do
  aluno o `DROP TABLE` dos próprios schemas. O registro é arquivado como "removido fora da
  plataforma" e auditado, sem dados para restaurar.

## Bancos dos alunos para o professor (`/professor/bancos`)

- **Privilégios exatos.** A conta MySQL do professor recebe `SELECT, INSERT, UPDATE ON \`<prefixo>\_\_%\`.*`
  para cada aluno com quem divide instituição — nunca `DELETE`, `DROP` ou `ALTER` (nem `TRUNCATE`,
  que exige `DROP`). Assim o "pode ajudar, não pode excluir" vale no próprio MySQL: na tela, no
  phpMyAdmin e em qualquer SGBD. Testado em integração (DELETE/DROP/TRUNCATE/ALTER recusados).
- **Onde é concedido/revogado.** `App\Services\ProfessorGrants` recalcula do zero (idempotente) depois
  de vincular/remover membro, excluir instituição, trocar papel, entrar por código, cadastro com
  código e arquivar/restaurar usuário/instituição/vínculo; e no boot (`grants:sync-professors`).
  Aluno que sai da instituição, ou professor que deixa de ser professor, perde o acesso. Falha na
  sincronização vai para o log de erros e nunca derruba a ação principal.
- **Execução pela conta do professor.** Leitura e escrita usam `Database::connectAs` com a senha MySQL
  do professor (cifrada na sessão); o `appuser` só é usado para conferir o escopo
  (`InstitutionScope`). Banco fora do escopo responde 404. Identificadores vêm da estrutura real e
  vão entre crases; valores sempre como parâmetros (`App\Support\RowEditSql`).
- **Auditoria** (`professor.db_row_inserted` / `professor.db_row_updated`): banco, tabela, colunas e
  chave — nunca os valores (dados do aluno).

## Disponibilidade (uptime)

Nenhum sistema garante 100% de uptime; o que dá pra fazer é reduzir a chance e o tempo de uma
queda:

- **Rotação de log** em todos os serviços (10 MB × 3 arquivos): sem isso, o log de cada
  container cresce sem limite até encher o disco.
- **`autoheal`**: reinicia sozinho `mysql` ou `app` se o `HEALTHCHECK` marcar "unhealthy" —
  cobre um processo travado mas ainda "vivo", que o `restart: unless-stopped` não pega.
  Precisa do socket do Docker (equivalente a root no host); aceito de propósito em troca de
  resiliência.
- **`restart: unless-stopped`** em todos os serviços: volta sozinho depois de crash ou reboot.
- **Migrations no boot com retry** (`docker/app-entrypoint.sh`): a app só sobe depois que o
  banco responde e as migrations rodam.
