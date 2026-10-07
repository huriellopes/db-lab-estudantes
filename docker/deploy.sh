#!/bin/sh
# Roda NO SERVIDOR, disparado pelo workflow de deploy via SSH. Cópia de referência: no
# servidor, o arquivo que executa fica na raiz do projeto (fora do git, pra o próprio deploy
# nunca sobrescrever o script que está rodando) e é o "command=" da chave de deploy no
# authorized_keys.
set -e

# Entra na pasta onde o script está (a raiz do projeto no servidor), sem caminho fixo.
cd "$(dirname "$(readlink -f "$0")")"

echo "== Atualizando código (git) =="
git fetch origin main
git reset --hard origin/main

echo "== Subindo containers (build + migrations automáticas no entrypoint) =="
docker compose -f docker-compose.prod.yml up -d --build --remove-orphans

echo "== Limpando imagens antigas =="
docker image prune -f

echo "== Deploy concluído =="
docker compose -f docker-compose.prod.yml ps
