#!/bin/sh
# Expurgo automático de Dados excluídos, uma vez por dia (programa do supervisord, roda como
# www-data). Com ARCHIVE_RETENTION_DAYS vazio/0 o comando não faz nada. A primeira rodada espera
# 10 minutos depois do boot, pra não competir com migrations e com o primeiro acesso.
sleep 600
while true; do
    php /var/www/html/bin/console.php archive:purge-expired --aplicar
    sleep 86400
done
