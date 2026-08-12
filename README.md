# DB Lab Estudantes

Ambiente de estudos em Docker com **MySQL 8**, **phpMyAdmin** e uma aplicação **PHP 8.5 em
MVC altamente tipado** (Composer, sem framework), templates em **Twig** (zero PHP misturado
com HTML), frontend com **Tailwind CSS v4 + Alpine.js + Axios** via **Vite**, migrations/
seeders/factories no estilo Laravel, e testes unitários com **Pest**.

Alunos se cadastram sozinhos; professores e admins são criados pelo admin. Todo mundo tem
uma conta MySQL real (username escolhido no cadastro), utilizável também no phpMyAdmin ou
num SGBD local.

## Papéis

| Papel | Pode |
|---|---|
| **Aluno** | Cadastrar-se (só alunos se autocadastram), logar com e-mail **ou** username, criar/excluir os próprios schemas, editar o próprio perfil (nome/senha/username). |
| **Professor** | Tudo do aluno, **+** ver, editar, ativar/desativar, resetar senha e excluir (soft delete) contas de aluno (`/professor/alunos`). |
| **Admin** (super admin) | Tudo do professor, **+** criar usuários de qualquer papel, gerenciar qualquer usuário não-admin (ativar/desativar, excluir, restaurar da lixeira), promover/rebaixar papéis, e ver/excluir **qualquer** schema do sistema (`/admin`). Promovido via `composer db:seed` + `ADMIN_EMAIL` no `.env` — não listado em `/admin/usuarios` (contas admin não aparecem nessa lista). |

## Como funciona

- No cadastro, a pessoa escolhe seu **username** — vira o login MySQL/phpMyAdmin *e* uma
  forma alternativa de logar na própria app (login aceita e-mail **ou** username).
- No painel (`/dashboard`), a pessoa cria schemas: a aplicação executa `CREATE DATABASE` e
  concede `GRANT ALL PRIVILEGES` **apenas** naquele schema para a conta MySQL da pessoa.
- Em "Meu perfil" dá pra trocar o nome, a senha (atualiza app + MySQL juntos) e o username/
  login do phpMyAdmin (`RENAME USER` real — preserva os acessos aos schemas já criados).
- **Ativar/desativar** (professor sobre alunos, admin sobre todo mundo): bloqueia o login na
  app *e* o acesso MySQL/phpMyAdmin (`ALTER USER ... ACCOUNT LOCK`), sem apagar nada —
  reversível a qualquer momento.
- **Excluir é soft delete**, como o `SoftDeletes` do Laravel: marca `deleted_at`, bloqueia o
  acesso MySQL, mas **não apaga** a linha, os databases nem a conta MySQL da pessoa. O admin
  vê e restaura contas excluídas em `/admin/usuarios/lixeira`.

## Migrations, seeders e factories (estilo Laravel)

```bash
composer migrate            # roda as migrations pendentes (também roda sozinho ao subir o container)
composer migrate:status      # lista o que já rodou
composer migrate:rollback     # desfaz o último lote
composer db:seed               # roda database/seeders/DatabaseSeeder (promove ADMIN_EMAIL a admin)
```

- `database/migrations/*.php`: cada arquivo devolve uma classe anônima `extends
  App\Core\Migration` com `up()`/`down()` — igual ao estilo de migration do Laravel 8+.
  `App\Core\Migrator` roda as pendentes e registra em uma tabela `migrations` (com `batch`,
  pra dar pra reverter o último lote).
- `database/seeders/`: `DatabaseSeeder` é o ponto de entrada; hoje só chama
  `AdminUserSeeder`, que promove `ADMIN_EMAIL` (do `.env`) a admin se a conta já existir —
  idempotente, seguro rodar de novo.
- `database/factories/UserFactory.php`: no estilo das factories do Laravel, com
  [`fakerphp/faker`](https://fakerphp.org/) (`require-dev` — só disponível localmente, não
  na imagem Docker de produção). `create()`/`createMany()` usam
  `App\Services\UserManager::provisionNewUser()`, o mesmo método usado no cadastro real e
  na criação de usuário pelo admin — então o resultado é um usuário "de verdade" (linha na
  app + conta MySQL), útil para popular um ambiente local de testes.
- O `Dockerfile`/`docker/app-entrypoint.sh` rodam `migrate` automaticamente toda vez que o
  container da app sobe, antes de iniciar o Apache — não precisa rodar nada na mão num
  `docker compose up` normal.

## Arquitetura

```
composer.json            # autoload PSR-4 (App\, Database\Seeders\, Database\Factories\)
package.json              # Vite, Tailwind v4, Alpine.js, Axios, Prettier
vite.config.js
Dockerfile                # multi-stage: composer -> npm/vite -> php:8.5-fpm + nginx + supervisord
docker-compose.yml           # dev — MESMA imagem/Dockerfile da produção (paridade de ambiente)
docker-compose.prod.yml       # produção (Contabo) — sem porta pública na app, rede própria
docker/
  nginx.conf                    # site do nginx (fastcgi -> php-fpm)
  supervisord.conf                # gerencia nginx + php-fpm dentro do container
  app-entrypoint.sh                # roda `migrate` e sobe o supervisord
  deploy.sh                         # roda NO SERVIDOR — git pull + docker compose up --build
.github/workflows/
  ci.yml                    # testes, padrão de código, build (toda branch/PR pra dev e main)
  deploy.yml                 # SSH no Contabo + deploy.sh (só depois do CI passar na main)
bin/console.php             # CLI (migrate, migrate:rollback, migrate:status, db:seed)
phpunit.xml, tests/          # Pest
.php-cs-fixer.php             # padrão de código PHP (PSR-12 + regras extra)
.prettierrc.json                # padrão de código JS/CSS
.editorconfig
SECURITY.md                       # avaliação de SQL injection e como é mitigada

database/
  migrations/    # classes anônimas com up()/down() — fonte da verdade do schema
  seeders/        # DatabaseSeeder, AdminUserSeeder
  factories/       # UserFactory (Faker)

app/
  Controllers/    # Auth, Dashboard, Schema, Profile, Connection, SqlConsole, Student (professor), Admin
  Core/            # Router (com {id} dinâmico), Controller, View (Twig), Vite, Database (PDO,
                    incl. connectAs() pro console SQL), Migration/Migrator/Console, Seeder,
                    Auth (sessão + papéis + senha MySQL cacheada), Flash, Config
  Support/         # Lógica pura, sem I/O — o que os testes do Pest cobrem:
                    Role (enum), AuthenticatedUser (com shortName()), AdminStats,
                    FlashType/FlashMessage, MysqlIdentifier, RegistrationValidator,
                    SchemaNameBuilder, Policy, TableQuery/Paginator/TableFilter (busca/
                    ordenação/paginação das listagens), Crypto (libsodium, senha MySQL em
                    cache de sessão), SqlScriptSplitter (console SQL)
  Models/
    Entities/          # DTOs readonly tipados: User, Schema, SchemaWithOwner, StudentSummary
    User.php, SchemaRecord.php  # acesso às tabelas da própria app, devolvem as Entities
  Services/        # SchemaProvisioner (DDL no MySQL, incl. ACCOUNT LOCK/UNLOCK), UserManager
                    (provisionNewUser/resetPassword/rename/setActive/softDelete/restore)
  Views/           # .twig — SEM PHP misturado, só a sintaxe do Twig
    layouts/ (app, guest), auth/, dashboard/, profile/, connection/, professor/students/,
    admin/, partials/, macros/ (forms.twig, table_controls.twig), errors/

public/
  index.php        # front controller — todas as rotas passam por aqui
  .htaccess
  build/            # gerado pelo `npm run build` (Vite) — não editar

resources/
  css/app.css       # fonte do Tailwind v4 (@import "tailwindcss"; + @utility/@layer)
  js/app.js          # Alpine.js + Axios (toaster, modal de confirmação, `ajaxForm`)

mysql/init/          # só o bootstrap de privilégios do appuser (o schema em si vem das migrations)
```

### MVC altamente tipado

- `declare(strict_types=1)` em **todo** arquivo PHP da aplicação.
- `App\Support\Role`: enum tipado (`Aluno`/`Professor`/`Admin`) no lugar de strings soltas.
  PHP não deixa enum implementar `__toString()`, então exibir usa `->label()`/`->value`.
- Nenhum Model devolve array cru do PDO: `User`/`SchemaRecord` sempre devolvem DTOs
  `readonly` tipados (`App\Models\Entities\*`), construídos via `fromRow()`.
- A sessão guarda um `App\Support\AuthenticatedUser` `readonly` de verdade (não array) —
  de propósito sem `password_hash`, pra esse hash nem chegar a ficar no arquivo de sessão.
  A sessão também cacheia, separadamente, a senha MySQL em texto puro (pro console SQL abrir
  conexão como a própria pessoa) — mas sempre criptografada com `App\Support\Crypto`, nunca
  em claro (ver seção "Console SQL").
- Mensagens flash são `FlashType` (enum) + `FlashMessage` (DTO), não strings soltas tipo `'success'`.
- `App\Core\Router` roteia com `array{0: class-string<Controller>, 1: string}` tipado por PHPDoc.

### Mecanismo de renderização (sem PHP misturado com HTML)

`App\Core\View` encapsula o Twig: os controllers só chamam `$this->render('pasta/view', [...])`
e passam dados (as Entities tipadas acima); o template (`.twig`) só usa a sintaxe do Twig
(`{{ }}`, `{% %}`) — nunca `<?php ?>`. Herança de layout é feita com `{% extends %}`/
`{% block %}` dentro do próprio template. Funções expostas: `auth_user()`, `is_admin()`,
`is_professor()`, `can_manage_students()`, `flash()`, `vite()` (tags de asset, resolvidas
via manifest do Vite em produção ou via `VITE_DEV_SERVER_URL` em desenvolvimento). Cache de
templates compilados com `auto_reload` sempre ligado, pra nunca servir uma versão antiga.

### Ações via Alpine.js + Axios

Botões de excluir/editar/trocar-papel/ativar-desativar usam um componente Alpine genérico
(`ajaxForm`, em `resources/js/app.js`) que envia o `<form>` via Axios com o header
`X-Requested-With`. O controller (`Controller::respond()`) detecta esse header e responde
com **JSON** (a linha some da tela sem recarregar a página); sem esse header — ou se o JS
não carregar — o mesmo endpoint responde do jeito clássico (redirect + flash), então tudo
funciona sem JavaScript também (progressive enhancement).

### Roteamento

`App\Core\Router` é um router simples próprio, sem framework, com suporte a segmentos
dinâmicos (`/professor/alunos/{id}/editar`) via regex.

### Toaster, modal de confirmação, navbar e responsividade

- **Toaster**: `Alpine.store('toasts')` (em `resources/js/app.js`), desenhado uma vez em
  `partials/toaster.twig` (incluído nos dois layouts). Flash do servidor (`partials/flash.twig`)
  vira um toast automaticamente no load da página via `x-init`; respostas Ajax (sucesso ou
  erro) também empurram pra lá — nunca mais um `alert()` nativo.
- **Modal de confirmação**: `Alpine.store('confirmModal')` + `window.confirmAction({title,
  message, confirmLabel, danger})` (retorna uma Promise), desenhado uma vez em
  `partials/confirm-modal.twig`. Toda ação destrutiva ou de impacto sobre outra pessoa —
  excluir schema/aluno/usuário, resetar senha, trocar papel, ativar/desativar, restaurar,
  renomear o username — passa por ele antes de enviar o form (via `ajaxForm`). Saves
  triviais do próprio usuário (nome, senha) não pedem confirmação extra.
- **Navbar**: o dropdown de conta mostra só primeiro+último nome
  (`AuthenticatedUser::shortName()`, testado) — evita quebra de linha no botão. Links de
  Administração/Usuários/Schemas ficam agrupados num dropdown "Admin" (`partials/nav-links.twig`,
  reaproveitado entre desktop e o menu hamburguer mobile, que mostra os mesmos links em
  lista plana em vez de dropdown aninhado).
- **Responsivo**: grids viram coluna única, tabelas ganham scroll horizontal
  (`overflow-x-auto`), linhas de listas empilham (`flex-col sm:flex-row`) abaixo do
  breakpoint `sm`/`md` do Tailwind, em todas as páginas do painel.

### Conectar via SGBD local (`/conectar`)

Manual de auto-ajuda pra quem prefere um cliente de banco na própria máquina (TablePlus,
DBeaver, MySQL Workbench, DataGrip/PhpStorm, HeidiSQL...) em vez do phpMyAdmin: destaque pro
link público do phpMyAdmin quando `PMA_URL` está configurada, comando `mysql -h ... -P ...`
pronto pra copiar, comando de túnel SSH (`ssh -L 3306:127.0.0.1:<porta> usuario@host -N`)
pra quando o ambiente estiver num servidor remoto, passo a passo por ferramenta (os campos
são sempre os mesmos depois do túnel aberto: `127.0.0.1:3306`) e uma seção de erros comuns.
Host/porta exibidos vêm de `DB_PUBLIC_HOST`/`MYSQL_EXTERNAL_PORT` (ver `.env.example`).

### Console SQL (`POST /dashboard/sql`)

Textarea no dashboard do aluno/professor pra rodar comandos SQL direto no navegador, sem
precisar de nenhum SGBD. Pontos de design:

- **Conecta como a própria pessoa**, não com a conexão admin da app (`Database::connectAs()`,
  nova conexão PDO por request, não é a singleton usada pelo resto da app) — assim os
  `GRANT`s que o MySQL já aplica por schema (`SchemaProvisioner::createDatabase()`) barram
  sozinhos qualquer tentativa de acessar schema de outra pessoa, sem precisar reimplementar
  esse controle aqui. Testado manualmente: tentar `USE` num schema de outra conta devolve
  `1044 Access denied` direto do MySQL.
- **Senha em cache na sessão, criptografada**: como a app só guarda `password_hash` (não dá
  pra abrir uma conexão MySQL nova com um hash), a senha em texto puro é cacheada na sessão
  no momento do login — mas nunca em texto puro: `App\Support\Crypto` (libsodium,
  `sodium_crypto_secretbox`, chave em `APP_KEY`) criptografa antes de guardar. Sessões
  antigas (de antes dessa feature) não têm o valor cacheado — o console pede pra logar de
  novo nesse caso, em vez de quebrar.
- **Múltiplos comandos**: separados por `;`, rodam em sequência numa conexão só, um por vez
  (`App\Support\SqlScriptSplitter` — respeita `;` dentro de strings/identificadores/
  comentários, sem ser um parser SQL completo) — para no primeiro erro e relata qual comando
  falhou.
- Resultado por comando: linhas + colunas (SELECT, cortado em 300 linhas) ou "N linha(s)
  afetada(s)" (INSERT/UPDATE/DELETE/DDL).

## Segurança

Veja **[SECURITY.md](SECURITY.md)** para a avaliação completa de SQL injection: onde estão
os pontos de risco real (comandos DDL do MySQL, que não aceitam identificador como bind
parameter), como cada um é validado antes de ser interpolado, e o que ainda não está
coberto (CSRF, rate limiting).

## Subindo o ambiente

```bash
cp .env.example .env   # ajuste as senhas e gere um APP_KEY antes de usar em qualquer lugar não-local
docker compose up -d --build
```

O `Dockerfile` faz tudo dentro do build — não precisa rodar `composer install`/`npm install`
na sua máquina: um stage instala as dependências PHP (sem as de dev — Pest/PHP-CS-Fixer/
Faker ficam só local), outro roda `npm run build` (Tailwind v4 + Alpine + Axios via Vite), e
a imagem final é `php:8.5-fpm` + `nginx` + `supervisord` (gerenciando os dois processos) +
os artefatos prontos. No boot do container, o `docker/app-entrypoint.sh` roda as migrations
pendentes antes de subir o supervisord. **Essa é a mesma imagem usada em produção** — dev e
prod só diferem em `.env`/rede/exposição de porta, nunca no software rodando dentro.

Serviços (portas padrão, configuráveis no `.env`):

| Serviço     | URL                          |
|-------------|-------------------------------|
| Aplicação   | http://localhost:8080         |
| phpMyAdmin  | http://localhost:8081         |
| MySQL       | localhost:3307 (root: ver `.env`) |

Depois de se cadastrar como o e-mail que você quer que seja admin, defina `ADMIN_EMAIL` no
`.env` e rode `docker compose exec app php bin/console.php db:seed` (ou `composer db:seed`
localmente) pra promover essa conta.

## Produção (Contabo)

Segue o mesmo padrão dos outros projetos no servidor (`/apps/<projeto>/`, Nginx Proxy
Manager como proxy reverso já existente, banco só em `127.0.0.1` sem exposição pública).

- **URL**: `https://dblab.217.76.60.113.sslip.io` (domínio `sslip.io` — resolve sozinho pro
  IP do servidor, sem precisar configurar DNS; é o mesmo padrão usado por `bookid-api`,
  `fintrack-admin` etc. nesse Contabo). phpMyAdmin público em
  `https://pma.dblab.217.76.60.113.sslip.io` (mesmo domínio sslip.io, subdomínio próprio).
- **MySQL nunca é público** — só em `127.0.0.1` no servidor, acesso de fora só via túnel SSH
  (a própria página `/conectar` da app ensina isso, inclusive pra quem preferir acessar o
  phpMyAdmin por SGBD local em vez do link público).
- **Deploy**: `git push` numa PR de `dev` → `main`; depois que o CI passar, o workflow
  **Deploy** roda `deploy.sh` no servidor via SSH (`git pull` + `docker compose -f
  docker-compose.prod.yml up -d --build`, migrations automáticas no entrypoint). A rede
  `proxy` (Nginx Proxy Manager) é declarada como `external: true` no compose — `app` e
  `phpmyadmin` entram nela sozinhos no `up`, sem `docker network connect` manual.
- **Chaves de deploy** (geradas dedicadas pra esse projeto, nenhuma reaproveitada):
  - Contabo → GitHub: deploy key só leitura, registrada no repo, guardada em
    `~/.ssh/id_ed25519_db-lab-estudantes` no servidor (com um alias `github.com-db-lab-estudantes`
    no `~/.ssh/config` do servidor, no mesmo padrão que este projeto já usa localmente).
  - GitHub Actions → Contabo: chave restrita via `authorized_keys` com
    `command="/apps/db-lab-estudantes/deploy.sh"` — mesmo que vaze, só executa esse script,
    nada mais. Guardada nos secrets do repo (`DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`).
- **`.env` de produção** fica só no servidor (`/apps/db-lab-estudantes/.env`, nunca no git)
  — veja `.env.production.example` pro formato (inclui `PMA_URL` e `APP_KEY`).
- **phpMyAdmin público**: `https://pma.dblab.217.76.60.113.sslip.io` (proxy host próprio no
  NPM, Let's Encrypt) — login com o mesmo usuário/senha MySQL de cada pessoa. A UI do NPM em
  si (pra mexer nos proxy hosts) continua só via túnel SSH: `ssh -L 8181:127.0.0.1:81 contaboo`,
  depois `http://localhost:8181` — não é algo que a automação de deploy cobre, é feito uma vez
  na mão quando um novo proxy host precisa ser criado.

## Testes (Pest)

```bash
composer install       # inclui as dependências de dev (Pest, PHP-CS-Fixer, Faker)
composer test           # ou: ./vendor/bin/pest
```

Cobrem a lógica pura em `App\Support` (sem tocar banco): geração e validação de username
(inclusive contra injeção), validação de cadastro, validação/montagem de nomes de schema, o
enum `Role`, `AuthenticatedUser::shortName()`, e as regras de autorização por papel.

## Padrão de código

```bash
composer cs             # verifica (dry-run) — PHP, PSR-12 via PHP-CS-Fixer
composer cs:fix          # aplica as correções
npm run format:check      # verifica — JS/CSS via Prettier
npm run format             # aplica
```

`.editorconfig` cobre o resto (indentação, fim de linha, charset) pra qualquer editor.

## Desenvolvendo localmente sem Docker (opcional)

```bash
composer install
npm install
npm run dev              # servidor do Vite com hot-reload, ou `npm run build` para gerar estático
php -S localhost:8000 -t public   # em outro terminal
```
Crie um `.env` na raiz (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` apontando para
o MySQL do docker-compose — ex. `DB_HOST=127.0.0.1`, `DB_PORT=3307`) — é lido automaticamente
via `vlucas/phpdotenv`. Rode `php bin/console.php migrate` na primeira vez. Para usar o Vite
em modo dev (hot-reload) em vez do build estático, defina `VITE_DEV_SERVER_URL=http://localhost:5173`.

## Avisos importantes (leia antes de usar fora do seu computador)

- O usuário `appuser` recebe `ALL PRIVILEGES ON *.* WITH GRANT OPTION` (praticamente root)
  para poder criar databases e contas dinamicamente. Aceitável **apenas** para um ambiente
  de estudos isolado, rodando localmente. **Não exponha essas portas na internet como está.**
  Detalhes de como isso é mitigado: [SECURITY.md](SECURITY.md).
- Trocar o valor de `MYSQL_USER` no `.env` exige atualizar também o nome fixo usado em
  `mysql/init/01-grants.sql`.
- Nenhuma operação de `CREATE`/`DROP DATABASE`/`CREATE`/`ALTER`/`RENAME`/`DROP USER` roda
  dentro de uma transação PDO — são comandos DDL e o MySQL faz commit implícito neles, o que
  quebraria `beginTransaction()`/`commit()`. Veja o aviso em `App\Services\SchemaProvisioner`.
- Renomear o username **não** renomeia os databases já criados (MySQL não tem um "RENAME
  DATABASE" seguro) — só o identificador de login. Os acessos continuam funcionando porque
  `RENAME USER` preserva os `GRANT`s.
- Soft delete apaga só o *acesso* (bloqueia login na app e no MySQL) — os databases da
  pessoa continuam ocupando espaço até alguém excluir os schemas dela manualmente ou (fora
  do escopo atual) implementar uma exclusão definitiva a partir da lixeira.
- Sessão guarda o papel/status do usuário no momento do login: se um admin muda o papel ou
  desativa alguém que já está logado em outra aba/sessão, essa sessão só sente a mudança na
  próxima ação que precisar reconsultar o banco (ex. próximo login).
- Não há CSRF token nos formulários — bom próximo passo antes de um uso mais sério.
- Os limites de CPU/memória por serviço no `docker-compose.yml` seguem a convenção já usada
  neste computador para evitar sobrecarga da máquina; ajuste conforme necessário.
