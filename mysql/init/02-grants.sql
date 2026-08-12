-- O usuário definido em MYSQL_USER (variável de ambiente, padrão "appuser") já é criado
-- automaticamente pela imagem oficial do MySQL, com privilégios completos sobre o schema
-- "schoolapp". Aqui damos a ele privilégios adicionais, necessários para que a aplicação
-- PHP possa criar databases dinamicamente para cada aluno/professor e criar/conceder
-- acesso a usuários MySQL individuais (usados depois para login no phpMyAdmin).
--
-- IMPORTANTE: se você mudar o valor de MYSQL_USER no .env, atualize também o nome
-- de usuário abaixo. Este é um ambiente de estudos isolado (não exponha a internet) —
-- por isso o usuário da aplicação recebe privilégios amplos (WITH GRANT OPTION) em vez
-- de um esquema de permissões mínimo, o que simplifica bastante a criação dinâmica de
-- schemas e contas por aluno/professor.
GRANT ALL PRIVILEGES ON *.* TO 'appuser'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
