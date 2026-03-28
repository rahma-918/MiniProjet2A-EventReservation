# =============================================
# Dockerfile (à la racine du projet)
# =============================================
FROM php:8.2-fpm
 
# Extensions PHP nécessaires
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    && docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    zip \
    opcache \
    && rm -rf /var/lib/apt/lists/*
 
# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
 
WORKDIR /var/www
 
# Copier les fichiers de dépendances d'abord (cache Docker)
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --no-interaction
 
# Copier tout le projet
COPY . .
RUN composer dump-autoload --optimize
 
# Permissions
RUN chown -R www-data:www-data /var/www/var /var/www/public
RUN chmod -R 775 /var/www/var
 
CMD ["php-fpm"]
 
EXPOSE 9000