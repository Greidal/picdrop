FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    && docker-php-ext-install zip mysqli pdo pdo_mysql

RUN a2enmod rewrite