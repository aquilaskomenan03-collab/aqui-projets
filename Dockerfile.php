FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/mg-site.conf \
    && a2enconf mg-site
WORKDIR /var/www/html
