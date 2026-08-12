#!/bin/sh
# Roda NO SERVIDOR (Contabo), disparado pelo workflow de deploy via SSH. Cópia de
# referência aqui no repo — o arquivo que realmente executa fica em
# /apps/db-lab-estudantes/deploy.sh (mesmo conteúdo).
set -e

cd /apps/db-lab-estudantes

echo "== Atualizando código (git) =="
git fetch origin main
git reset --hard origin/main

echo "== Subindo containers (build + migrations automáticas no entrypoint) =="
docker compose -f docker-compose.prod.yml up -d --build --remove-orphans

echo "== Limpando imagens antigas =="
docker image prune -f

echo "== Deploy concluído =="
docker compose -f docker-compose.prod.yml ps
