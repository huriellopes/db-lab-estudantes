# DB Lab Estudantes

Ambiente de estudos em Docker com **MySQL 8**, **phpMyAdmin** e uma aplicação **PHP em MVC**
(Composer, sem framework), templates em **Twig** (zero PHP misturado com HTML), frontend
com **Tailwind CSS v4 + Alpine.js + Axios** via **Vite**, e testes unitários com **Pest**.

Alunos, professores e um super admin se cadastram/logam e podem criar e gerenciar os
próprios schemas (databases) no MySQL — cada um com uma conta MySQL real, utilizável
também no phpMyAdmin.

## Papéis

| Papel | Pode |
|---|---|
| **Aluno** | Cadastrar-se, logar, criar/excluir os próprios schemas, editar o próprio perfil (nome/senha). |
| **Professor** | Tudo do aluno, **+** ver, editar, resetar senha e excluir contas de aluno (`/professor/alunos`). |
| **Admin** (super admin) | Tudo do professor, **+** gerenciar qualquer usuário (aluno/professor/admin), promover/rebaixar papéis, e ver/excluir **qualquer** schema do sistema (`/admin`). O e-mail `huriellopes1996@gmail.com` já vem promovido. |

## Como funciona

- No cadastro, além do registro na tabela `users`, é criada **uma conta MySQL real** para
  a pessoa (`u<id>_<usuario>`), com a mesma senha usada na plataforma.
- No painel (`/dashboard`), a pessoa cria schemas: a aplicação executa `CREATE DATABASE` e
  concede `GRANT ALL PRIVILEGES` **apenas** naquele schema para a conta MySQL da pessoa.
- Com essa conta, a pessoa entra no **phpMyAdmin** e enxerga **somente os schemas que ela
  mesma criou**. Trocar a senha em "Meu perfil" atualiza a senha nos dois lugares (app + MySQL).
- Exclusão de conta (pelo professor/admin) remove em cascata: todos os databases da pessoa,
  a conta MySQL dela, e o registro em `users`.

## Arquitetura

```
composer.json            # autoload PSR-4 (App\ -> app/), twig/twig, vlucas/phpdotenv
package.json              # Vite, Tailwind v4, Alpine.js, Axios
vite.config.js
Dockerfile                # multi-stage: composer -> npm/vite -> php:8.2-apache
docker-compose.yml
phpunit.xml, tests/        # Pest

app/
  Controllers/            # Auth, Dashboard, Schema, Profile, Student (professor), Admin
  Core/                    # Router (com {id} dinâmico), Controller, View (Twig), Vite,
                            Database (PDO), Auth (sessão + papéis), Flash, Config
  Support/                 # Lógica pura, sem I/O — o que os testes do Pest cobrem:
                            MysqlIdentifier, RegistrationValidator, SchemaNameBuilder, Policy
  Models/                  # User, SchemaRecord (acesso às tabelas da própria app)
  Services/                # SchemaProvisioner (DDL no MySQL), UserManager (exclusão/reset
                            completos, coordenando app + MySQL)
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

### Mecanismo de renderização (sem PHP misturado com HTML)

`App\Core\View` encapsula o Twig: os controllers só chamam `$this->render('pasta/view', [...])`
e passam dados; o template (`.twig`) só usa a sintaxe do Twig (`{{ }}`, `{% %}`) — nunca
`<?php ?>`. Herança de layout é feita com `{% extends %}`/`{% block %}` dentro do próprio
template. Funções expostas aos templates: `auth_user()`, `is_admin()`, `is_professor()`,
`can_manage_students()`, `flash()`, `vite()` (tags de asset, resolvidas via manifest do Vite
em produção ou via `VITE_DEV_SERVER_URL` em desenvolvimento).

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

## Subindo o ambiente

```bash
cp .env.example .env   # ajuste as senhas antes de usar em qualquer lugar não-local
docker compose up -d --build
```

O `Dockerfile` faz tudo dentro do build — não precisa rodar `composer install`/`npm install`
na sua máquina: um stage instala as dependências PHP (sem as de dev — Pest fica só local),
outro roda `npm run build` (Tailwind v4 + Alpine + Axios via Vite), e a imagem final é só
`php:8.2-apache` + os artefatos prontos.

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
composer install       # inclui as dependências de dev (Pest)
composer test           # ou: ./vendor/bin/pest
```

Cobrem a lógica pura em `App\Support` (sem tocar banco): geração do login MySQL, validação
de cadastro, validação/montagem de nomes de schema (inclusive contra injeção via `db_name`),
e as regras de autorização por papel.

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
- Trocar o valor de `MYSQL_USER` no `.env` exige atualizar também o nome fixo usado em
  `mysql/init/02-grants.sql`.
- Nenhuma operação de `CREATE`/`DROP DATABASE`/`CREATE`/`ALTER`/`DROP USER` roda dentro de
  uma transação PDO — são comandos DDL e o MySQL faz commit implícito neles, o que quebraria
  `beginTransaction()`/`commit()`. Veja o aviso em `App\Services\SchemaProvisioner`.
- Sessão guarda o papel do usuário no momento do login: se um admin troca o papel de alguém
  que já está logado em outra aba/sessão, essa sessão só reflete a mudança no próximo login.
- Não há CSRF token nos formulários — bom próximo passo antes de um uso mais sério.
- Os limites de CPU/memória por serviço no `docker-compose.yml` seguem a convenção já usada
  neste computador para evitar sobrecarga da máquina; ajuste conforme necessário.
