FROM php:8.4-apache

RUN a2enmod rewrite

# PHP extensions + cron + MySQL client
RUN apt-get update && apt-get install -y libzip-dev unzip cron default-mysql-client && \
    docker-php-ext-install zip opcache pdo pdo_mysql && \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first for layer caching
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy application code
COPY . .
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-interaction

# Install importmap vendor assets (Stimulus, Turbo, Chart.js)
RUN APP_ENV=prod APP_SECRET=build \
    IB_TOKEN=x IB_QUERY_ID=x \
    SAXO_APP_KEY=x SAXO_APP_SECRET=x SAXO_REDIRECT_URI=x \
    SAXO_AUTH_ENDPOINT=x SAXO_TOKEN_ENDPOINT=x SAXO_API_BASE=x \
    DASHBOARD_PASSWORD_HASH=x FRED_API_KEY=x \
    DATABASE_URL="sqlite:///var/www/html/var/data/mido.sqlite" \
    php bin/console importmap:install --env=prod

# Compile assets to public/assets/ (required for prod)
RUN APP_ENV=prod APP_SECRET=build \
    IB_TOKEN=x IB_QUERY_ID=x \
    SAXO_APP_KEY=x SAXO_APP_SECRET=x SAXO_REDIRECT_URI=x \
    SAXO_AUTH_ENDPOINT=x SAXO_TOKEN_ENDPOINT=x SAXO_API_BASE=x \
    DASHBOARD_PASSWORD_HASH=x FRED_API_KEY=x \
    DATABASE_URL="sqlite:///var/www/html/var/data/mido.sqlite" \
    php bin/console asset-map:compile --env=prod

# Warmup Symfony cache
RUN APP_ENV=prod APP_SECRET=build \
    IB_TOKEN=x IB_QUERY_ID=x \
    SAXO_APP_KEY=x SAXO_APP_SECRET=x SAXO_REDIRECT_URI=x \
    SAXO_AUTH_ENDPOINT=x SAXO_TOKEN_ENDPOINT=x SAXO_API_BASE=x \
    DASHBOARD_PASSWORD_HASH=x FRED_API_KEY=x \
    DATABASE_URL="sqlite:///var/www/html/var/data/mido.sqlite" \
    php bin/console cache:warmup --env=prod

# Apache: DocumentRoot to public/
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf
RUN printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf

# Writable dirs
RUN mkdir -p var/cache var/log var/share var/data && chown -R www-data:www-data var/

ENV APP_ENV=prod
ENV APP_DEBUG=0

# Cron jobs
RUN printf '*/15 * * * * cd /var/www/html && php bin/console app:dashboard:warmup >> var/log/cron.log 2>&1\n*/15 * * * * cd /var/www/html && php bin/console app:saxo:refresh >> var/log/cron.log 2>&1\n*/30 * * * * cd /var/www/html && php bin/console app:ib:fetch >> var/log/cron.log 2>&1\n0 * * * * cd /var/www/html && php bin/console app:momentum:warmup >> var/log/cron.log 2>&1\n' > /etc/cron.d/midodash \
    && chmod 0644 /etc/cron.d/midodash \
    && crontab /etc/cron.d/midodash

# Start cron + Apache
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
CMD ["docker-entrypoint.sh"]
