#!/bin/sh
set -e

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

exec apache2-foreground
