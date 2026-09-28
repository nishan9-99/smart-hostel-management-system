FROM php:8.2-apache

# PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
 && docker-php-ext-install mysqli pdo pdo_mysql curl \
 && rm -rf /var/lib/apt/lists/*

# Allow .htaccess (upload hardening) and disable directory listing
RUN a2enmod headers \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && echo 'Options -Indexes' >> /etc/apache2/apache2.conf

RUN printf 'display_errors=Off\nlog_errors=On\n' > /usr/local/etc/php/conf.d/99-smarthostel.ini
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
 && chmod 750 /var/www/html/uploads/payment_proofs || true

EXPOSE 80
