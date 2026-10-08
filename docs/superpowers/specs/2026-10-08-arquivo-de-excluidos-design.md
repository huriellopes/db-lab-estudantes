# Arquivo de dados excluídos (etapa 1 de 3)

Data: 2026-10-08 · Status: proposta para revisão

## Contexto

Pedido: "nenhum dado da plataforma pode ser excluído, deve ir para uma tabela estilo o deleted
models do spatie, para auditoria; uma tela para o admin avaliar se volta o dado ou exclui
definitivamente, tudo auditado".

É a primeira de três etapas, cada uma com spec, plano e implementação próprios:

1. **Arquivo de dados excluídos** (esta spec) — base que as outras usam para excluir.
2. Instituições (só admin) com vínculo de professores e alunos.
3. Turmas (professor/admin) dentro da instituição, com vínculo de alunos.

Hoje:
- Usuários usam soft delete (`users.deleted_at`, conta MySQL bloqueada, lixeira em
  `/admin/usuarios/lixeira`, `UserManager::softDelete/restore`).
- Schemas são apagados de verdade: linha em `schemas_criados` + `DROP DATABASE`
  (`SchemaProvisioner::dropDatabase`), por `DestroySchemaAction` (dono) e
  `DestroyAdminSchemaAction` (admin).
- Consultas salvas (`SavedQuery::delete`) e diagramas ER (`ErDiagram::delete`) são `DELETE` direto.
- `schemas_criados`, `saved_queries` e `er_diagrams` têm FK para `users` com `ON DELETE CASCADE`.

## Decisões

| Tema | Decisão |
|---|---|
| Mecanismo | Tabela central `deleted_models` + serviço `Archiver` chamado por todo ponto de exclusão (não triggers: o MySQL não dispara trigger em exclusão por cascata de FK, e o trigger não sabe quem excluiu). |
| Abrangência | Só dados de negócio: usuários, schemas, consultas salvas, diagramas ER (e, nas etapas 2-3, instituições, turmas e vínculos). Ficam como estão: rotação de logs de erro e backups, tokens de login/reset/rate limit, `audit:prune` (manual, via CLI). A auditoria nunca é apagada pelo app. |
| Usuários | Também vão para `deleted_models` (a linha sai de `users`), levando juntos schemas, consultas e diagramas. Conta MySQL fica **bloqueada** (`ACCOUNT LOCK`) até restaurar ou excluir definitivamente. A coluna `users.deleted_at` deixa de existir. |
| Schemas | **Quarentena**: as tabelas vão para um database oculto `_lixeira_<id>` via `RENAME TABLE` (instantâneo, preserva dados, índices e FKs). Fora do prefixo do aluno, então ele não tem acesso. |
| `DROP DATABASE` por fora (console, phpMyAdmin, SGBD) | Só auditado: quando a sincronização (`SchemaRecord::reconcileForUser`) detecta o sumiço, o registro vai para o arquivo marcado como "removido fora da plataforma", sem dados para restaurar (só excluir definitivamente). |
| Quem exclui | Igual a hoje (dono exclui schema/consulta/diagrama; professor exclui aluno; admin exclui qualquer não-admin) — só que agora vai pro arquivo. |
| Quem vê/restaura/exclui definitivamente | Só admin. |

## Modelo de dados

```sql
CREATE TABLE deleted_models (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  batch_id        CHAR(36)     NOT NULL,          -- UUID: o que foi excluído junto
  is_root         TINYINT(1)   NOT NULL,          -- 1 = item principal do lote (o que a pessoa clicou)
  model           VARCHAR(32)  NOT NULL,          -- 'user' | 'schema' | 'saved_query' | 'er_diagram'
  model_id        INT          NOT NULL,          -- id original (restaurado com o mesmo id)
  label           VARCHAR(200) NOT NULL,          -- nome legível para a tela
  `values`        JSON         NOT NULL,          -- linha completa no momento da exclusão
  meta            JSON         NULL,              -- ex.: database de quarentena, objetos programáveis salvos, origem
  deleted_by_id   INT          NULL,
  deleted_by_name VARCHAR(100) NULL,
  deleted_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_batch (batch_id),
  INDEX idx_model (model, model_id),
  INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Sem FK para `users` (o autor pode ser excluído depois; nome fica em `deleted_by_name`).

## Componentes

- **`App\Services\Archiver`** — único ponto de escrita do arquivo.
  - `archive(string $model, int $id, ?string $reason = null): string` — monta o lote (raiz + dependentes),
    grava em `deleted_models`, executa os efeitos colaterais (quarentena, lock da conta) e apaga as
    linhas originais; devolve o `batch_id`.
  - `restore(string $batchId): void` — reinsere na ordem certa (usuário antes dos filhos), traz schemas
    da quarentena, desbloqueia a conta, apaga o lote do arquivo.
  - `purge(string $batchId): void` — `DROP DATABASE` das quarentenas, `DROP USER` da conta MySQL (lote de
    usuário), apaga o lote do arquivo.
- **`App\Services\SchemaQuarantine`** — move um schema para `_lixeira_<id>` e de volta; salva as
  definições de views, triggers, rotinas e eventos (ver "Objetos programáveis").
- **`App\Models\DeletedModel`** — leitura para a tela (lotes paginados, filtros, itens de um lote).
- **`App\Support\ArchiveSnapshot`** — função pura: linha → `values` com campos sensíveis
  (`password_hash`, tokens) trocados por `[omitido]` **só na exibição** (o arquivo guarda o hash, senão
  restaurar um usuário não teria senha).
- **Definição de lote por modelo** (`App\Support\ArchiveGraph`, pura): `user` → seus `schema`,
  `saved_query`, `er_diagram`; `schema`, `saved_query`, `er_diagram` → sem dependentes. Nas etapas 2-3
  entram `institution` e `class`.

### Pontos de exclusão que passam a usar o Archiver

`DestroySchemaAction`, `DestroyAdminSchemaAction`, `DestroySavedQueryAction`,
`DestroyErDiagramAction`, `DestroyStudentAction`, `DestroyAdminUserAction`, e
`SchemaRecord::reconcileForUser` (remoção detectada fora da plataforma). Os métodos
`SchemaRecord::delete`, `SavedQuery::delete`, `ErDiagram::delete`, `User::softDelete/restore` e
`UserManager::softDelete/restore` deixam de ser chamados pelas Actions.

## Fluxos

**Excluir usuário** (transação no banco da app):
1. Snapshot do usuário, dos schemas, das consultas e dos diagramas → `deleted_models` (mesmo `batch_id`).
2. Cada schema vai para a quarentena (`SchemaQuarantine::move`). Falha em qualquer schema → rollback do
   que já foi movido e erro ("não foi possível arquivar"); nada é excluído.
3. `ALTER USER ... ACCOUNT LOCK`, sessões e tokens "lembrar de mim" revogados, tokens de reset apagados.
4. `DELETE FROM users` (as FKs em cascata limpam as linhas filhas, já arquivadas).
5. Auditoria: `archive.created` com `batch_id`, modelo raiz e contagem por tipo.

**Excluir schema / consulta / diagrama**: mesmo fluxo com lote de um item (schema com quarentena).

**Restaurar lote**:
1. Pré-checagem de conflitos, antes de mexer em qualquer coisa: id já ocupado, `email`/`mysql_login`/
   `schema_prefix` em uso, `db_name` já existe, quarentena sumida, dono do item já não existe (ex.:
   consulta cujo usuário foi excluído depois — o restore pede para restaurar o usuário primeiro).
2. Qualquer conflito → erro claro listando o que impede, nada restaurado, auditoria `archive.restore_failed`.
3. Reinsere as linhas com os ids originais, move as tabelas de volta, `GRANT` de novo para o dono,
   desbloqueia a conta (lote de usuário), cria a consulta salva de objetos programáveis (ver abaixo),
   apaga o lote do arquivo. Auditoria `archive.restored`.

**Excluir definitivamente**: confirmação digitando o rótulo; `DROP DATABASE` das quarentenas, `DROP USER`
(lote de usuário), apaga o lote. Auditoria `archive.purged` com o rótulo e a contagem (o registro de
auditoria fica para sempre).

## Objetos programáveis (views, triggers, rotinas, eventos)

`RENAME TABLE` entre databases falha com tabela que tem trigger (erro 1435) e não move views. E
recriá-los com o aluno como `DEFINER` exigiria `SET_USER_ID` para o usuário da app — privilégio que ele
não tem de propósito (`mysql/init/01-grants.sql`). Recriar sem `DEFINER` faria o código do aluno rodar
com os privilégios da app sobre todos os schemas (escalada de privilégio).

Por isso, na quarentena: as definições (`SHOW CREATE VIEW/TRIGGER/PROCEDURE/FUNCTION/EVENT`) são salvas
em `meta` e os objetos removidos antes do `RENAME TABLE`. Na restauração, tabelas e dados voltam na
hora, e as definições viram uma **consulta salva na biblioteca do aluno** ("Restaurar objetos de
`<schema>`"), que ele executa com um clique no console — rodando como ele mesmo. A tela de restaurar
avisa quando isso acontece.

## Reservas de nomes

Enquanto o lote de um usuário está no arquivo, `email`, `mysql_login` e `schema_prefix` dele ficam
reservados: cadastro, criação de conta pelo admin e renomear login recusam esses valores
("pertence a uma conta excluída"). A conta MySQL bloqueada continua existindo, então liberar o login
antes faria o `CREATE USER` de outra pessoa falhar. Liberados ao excluir definitivamente. Mesmo para
`db_name` de schema em quarentena.

## Tela `/admin/excluidos`

- Só admin (`Auth::requireAdmin`). Navegação sem reload (hx-boost + `ajaxForm({ refresh: true })`).
- Lista **por lote**, mais recente primeiro: tipo + rótulo do item raiz, "+N itens relacionados" (por
  tipo), excluído por, quando, espaço em disco das quarentenas, selo "removido fora da plataforma"
  quando for o caso (sem botão restaurar).
- Filtros: tipo, busca (rótulo, e-mail, nome do schema), período. Paginação igual às outras tabelas
  (`TableQuery`/`Paginator`).
- Expandir lote: cada item com seus campos (sensíveis como `[omitido]`).
- Ações por lote: **Restaurar** (modal de confirmação) e **Excluir definitivamente** (modal pedindo para
  digitar o rótulo).
- `/admin/usuarios/lixeira` redireciona para `/admin/excluidos?tipo=user`; o card "Na lixeira" do
  `/admin` conta lotes do arquivo; link no menu Admin.

## Migração

1. Cria `deleted_models`.
2. Para cada usuário com `deleted_at` preenchido: monta o lote (usuário + filhos) e move os schemas para
   a quarentena, exatamente como o `Archiver` faria, com `deleted_by_name = 'migração'`.
3. Remove `users.deleted_at` e os filtros `deleted_at IS NULL` de `App\Models\User` e `AdminMetrics`.
4. `down()`: devolve os lotes de usuário para `users` com `deleted_at = NOW()` e remove a tabela.

## Erros

- Archive e restore são tudo-ou-nada: falha no meio desfaz o que foi feito (inclusive tabelas já movidas)
  e registra `archive.failed` / `archive.restore_failed` com o motivo; o usuário vê mensagem genérica, o
  detalhe vai para o log de erros.
- `DDL` do MySQL não é transacional: o `Archiver` mantém a lista do que já moveu e desfaz manualmente
  em caso de erro.

## Testes

- Unitários (Pest): `ArchiveGraph` (lote por modelo), `ArchiveSnapshot` (mascaramento só na exibição),
  detecção de conflitos de restauração, reservas de nome, permissões.
- Integração contra o MySQL do Docker local (novo grupo de testes, fora do `composer test` padrão
  porque precisa do banco): excluir usuário com schema que tem tabela com dados + trigger + view →
  conferir quarentena, conta bloqueada, nada em `users`; restaurar → dados de volta, conta liberada,
  consulta "Restaurar objetos" criada; excluir definitivamente → quarentena e conta MySQL sumiram,
  auditoria presente nos três passos.
- Navegador: fluxo completo na tela `/admin/excluidos`.

## Fora do escopo desta etapa

Instituições e turmas (etapas 2 e 3); lixeira própria para aluno/professor; expurgo automático por
tempo; arquivo de dados operacionais (logs, backups, tokens).
