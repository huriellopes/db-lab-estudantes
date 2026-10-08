# Bancos dos alunos para o professor

Data: 2026-10-08 · Status: aprovada (usuário escolheu "editar = dados" e autorizou implementar)

## Contexto

Pedido: "uma tela para o professor visualizar as bases de dados, tabelas e todas as colunas, relações
e etc dos alunos, podendo editar para ajudar os alunos, não podendo excluir".

## Decisões

| Tema | Decisão |
|---|---|
| Quem | Professor; só alunos de instituição em comum (`InstitutionScope`). Admin não ganha esta tela (tem phpMyAdmin com root). |
| Ver | Schemas do aluno, tabelas, colunas (tipo, nulo, padrão, chave, extra), chaves primárias e estrangeiras (relações), dados paginados. |
| Editar | **Dados**: inserir linha e alterar linha (pela chave primária). Estrutura só leitura. Tabela sem chave primária: dados só leitura. |
| Não excluir | Garantido pelo **MySQL**: a conta do professor recebe `SELECT, INSERT, UPDATE` no prefixo de cada aluno (`GRANT ... ON \`<prefixo>\_\_%\`.*`), sem `DELETE`, `DROP`, `ALTER`. Vale também no phpMyAdmin e em SGBD. |
| Execução | Leitura e escrita pela **conta MySQL do próprio professor** (`Database::connectAs` com a senha cifrada na sessão); sem a senha em cache, a tela pede para confirmar (`POST /dashboard/sql/confirmar-senha`). |
| Auditoria | `professor.db_row_inserted` / `professor.db_row_updated` com banco, tabela, colunas e chave — sem os valores alterados (dados do aluno). |

## Permissões (`App\Services\ProfessorGrants`)

- Desejado para o professor P: padrão de prefixo de cada aluno que divide instituição com P.
- Atual: linhas de `mysql.db` do usuário de P com `Select/Insert/Update = Y` e `Delete/Drop/Alter = N`
  (o GRANT do próprio prefixo do professor tem `ALL`, então não é confundido).
- Diferença pura (`App\Support\ProfessorGrantDiff`): `GRANT` o que falta, `REVOKE` o que sobra.
- `syncAll()` recalcula para todo professor e aluno (aluno nunca tem grant "de professor": revoga se
  sobrou de uma troca de papel). Chamado depois de: vincular/remover membro, excluir instituição,
  troca de papel, arquivar/restaurar usuário/instituição/vínculo; e no boot (`grants:sync-professors`
  no entrypoint). Falha de sincronização nunca derruba a ação principal (vai pro log de erros).

## Componentes

- `App\Support\RowEditSql` (pura): monta `UPDATE ... SET ... WHERE <pk> LIMIT 1` e `INSERT ... (...) VALUES (...)`
  com identificadores entre crases (crase escapada) e valores como parâmetros; `null` vira `NULL`.
- `App\Services\StudentDatabases`: alunos visíveis com schemas (via app), checagem de acesso a um banco,
  estrutura (`information_schema` pela conexão do professor) e linhas paginadas (25 por página).
- `App\Services\StudentDataEditor`: valida tabela/colunas contra a estrutura real, exige chave primária
  para alterar, executa pela conexão do professor e audita.
- Colunas binárias (`blob`, `binary`, `varbinary`, `bit`, `geometry`) aparecem como `[binário]` e não são editáveis.

## Telas (sem reload)

- `GET /professor/bancos`: alunos por instituição com seus bancos.
- `GET /professor/bancos/{banco}`: tabelas, colunas, chaves e relações ("tabela.coluna → tabela.coluna").
- `GET /professor/bancos/{banco}/{tabela}`: dados paginados, formulário de editar linha e de inserir linha.
- `POST /professor/bancos/{banco}/{tabela}/linhas` (inserir) e `/editar` (alterar).
- Menu "Ensino ▾" → "Bancos dos alunos".

## Testes

- Unitários: `ProfessorGrantDiff`, `RowEditSql` (identificador com crase, NULL, múltiplas colunas de PK).
- Integração (MySQL real): sincronização dá `SELECT/INSERT/UPDATE`; pela conta do professor `INSERT`/`UPDATE`
  funcionam e `DELETE`/`DROP`/`TRUNCATE` dão erro 1142; remover o aluno da instituição revoga; aluno de
  outra instituição recusado; edição auditada.
- Navegador/HTTP: professor vê estrutura e dados, edita e insere.

## Fora do escopo

Editar estrutura (adicionar/renomear coluna, mudar tipo); excluir linhas; tela para admin.
