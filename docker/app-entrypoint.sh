#!/bin/sh
set -e

# Padrões do tuning do PHP-FPM/OPcache (docker/php-fpm-pool.conf, docker/php-performance.ini),
# caso a imagem rode sem os valores do docker-compose (ex.: `docker run` direto). O FPM não
# sobe com ${VAR} vazio no pool, então o padrão aqui é obrigatório.
export FPM_MAX_CHILDREN="${FPM_MAX_CHILDREN:-10}"
export FPM_START_SERVERS="${FPM_START_SERVERS:-2}"
export FPM_MIN_SPARE_SERVERS="${FPM_MIN_SPARE_SERVERS:-2}"
export FPM_MAX_SPARE_SERVERS="${FPM_MAX_SPARE_SERVERS:-4}"
export OPCACHE_VALIDATE_TIMESTAMPS="${OPCACHE_VALIDATE_TIMESTAMPS:-1}"

echo "Rodando migrations..."

attempt=0
until php bin/console.php migrate; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "Não foi possível migrar o banco depois de várias tentativas." >&2
        exit 1
    fi
    echo "Banco ainda não disponível, tentando de novo em 2s... (tentativa $attempt)"
    sleep 2
done

echo "Migrations em dia."

# Só em ambiente local: cria admin/professor/aluno de dev (senha password123) se ainda não
# existir — ver database/seeders/DevUsersSeeder.php. Falhar aqui não impede a app de subir.
case "$APP_ENV" in
    local|development|dev)
        echo "APP_ENV=$APP_ENV: rodando seeders..."
        php bin/console.php db:seed || echo "Seeders falharam — a app sobe mesmo assim." >&2
        ;;
esac

exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
