# DB Lab Estudantes

Ambiente de estudos em Docker com **MySQL 8**, **phpMyAdmin** e uma aplicação **PHP 8.5 em
MVC altamente tipado** (Composer, sem framework), templates em **Twig** (zero PHP misturado
com HTML), frontend com **Tailwind CSS v4 + Alpine.js + Axios** via **Vite**, e testes
unitários com **Pest**.

Alunos, professores e um super admin se cadastram/logam e podem criar e gerenciar os
próprios schemas (databases) no MySQL — cada um com uma conta MySQL real, utilizável
também no phpMyAdmin, com login renomeável.

## Papéis

| Papel | Pode |
|---|---|
| **Aluno** | Cadastrar-se, logar, criar/excluir os próprios schemas, editar o próprio perfil (nome/senha/login do phpMyAdmin). |
| **Professor** | Tudo do aluno, **+** ver, editar, resetar senha e excluir contas de aluno (`/professor/alunos`). |
| **Admin** (super admin) | Tudo do professor, **+** gerenciar qualquer usuário (aluno/professor/admin), promover/rebaixar papéis, e ver/excluir **qualquer** schema do sistema (`/admin`). O e-mail `huriellopes1996@gmail.com` já vem promovido. |

## Como funciona

- No cadastro, além do registro na tabela `users`, é criada **uma conta MySQL real** para
  a pessoa (`u<id>_<usuario>`), com a mesma senha usada na plataforma.
- No painel (`/dashboard`), a pessoa cria schemas: a aplicação executa `CREATE DATABASE` e
  concede `GRANT ALL PRIVILEGES` **apenas** naquele schema para a conta MySQL da pessoa.
- Com essa conta, a pessoa entra no **phpMyAdmin** e enxerga **somente os schemas que ela
  mesma criou**. Em "Meu perfil" dá pra trocar o nome, a senha (atualiza app + MySQL juntos)
  e o **login do phpMyAdmin** (`RENAME USER` real — preserva os acessos aos schemas já
  criados, só o identificador de login muda).
- Exclusão de conta (pelo professor/admin) remove em cascata: todos os databases da pessoa,
  a conta MySQL dela, e o registro em `users`.

## Arquitetura

```
composer.json            # autoload PSR-4 (App\ -> app/), twig/twig, vlucas/phpdotenv
package.json              # Vite, Tailwind v4, Alpine.js, Axios, Prettier
vite.config.js
Dockerfile                # multi-stage: composer -> npm/vite -> php:8.5-apache
docker-compose.yml
phpunit.xml, tests/        # Pest
.php-cs-fixer.php          # padrão de código PHP (PSR-12 + regras extra)
.prettierrc.json           # padrão de código JS/CSS
.editorconfig
SECURITY.md                 # avaliação de SQL injection e como é mitigada

app/
  Controllers/            # Auth, Dashboard, Schema, Profile, Student (professor), Admin
  Core/                    # Router (com {id} dinâmico), Controller, View (Twig), Vite,
                            Database (PDO), Auth (sessão + papéis), Flash, Config
  Support/                 # Lógica pura, sem I/O — o que os testes do Pest cobrem:
                            Role (enum), AuthenticatedUser, AdminStats, FlashType/FlashMessage,
                            MysqlIdentifier, RegistrationValidator, SchemaNameBuilder, Policy
  Models/
    Entities/                # DTOs readonly tipados: User, Schema, SchemaWithOwner, StudentSummary
    User.php, SchemaRecord.php   # acesso às tabelas da própria app, devolvem as Entities
  Services/                # SchemaProvisioner (DDL no MySQL), UserManager (exclusão/reset/
                            rename completos, coordenando app + MySQL)
  Views/                   # .twig — SEM PHP misturado, só a sintaxe do Twig
    layouts/ (app, guest), auth/, dashboard/, profile/, professor/students/, admin/,
    partials/, errors/

public/
  index.php                # front controller — todas as rotas passam por aqui
  .htaccess
  build/                    # gerado pelo `npm run build` (Vite) — não editar

resources/
  css/app.css               # fonte do Tailwind v4 (@import "tailwindcss"; + @utility/@layer)
  js/app.js                 # Alpine.js + Axios (componente `ajaxForm` reutilizável)

mysql/init/                 # 01-schema.sql, 02-grants.sql, 03-admin-role.sql
```

### MVC altamente tipado

- `declare(strict_types=1)` em **todo** arquivo PHP da aplicação.
- `App\Support\Role`: enum tipado (`Aluno`/`Professor`/`Admin`) no lugar de strings soltas.
  PHP não deixa enum implementar `__toString()`, então exibir usa `->label()`/`->value`.
- Nenhum Model devolve array cru do PDO: `User`/`SchemaRecord` sempre devolvem DTOs
  `readonly` tipados (`App\Models\Entities\*`), construídos via `fromRow()`.
- A sessão guarda um `App\Support\AuthenticatedUser` `readonly` de verdade (não array) —
  de propósito sem `password_hash`, pra esse hash nem chegar a ficar no arquivo de sessão.
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

Botões de excluir/editar/trocar-papel usam um componente Alpine genérico (`ajaxForm`, em
`resources/js/app.js`) que envia o `<form>` via Axios com o header `X-Requested-With`. O
controller (`Controller::respond()`) detecta esse header e responde com **JSON** (a linha
some da tela sem recarregar a página); sem esse header — ou se o JS não carregar — o mesmo
endpoint responde do jeito clássico (redirect + flash), então tudo funciona sem JavaScript
também (progressive enhancement).

### Roteamento

`App\Core\Router` é um router simples próprio, sem framework, com suporte a segmentos
dinâmicos (`/professor/alunos/{id}/editar`) via regex.

## Segurança

Veja **[SECURITY.md](SECURITY.md)** para a avaliação completa de SQL injection: onde estão
os pontos de risco real (comandos DDL do MySQL, que não aceitam identificador como bind
parameter), como cada um é validado antes de ser interpolado, e o que ainda não está
coberto (CSRF, rate limiting).

## Subindo o ambiente

```bash
cp .env.example .env   # ajuste as senhas antes de usar em qualquer lugar não-local
docker compose up -d --build
```

O `Dockerfile` faz tudo dentro do build — não precisa rodar `composer install`/`npm install`
na sua máquina: um stage instala as dependências PHP (sem as de dev — Pest/PHP-CS-Fixer
ficam só local), outro roda `npm run build` (Tailwind v4 + Alpine + Axios via Vite), e a
imagem final é só `php:8.5-apache` + os artefatos prontos.

Serviços (portas padrão, configuráveis no `.env`):

| Serviço     | URL                          |
|-------------|-------------------------------|
| Aplicação   | http://localhost:8080         |
| phpMyAdmin  | http://localhost:8081         |
| MySQL       | localhost:3307 (root: ver `.env`) |

Na primeira subida, os scripts em `mysql/init/` criam o schema `schoolapp` (tabelas `users`
e `schemas_criados`, já com o papel `admin`) e concedem ao usuário `appuser` os privilégios
extras para criar databases e contas MySQL dinamicamente.

## Testes (Pest)

```bash
composer install       # inclui as dependências de dev (Pest, PHP-CS-Fixer)
composer test           # ou: ./vendor/bin/pest
```

Cobrem a lógica pura em `App\Support` (sem tocar banco): geração e validação do login MySQL
(inclusive contra injeção), validação de cadastro, validação/montagem de nomes de schema, o
enum `Role`, e as regras de autorização por papel.

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
via `vlucas/phpdotenv`. Para usar o Vite em modo dev (hot-reload) em vez do build estático,
defina `VITE_DEV_SERVER_URL=http://localhost:5173` no `.env`.

## Avisos importantes (leia antes de usar fora do seu computador)

- O usuário `appuser` recebe `ALL PRIVILEGES ON *.* WITH GRANT OPTION` (praticamente root)
  para poder criar databases e contas dinamicamente. Aceitável **apenas** para um ambiente
  de estudos isolado, rodando localmente. **Não exponha essas portas na internet como está.**
  Detalhes de como isso é mitigado: [SECURITY.md](SECURITY.md).
- Trocar o valor de `MYSQL_USER` no `.env` exige atualizar também o nome fixo usado em
  `mysql/init/02-grants.sql`.
- Nenhuma operação de `CREATE`/`DROP DATABASE`/`CREATE`/`ALTER`/`RENAME`/`DROP USER` roda
  dentro de uma transação PDO — são comandos DDL e o MySQL faz commit implícito neles, o que
  quebraria `beginTransaction()`/`commit()`. Veja o aviso em `App\Services\SchemaProvisioner`.
- Renomear o login do phpMyAdmin **não** renomeia os databases já criados (MySQL não tem um
  "RENAME DATABASE" seguro) — só o identificador de login. Os acessos continuam funcionando
  porque `RENAME USER` preserva os `GRANT`s.
- Sessão guarda o papel do usuário no momento do login: se um admin troca o papel de alguém
  que já está logado em outra aba/sessão, essa sessão só reflete a mudança no próximo login.
- Não há CSRF token nos formulários — bom próximo passo antes de um uso mais sério.
- Os limites de CPU/memória por serviço no `docker-compose.yml` seguem a convenção já usada
  neste computador para evitar sobrecarga da máquina; ajuste conforme necessário.
