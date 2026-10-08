#!/usr/bin/env bash
# Diagnóstico SOMENTE LEITURA do servidor de produção — base pra calibrar os limites de
# memória/CPU e o tuning do docker-compose.prod.yml (MySQL, PHP-FPM, OPcache).
#
# Uso, da sua máquina:   ssh <alias-do-servidor> 'bash -s' < docker/prod-diagnose.sh
# Ou no servidor:        bash docker/prod-diagnose.sh
#
# Não altera nada e não imprime segredos: a senha do MySQL é lida de dentro do próprio
# container (variável MYSQL_ROOT_PASSWORD dele), nunca do .env nem da linha de comando local.
set -u

MYSQL_CONTAINER="${MYSQL_CONTAINER:-dblab-mysql}"
APP_CONTAINER="${APP_CONTAINER:-dblab-app}"

section() { printf '\n===== %s =====\n' "$1"; }

DOCKER="docker"
if ! docker info >/dev/null 2>&1; then
    if sudo -n docker info >/dev/null 2>&1; then DOCKER="sudo -n docker"; else
        echo "Sem acesso ao Docker com este usuário (nem via sudo sem senha)." >&2
    fi
fi

section "Host"
uname -srm
grep -m1 'model name' /proc/cpuinfo | sed 's/.*: //'
echo "CPUs: $(nproc)"
uptime
cat /etc/os-release 2>/dev/null | grep -E '^PRETTY_NAME' | cut -d= -f2

section "Memória e swap"
free -m
swapon --show 2>/dev/null || echo "(swapon indisponível)"
echo "vm.swappiness=$(cat /proc/sys/vm/swappiness 2>/dev/null)"
echo "vm.overcommit_memory=$(cat /proc/sys/vm/overcommit_memory 2>/dev/null)"

section "Pressão de recursos (PSI — % do tempo travado esperando recurso)"
for r in cpu memory io; do
    [ -r "/proc/pressure/$r" ] && { echo "[$r]"; cat "/proc/pressure/$r"; }
done

section "Disco"
df -h -x tmpfs -x devtmpfs -x overlay 2>/dev/null || df -h
echo
$DOCKER system df 2>/dev/null

section "Docker"
$DOCKER version --format 'Engine {{.Server.Version}}' 2>/dev/null
$DOCKER info --format 'cgroup {{.CgroupVersion}} ({{.CgroupDriver}}) · storage {{.Driver}} · containers {{.Containers}} ({{.ContainersRunning}} rodando)' 2>/dev/null
docker compose version 2>/dev/null || $DOCKER compose version 2>/dev/null

section "Containers (todos do servidor, não só deste projeto)"
$DOCKER ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}' 2>/dev/null

section "Consumo agora (docker stats)"
$DOCKER stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.PIDs}}' 2>/dev/null

section "Limites, reinícios e OOM por container"
for c in $($DOCKER ps -q 2>/dev/null); do
    $DOCKER inspect --format '{{.Name}}: mem_limit={{.HostConfig.Memory}} cpus(nano)={{.HostConfig.NanoCpus}} restarts={{.RestartCount}} oom_killed={{.State.OOMKilled}} desde={{.State.StartedAt}}' "$c"
done

section "OOM killer no kernel (últimos)"
(journalctl -k --no-pager 2>/dev/null || dmesg 2>/dev/null) | grep -i -E 'out of memory|oom-kill|killed process' | tail -10 || true
echo "(vazio = nenhum OOM registrado/visível para este usuário)"

section "Pasta do projeto"
$DOCKER inspect --format '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$APP_CONTAINER" 2>/dev/null

section "MySQL — configuração"
MYSQL() { $DOCKER exec "$MYSQL_CONTAINER" sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -N -B -e \"$1\"" 2>/dev/null; }
MYSQL "SELECT CONCAT('versão ', VERSION());"
MYSQL "SELECT VARIABLE_NAME, VARIABLE_VALUE FROM performance_schema.global_variables WHERE VARIABLE_NAME IN (
  'innodb_buffer_pool_size','innodb_buffer_pool_instances','innodb_log_buffer_size','innodb_redo_log_capacity',
  'innodb_flush_log_at_trx_commit','innodb_flush_method','innodb_io_capacity','max_connections','max_user_connections',
  'performance_schema','table_open_cache','table_definition_cache','thread_cache_size','tmp_table_size',
  'max_heap_table_size','sort_buffer_size','join_buffer_size','read_buffer_size','key_buffer_size',
  'binlog_expire_logs_seconds','log_bin','skip_name_resolve','wait_timeout') ORDER BY VARIABLE_NAME;"

section "MySQL — uso (desde o último start)"
MYSQL "SELECT VARIABLE_NAME, VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME IN (
  'Uptime','Threads_connected','Threads_running','Max_used_connections','Max_used_connections_time','Connections',
  'Aborted_connects','Questions','Slow_queries','Created_tmp_tables','Created_tmp_disk_tables',
  'Innodb_buffer_pool_read_requests','Innodb_buffer_pool_reads','Innodb_buffer_pool_pages_total',
  'Innodb_buffer_pool_pages_free','Innodb_buffer_pool_pages_data','Opened_tables','Table_open_cache_misses') ORDER BY VARIABLE_NAME;"
echo "-- memória alocada pelo MySQL (sys.memory_global_total):"
MYSQL "SELECT * FROM sys.memory_global_total;"
echo "-- top 8 consumidores de memória:"
MYSQL "SELECT event_name, current_alloc FROM sys.memory_global_by_current_bytes LIMIT 8;"

section "MySQL — tamanho dos dados"
MYSQL "SELECT COUNT(DISTINCT table_schema) AS n_schemas, ROUND(SUM(data_length+index_length)/1024/1024,1) AS total_mb,
  ROUND(SUM(CASE WHEN engine='InnoDB' THEN data_length+index_length END)/1024/1024,1) AS innodb_mb
  FROM information_schema.tables WHERE table_schema NOT IN ('mysql','sys','performance_schema','information_schema');"
echo "-- binlogs:"
MYSQL "SHOW BINARY LOGS;" | awk '{s+=$2; n++} END {printf "%d arquivo(s), %.1f MB\n", n, s/1024/1024}'

section "App — PHP-FPM / OPcache / nginx"
$DOCKER exec "$APP_CONTAINER" sh -c 'php-fpm -tt 2>&1 | grep -E "\bpm(\.|\s)|pm\.max|pm\.start|pm\.min|pm\.max_spare|pm\.max_requests|request_terminate" | sed "s/.*NOTICE: //"' 2>/dev/null
$DOCKER exec "$APP_CONTAINER" php -r 'foreach (["memory_limit","opcache.enable","opcache.memory_consumption","opcache.max_accelerated_files","opcache.validate_timestamps","opcache.jit","opcache.jit_buffer_size","realpath_cache_size"] as $k) echo $k, "=", ini_get($k), PHP_EOL;' 2>/dev/null
echo "-- processos (RSS em KB):"
$DOCKER top "$APP_CONTAINER" -eo rss,args 2>/dev/null | grep -E 'php-fpm|nginx|RSS' | head -20
$DOCKER exec "$APP_CONTAINER" sh -c 'grep -E "worker_processes|worker_connections" /etc/nginx/nginx.conf' 2>/dev/null

section "Fim"
date
