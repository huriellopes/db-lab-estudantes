#!/usr/bin/env bash
# Zera a conta de aluno de dev (aluno@dblab.local) antes de gravar: apaga os schemas dela
# (database + registro), as consultas salvas e os diagramas. Só roda contra o Docker local.
set -euo pipefail
APP=${APP_CONTAINER:-dblab_app}
MYSQL=${MYSQL_CONTAINER:-dblab_mysql}
[ "$(docker exec "$APP" printenv APP_ENV)" = local ] || { echo "APP_ENV não é local: abortando." >&2; exit 1; }
DB=$(docker exec "$APP" printenv DB_NAME)
sql() { docker exec -i "$MYSQL" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N 2>/dev/null' ; }

UID_ALUNO=$(echo "SELECT id FROM \`$DB\`.users WHERE email = 'aluno@dblab.local';" | sql)
[ -n "$UID_ALUNO" ] || { echo "aluno@dblab.local não existe (rode o DevUsersSeeder)." >&2; exit 1; }
for s in $(echo "SELECT db_name FROM \`$DB\`.schemas_criados WHERE user_id = $UID_ALUNO;" | sql); do
  echo "DROP DATABASE IF EXISTS \`$s\`;" | sql
done
sql <<SQL
DELETE FROM \`$DB\`.schemas_criados WHERE user_id = $UID_ALUNO;
DELETE FROM \`$DB\`.saved_queries WHERE user_id = $UID_ALUNO;
DELETE FROM \`$DB\`.er_diagrams WHERE user_id = $UID_ALUNO;
SQL
echo "aluno de dev zerado"
