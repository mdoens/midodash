FROM php:8.3-apache

RUN a2enmod rewrite

COPY config.php redirect.php /var/www/html/

# Redirect URL is root, so make redirect.php the index
RUN mv /var/www/html/redirect.php /var/www/html/index.php

EXPOSE 80
