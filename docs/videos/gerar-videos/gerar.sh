#!/usr/bin/env bash
# Gera as vídeo-aulas do guia de ponta a ponta. Ver docs/videos/README.md ("Geração automática").
#
#   docs/videos/gerar-videos/gerar.sh                 # todos
#   docs/videos/gerar-videos/gerar.sh mysql-console-sql sql-ansi-join
#   PUBLICAR=1 docs/videos/gerar-videos/gerar.sh      # + copia pro volume do Docker local
set -euo pipefail
AQUI=$(cd "$(dirname "$0")" && pwd)
VIDEOS=$(dirname "$AQUI")
APP=${APP_CONTAINER:-dblab_app}
cd "$AQUI"
[ -d node_modules/playwright ] || npm ci --no-audit --no-fund
command -v uv >/dev/null || { echo "precisa do uv (https://docs.astral.sh/uv/)" >&2; exit 1; }

if [ $# -eq 0 ]; then
  set -- $(ls cenas | grep -v '^_' | sed 's/\.mjs$//')
fi

uv run -q --with edge-tts==7.3.0 python -I narrar.py "$@"
for v in "$@"; do
  echo "== $v"
  ./preparar.sh
  node gravar.mjs "$v"
  python3 montar.py "$v"
done
python3 "$VIDEOS/gerar-roteiros.py"

if [ "${PUBLICAR:-0}" = 1 ]; then
  for v in "$@"; do
    cp "$VIDEOS/legendas/$v.vtt" "$VIDEOS/saida/$v/"
    for ext in mp4 webp vtt; do
      docker cp "$VIDEOS/saida/$v/$v.$ext" "$APP:/var/www/html/storage/guide-videos/"
    done
  done
  ./preparar.sh
  echo "publicado no volume de dev: $*"
fi
