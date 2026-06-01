FROM php:8.3-fpm

# Nginx + системные зависимости + envsubst (gettext-base) для подстановки ${S3_BUCKET}
RUN apt-get update && apt-get install -y \
    nginx \
    curl \
    gettext-base \
    libpng-dev libjpeg-dev libwebp-dev \
    libzip-dev libxml2-dev libonig-dev libicu-dev \
    default-mysql-client \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install \
        pdo_mysql mysqli gd mbstring \
        zip xml intl opcache \
    && rm -rf /var/lib/apt/lists/*

# PHP конфиг
COPY php.ini /usr/local/etc/php/conf.d/modx.ini

# nginx-конфиг как ШАБЛОН — entrypoint подставит в него ${S3_BUCKET} и положит в sites-enabled
COPY nginx.conf /etc/nginx/site.conf.template

# Убрать дефолтный index.html nginx
RUN rm -f /var/www/html/index.nginx-debian.html

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html
EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
