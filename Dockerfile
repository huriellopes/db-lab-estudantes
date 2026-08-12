# ---- Stage 1: dependências PHP (composer, sem as de dev — Pest fica só local) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts

# ---- Stage 2: build dos assets (Tailwind v4 + Alpine + Axios via Vite) ----
FROM node:20-alpine AS assets
WORKDIR /assets
COPY package.json package-lock.json* ./
RUN npm install
COPY vite.config.js ./
COPY resources ./resources
COPY app/Views ./app/Views
RUN npm run build

# ---- Stage 3: imagem final da aplicação ----
# php-fpm + nginx + supervisord — a MESMA imagem roda em dev (docker-compose.yml) e em
# produção (docker-compose.prod.yml), só muda env vars/limites/rede. Isso é de propósito:
# dev e prod usam exatamente o mesmo Dockerfile pra evitar "funciona na minha máquina".
FROM php:8.5-fpm
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

RUN mkdir -p storage/twig-cache /var/log/supervisor \
    && chmod +x /usr/local/bin/app-entrypoint.sh bin/console.php \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
    CMD curl -fsS http://127.0.0.1/login -o /dev/null || exit 1

ENTRYPOINT ["app-entrypoint.sh"]
