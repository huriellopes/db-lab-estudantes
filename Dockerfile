# Versões fixas (não "composer:2", "node:22-alpine", "php:8.5-fpm"): o mesmo commit sempre
# gera a mesma imagem, e um upgrade vira um PR visível (o Dependabot abre sozinho, ver
# .github/dependabot.yml) em vez de entrar calado no próximo build.

# ---- Stage 1: dependências PHP (composer, sem as de dev — Pest fica só local) ----
FROM composer:2.10.3 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts

# ---- Stage 2: build dos assets (Tailwind v4 + Alpine + Axios via Vite) ----
# Node 22 (LTS): o 20 saiu de suporte em abril de 2026.
FROM node:26.10.0-alpine AS assets
WORKDIR /assets
COPY package.json package-lock.json ./
# npm ci, não npm install: instala exatamente o package-lock.json (e falha se ele estiver
# fora de sincronia com o package.json) — o install podia resolver versões novas no build.
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY app/Views ./app/Views
RUN npm run build

# ---- Stage 3: imagem final da aplicação ----
# php-fpm + nginx + supervisord — a MESMA imagem roda em dev (docker-compose.yml) e em
# produção (docker-compose.prod.yml), só muda env vars/limites/rede. Isso é de propósito:
# dev e prod usam exatamente o mesmo Dockerfile pra evitar "funciona na minha máquina".
FROM php:8.5.11-fpm
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor curl \
    && docker-php-ext-install pdo pdo_mysql mysqli \
    && rm -rf /var/lib/apt/lists/*

COPY docker/nginx.conf /etc/nginx/sites-enabled/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php-hardening.ini /usr/local/etc/php/conf.d/zz-hardening.ini

WORKDIR /var/www/html

# app/ (controllers, models, views) fica fora do docroot (public/) de propósito — nginx
# só serve public/, então nada em app/ é acessível direto por URL.
COPY --from=vendor /app/vendor ./vendor
COPY app ./app
COPY bin ./bin
COPY database ./database
COPY composer.json ./
COPY public ./public
COPY --from=assets /assets/public/build ./public/build
COPY docker/app-entrypoint.sh /usr/local/bin/app-entrypoint.sh

# Código fica de root (só leitura pro PHP-FPM, que roda como www-data); só storage/ (cache
# do Twig) é gravável. Antes era chown -R da pasta inteira: uma falha que desse execução de
# código no PHP podia reescrever os próprios arquivos da app e ficar lá de vez.
RUN mkdir -p storage/twig-cache /var/log/supervisor \
    && chmod +x /usr/local/bin/app-entrypoint.sh bin/console.php \
    && chown -R www-data:www-data storage

EXPOSE 80

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
    CMD curl -fsS http://127.0.0.1/login -o /dev/null || exit 1

ENTRYPOINT ["app-entrypoint.sh"]
