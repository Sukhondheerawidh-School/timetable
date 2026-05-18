FROM php:8.2-apache
RUN docker-php-ext-install pdo pdo_mysql mysqli
RUN a2enmod rewrite
COPY . /var/www/html/
COPY .htaccess /var/www/html/.htaccess
RUN chown -R www-data:www-data /var/www/html

# เปิด AllowOverride สำหรับ .htaccess
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf
