FROM php:8.3-apache

RUN a2enmod rewrite

COPY redirect.php /var/www/html/index.php

EXPOSE 80
