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
FROM php:8.2-apache
RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite

# DocumentRoot aponta para public/ — app/ (controllers/models/views) fica fora do
# docroot, então não é acessível via navegador; .htaccess precisa de AllowOverride All.
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' \
        /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && sed -ri -e 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY app ./app
COPY public ./public
COPY --from=assets /assets/public/build ./public/build

RUN mkdir -p storage/twig-cache \
    && chown -R www-data:www-data /var/www/html
