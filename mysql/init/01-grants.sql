-- O usuário definido em MYSQL_USER (variável de ambiente, padrão "appuser") já é criado
-- automaticamente pela imagem oficial do MySQL, com privilégios completos sobre o schema
-- "schoolapp". Aqui damos a ele só o que App\Services\SchemaProvisioner de fato usa:
-- CREATE/DROP DATABASE dinâmico, CREATE/ALTER/RENAME/DROP USER (login de aluno/professor/
-- admin) e GRANT em schemas recém-criados (por isso precisa de WITH GRANT OPTION nos
-- privilégios de schema, não só nos administrativos).
--
-- IMPORTANTE: se você mudar o valor de MYSQL_USER no .env, atualize também o nome de
-- usuário abaixo.
--
-- De propósito SEM SUPER/FILE/SHUTDOWN/RELOAD/PROCESS/replicação/CREATE ROLE/DROP ROLE/
-- CREATE TABLESPACE e SEM nenhum dos privilégios administrativos dinâmicos do MySQL 8
-- (BACKUP_ADMIN, BINLOG_ADMIN, CONNECTION_ADMIN, ENCRYPTION_KEY_ADMIN,
-- SYSTEM_VARIABLES_ADMIN etc.) — nada disso é usado pela aplicação, e um `GRANT ALL
-- PRIVILEGES ON *.*` feito como root (como esse script roda, via entrypoint oficial da
-- imagem) arrasta esses privilégios dinâmicos junto sem necessidade nenhuma. A credencial
-- do appuser fica em texto puro no .env — reduzir o alcance dela reduz o estrago de um
-- eventual vazamento (ver SECURITY.md, "Acessos excessivos").
GRANT CREATE, DROP, ALTER, CREATE USER,
      SELECT, INSERT, UPDATE, DELETE, REFERENCES, INDEX,
      CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE,
      CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE,
      EVENT, TRIGGER
  ON *.* TO 'appuser'@'%' WITH GRANT OPTION;
