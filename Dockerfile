FROM php:8.4-apache
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/* \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf
WORKDIR /var/www/html
COPY . .
RUN find . -type d -exec chmod 755 {} + \
    && find . -type f -exec chmod 644 {} + \
    && chown -R www-data:www-data storage && chmod 700 storage
ENV APP_STORAGE_DIR=/var/www/html/storage
EXPOSE 80
