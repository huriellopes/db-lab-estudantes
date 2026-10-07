# Avaliação de segurança

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
| [#54](https://github.com/huriellopes/db-lab-estudantes/pull/54) | MySQL público sem trava de força bruta, sem limite de conexões, `root@'%'`; console sem limite de memória/tempo | 🔴 Alta | `connection_control`, `MAX_USER_CONNECTIONS`, `MYSQL_ROOT_HOST`, console com teto de linhas/tempo/execuções |
| [#55](https://github.com/huriellopes/db-lab-estudantes/pull/55) | Senha mínima de 6 caracteres; seeder promovia a admin quem tivesse o e-mail certo; erro de conexão vazava detalhes | 🟠 Média | `PasswordPolicy`, `user:promote-admin` com confirmação, 500 genérico |
| este PR | Axios com advisories altas no bundle; imagens/actions sem versão fixa; Node 20 (EOL); código gravável pelo PHP | 🟡 Média/baixa | `npm audit fix`, versões fixas + SHA, Dependabot, `composer`/`npm audit` no CI, código só leitura |

Pendências conhecidas estão em "Não coberto por esta auditoria", no fim deste arquivo.

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
- **Força bruta direto na porta 3306** — o `RateLimiter` da app não vê essas tentativas.
  ~~O MySQL em si não tem lockout nativo~~ (corrigido em 2026-10-07: tem, ver
  "Endurecimento do MySQL público" abaixo).

**Mitigado**: isolamento entre contas continua garantido pelos `GRANT`s do MySQL (testado
ativamente, ver acima) — isso nunca dependeu da rede, só de cada conta só ter privilégio
no próprio schema. Então mesmo com a porta pública, ninguém lê schema alheio.

**Recomendação pra quem usa a conta**: senha forte de verdade agora importa mais do que
antes — vale reforçar isso pros alunos/professores.

## Isolamento do banco da app (`schoolapp`) com o MySQL público — avaliado

Pedido explícito: com a porta pública, garantir que só os schemas de aluno/usuário ficam
alcançáveis por conexão direta, nunca o database interno da aplicação.

**Já garantido pelo modelo de GRANT, testado ativamente** (ver tabela em "Modelo de dados
e acessos ao banco" acima) — nenhuma conta pessoal (`'login'@'%'`) jamais recebeu privilégio
nenhum sobre `schoolapp`; só o `appuser` acessa. Isso nunca dependeu da rede, e continua
valendo com a porta pública: `USE schoolapp` ou `SELECT` direto de uma conta pessoal
continuam voltando `Access denied` do próprio servidor.

**Mudança desta sessão — GRANT com wildcard pro console SQL criar schema**: cada conta
pessoal agora recebe, na criação (`SchemaProvisioner::createMysqlAccount`), um
`GRANT ALL PRIVILEGES ON `<login>\_\_%`.* TO '<login>'@'%'` — escopo idêntico ao prefixo que
`SchemaNameBuilder` já exige pros schemas criados pelo formulário, só que concedido de
antemão em vez de schema por schema. Motivo: permite `CREATE DATABASE <login>__algo;` **via
o próprio console SQL** (ver seção abaixo), sem abrir acesso a mais nada — o pattern nunca
bate com `schoolapp` (nome fixo, não tem esse prefixo) nem com o prefixo de outro usuário
(todo `_` literal do login é escapado como `\_` na hora de montar o pattern, senão o `_`
seria wildcard de 1 caractere e poderia colidir com o prefixo de um login "vizinho" — ex.:
sem o escape, `ana_costa` bateria também com `anaXcosta__`).

**Avaliado e não aplicado: restringir o host do `appuser`.** A mitigação "de manual" pra
esse cenário seria trocar `'appuser'@'%'` por `'appuser'@'<subnet interna>'`, deixando essa
conta (a única com alcance total) impossível de autenticar vindo de fora do Docker.
Não apliquei porque, nesse ambiente especificamente, é mais arriscado do que parece:

- As redes do Compose (`dblab`/`dblab-net`) não têm subnet fixa — o Docker aloca uma faixa
  livre a cada `up`, então um IP/netmask fixo no `mysql/init/01-grants.sql` quebraria (ou
  passaria a não restringir nada) na primeira vez que a faixa mudasse.
- Mesmo fixando a subnet (`ipam.config.subnet` no compose), se o host tiver o
  `userland-proxy` do Docker ativo (padrão em várias instalações), conexões chegando pela
  porta publicada — de dentro **ou de fora** do host — aparecem pro MySQL com o IP do
  gateway da bridge, não o IP real do cliente. Ou seja, a restrição por subnet interna
  correria o risco de **não bloquear ninguém de fora** (falso senso de segurança) em vez de
  só bloquear.
- Errar isso pra pior (restringir demais) derruba a conexão da própria app com o banco —
  o `appuser` é usado por tudo, incluindo o login.

Dado o risco de regressão sem conseguir validar o comportamento real do host de produção
primeiro, isso fica como recomendação futura (não como item pendente urgente): confirmar
se `userland-proxy` está desligado no host, fixar a subnet do `dblab-net`, e só então trocar
`'appuser'@'%'` por `'appuser'@'<subnet>/<netmask>'` via `RENAME USER` (preserva o hash de
senha, não precisa saber a senha em texto puro pra fazer a troca).

**Mitigação que já existe hoje** pro cenário "credencial do appuser vazou": ela não aparece
em nenhuma tela nem resposta de API — só vive no `.env` do servidor —, e o
`appuser` já teve o privilégio reduzido de "equivalente a root" pro conjunto mínimo (ver
"Modelo de dados e acessos ao banco"). Continua sendo o ponto de maior impacto em caso de
vazamento, é só o host-restriction específico que não foi possível aplicar com segurança
agora.

## Endurecimento do MySQL público (2026-10-07)

- **Força bruta: plugin `connection_control`** (já vem com o MySQL 8.0; ligado no `command:`
  dos dois `docker-compose*.yml`). Depois de 5 senhas erradas seguidas pro mesmo usuário
  vindo do mesmo host, cada tentativa nova espera 1s, 2s, 3s... até 30s. Escolhido no lugar
  de `FAILED_LOGIN_ATTEMPTS`/`PASSWORD_LOCK_TIME` (existe desde a 8.0.19), que trava a
  **conta** inteira por no mínimo 1 dia: aí qualquer pessoa travaria o console SQL e o
  phpMyAdmin de um colega só errando a senha dele de propósito. Limite conhecido: a conta
  atrasada é contada por usuário+host, e o phpMyAdmin conecta sempre do mesmo host (o
  container) — quem errar a senha de alguém pelo phpMyAdmin atrasa (até 30s, sem bloquear)
  o phpMyAdmin dessa pessoa enquanto continuar errando.
- **`MAX_USER_CONNECTIONS 10`** por conta de aluno/professor (`SchemaProvisioner::MAX_USER_CONNECTIONS`,
  backfill na migration `2026_10_07_000003`) — uma conta só não esgota o `max_connections`
  do servidor inteiro.
- **Console SQL**: `max_execution_time` de 10s por SELECT, leitura unbuffered com teto de
  300 linhas (`App\Support\CappedResult` — antes era `fetchAll()` e só depois o corte), 60
  execuções por minuto por usuário. `LOAD DATA LOCAL INFILE` continua desligado (padrão do
  PDO, testado: erro 3948).
- **`root@'%'`**: a imagem oficial cria root aceitando qualquer host. `MYSQL_ROOT_HOST=localhost`
  nos compose resolve **só em volume novo**. Em volume existente (produção), remover na mão,
  depois de confirmar que `root@localhost` existe (o healthcheck usa o socket local):

  ```sql
  SELECT user, host FROM mysql.user WHERE user = 'root';  -- precisa listar 'localhost'
  DROP USER 'root'@'%';
  ```

  O `appuser` não consegue fazer isso por conta própria (nem alterar o root): root tem
  `SYSTEM_USER`, e o `CREATE USER` do `appuser` não alcança contas com esse privilégio.
- **Ainda não aplicado: `REQUIRE SSL` nas contas de aluno.** Forçaria TLS nas conexões
  externas, mas o console SQL (`Database::connectAs`) e o phpMyAdmin também entram com a
  conta do aluno, e nenhum dos dois usa TLS hoje. Pra ligar sem quebrar nada: TLS no PDO do
  console (`Pdo\Mysql::ATTR_SSL_CA` com o `ca.pem` do volume do MySQL), `PMA_SSL=1` no
  phpMyAdmin, avisar a turma pra marcar "Use SSL" no SGBD local, e só então
  `ALTER USER ... REQUIRE SSL`.

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

- **Rate limit só existe nas 3 rotas públicas** (login, cadastro, esqueci-senha) — as
  únicas alcançáveis sem estar autenticado. Rotas pós-login (trocar senha, resetar senha
  de aluno, console SQL) não têm, mas todas exigem sessão válida antes — não são um alvo
  de força bruta anônima. O console SQL em específico poderia, em teoria, ser usado pra
  martelar o MySQL com queries repetidas num loop automatizado; como cada pessoa só afeta
  o próprio schema/conexão, é mais um risco de recurso próprio que de terceiros, mas é o
  candidato mais razoável a rate limit se isso virar problema na prática.
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

## Disponibilidade (uptime) em produção — o que foi feito e o que não dá pra prometer

Pedido explícito: "garanta que essa aplicação jamais caia, fique sempre 24/7 no ar". Não
tenho acesso SSH direto ao servidor (as credenciais de deploy existem só como secret do
GitHub Actions, ver `.github/workflows/deploy.yml`) — tudo aqui é feito via mudança no
repo, que só chega em produção no próximo deploy (`dev` → `main`, disparado por quem
mergeia). E nenhum sistema consegue prometer 100% de uptime de verdade — o que dá pra
fazer é reduzir bastante a chance e o tempo de uma queda:

- **Rotação de log** (`docker-compose.prod.yml` e `docker-compose.yml`, todos os
  serviços): sem isso, o log de cada container cresce sem limite — em meses/anos de
  uptime, disco cheio já derrubou servidor inteiro em outros contextos (não só esse app —
  o Contabo hospeda vários projetos em `/apps/*`). Limitado a 10MB × 3 arquivos por
  serviço.
- **`autoheal`** (container novo, `willfarrell/autoheal`): reinicia sozinho `mysql` ou
  `app` se o `HEALTHCHECK` do Docker marcar "unhealthy" — cobre o caso de um processo
  travado mas ainda "vivo" (php-fpm engasgado, por exemplo), que o `restart: unless-
  stopped` sozinho não pega (só reage a o processo morrer de vez). Precisa de acesso ao
  socket do Docker (`/var/run/docker.sock`) pra poder reiniciar containers — na prática
  equivalente a root no host; aceito de propósito, decisão confirmada explicitamente
  antes de aplicar.
- **`restart: unless-stopped`** já existia em todos os serviços — cobre crash do processo
  e reboot do host (volta sozinho, contanto que ninguém tenha parado manualmente antes).

**Avaliado e não aplicado — precisa de mais informação**: limite de CPU/memória
(`deploy.resources.limits`) em produção, pro contrário também valer (esse app sozinho não
consumir todo o servidor e derrubar os outros projetos que moram lá, nem ser derrubado por
eles). Não apliquei um número às cegas porque errar pra menos faria o próprio app cair sob
uso normal — exatamente o oposto do pedido. Falta saber o tamanho real do VPS (RAM/CPU
total) pra calibrar isso direito; documentado aqui como pendência.

**Fora do alcance de uma mudança no repo** (dependem de acesso direto ao servidor ou de
serviço externo — nenhum dos dois eu tenho aqui):
- Confirmar se o `docker.service` inicia sozinho no boot do host (normalmente já vem
  assim numa instalação padrão do Docker, mas não dá pra confirmar sem acessar a máquina).
- Monitoramento/alerta externo (ex.: UptimeRobot, Better Uptime) — avisa alguém quando o
  site sai do ar de verdade, o que nenhuma das medidas acima faz sozinha. Precisa de uma
  conta/serviço terceiro escolhido por quem administra.
- Nginx Proxy Manager (fora deste repo) é quem termina o TLS e expõe o app pro público —
  se ele cair, o app fica inacessível mesmo saudável por dentro; a resiliência dele não
  está coberta aqui.

## Não coberto por esta auditoria (próximos passos recomendados)

- **`REQUIRE SSL` nas contas de aluno** — passo a passo em "Endurecimento do MySQL público"
  (precisa de TLS no console SQL e no phpMyAdmin antes, senão os dois param de funcionar).
- **phpMyAdmin público** — superfície grande e com histórico de CVEs. Recomendado: Access
  List no Nginx Proxy Manager (basic auth ou allowlist de IP) na frente do proxy host do
  `pma.`; a versão da imagem agora é fixa (`phpmyadmin:5.2.3`) e o Dependabot avisa quando
  sair correção.
- **CSP com `'unsafe-eval'`** — exigido pelo Alpine.js padrão. Tirar exige migrar pro build
  CSP do Alpine (`@alpinejs/csp`), que não aceita expressão inline nos atributos (`x-data=
  "ajaxForm({...})"` etc.) — refatoração de todas as views, por isso ficou de fora.
- **`appuser` com DML em `*.*`** — inclui o schema `mysql`. Restringir o host do `appuser` à
  subnet do Docker (avaliado e adiado antes, ver acima) segue sendo o maior ganho por linha
  alterada agora que o MySQL é público.
- **Log de auditoria** — troca de papel, reset de senha por admin/professor e DROP de schema
  pelo admin não deixam rastro além do log do container.
- **Cadastro aberto + sem verificação de e-mail** — qualquer pessoa ganha uma conta MySQL
  num servidor público. Convite/código de turma ou allowlist de domínio resolveriam.
- **MySQL 8.0 saiu de suporte (abril de 2026)** — migrar pra 8.4 LTS (e, junto, de
  `mysql_native_password` pra `caching_sha2_password`) num PR próprio, com backup antes.
- 2FA / MFA — fora de escopo pra esse tamanho de lab, mas vale considerar se crescer.
- `Strict-Transport-Security` (HSTS) não configurado — dá pra habilitar direto no Nginx
  Proxy Manager (toggle "HSTS Enabled" na tela de SSL do proxy host), preferível a
  configurar aqui: é o NPM que efetivamente termina o TLS, e HSTS mal configurado
  (`max-age` longo) é chato de reverter rápido se algo mudar.
