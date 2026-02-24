FROM php:8.4-apache

RUN a2enmod rewrite

# PHP extensions needed by Symfony
RUN apt-get update && apt-get install -y libzip-dev unzip && \
    docker-php-ext-install zip opcache && \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first for layer caching
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy application code
COPY . .
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-interaction

# Apache: DocumentRoot to public/
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf
RUN printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf

# Writable dirs
RUN mkdir -p var/cache var/log var/share && chown -R www-data:www-data var/

ENV APP_ENV=prod
ENV APP_DEBUG=0

EXPOSE 80
