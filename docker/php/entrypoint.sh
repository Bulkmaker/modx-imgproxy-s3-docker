#!/bin/bash
set -e

# 1. Подставить ${S3_BUCKET} из .env в nginx-конфиг (остальные $var nginx не трогаем)
export S3_BUCKET="${S3_BUCKET:-my-media-bucket}"
rm -f /etc/nginx/sites-enabled/default
envsubst '${S3_BUCKET}' < /etc/nginx/site.conf.template > /etc/nginx/sites-enabled/default

# 2. (Опционально) поправить site_url в БД MODX, когда он уже установлен.
#    На свежей инсталляции таблиц ещё нет — запросы тихо игнорируются.
if [ -n "$SITE_URL" ] && [ -n "$DB_HOST" ] && [ -n "$TABLE_PREFIX" ]; then
    echo "Ожидание базы данных..."
    for i in $(seq 1 30); do
        mysql --skip-ssl -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1" >/dev/null 2>&1 && break
        sleep 2
    done

    ESCAPED_URL=$(printf '%s' "$SITE_URL" | sed "s/'/''/g")
    PROTOCOL="http"; case "$SITE_URL" in https://*) PROTOCOL="https" ;; esac

    mysql --skip-ssl -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
        "INSERT INTO \`${TABLE_PREFIX}system_settings\` (\`key\`, value) VALUES ('site_url', '${ESCAPED_URL}') ON DUPLICATE KEY UPDATE value='${ESCAPED_URL}';" 2>/dev/null || true
    mysql --skip-ssl -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
        "INSERT INTO \`${TABLE_PREFIX}system_settings\` (\`key\`, value) VALUES ('server_protocol', '${PROTOCOL}') ON DUPLICATE KEY UPDATE value='${PROTOCOL}';" 2>/dev/null || true

    rm -rf /var/www/html/core/cache/system_settings/ /var/www/html/core/cache/context_settings/ 2>/dev/null || true
    echo "site_url → $SITE_URL ($PROTOCOL)"
fi

# 3. Права на запись для MODX
mkdir -p /var/www/html/core/cache /var/www/html/core/export
chown -R www-data:www-data /var/www/html 2>/dev/null || true
chmod -R 775 /var/www/html/core/cache /var/www/html/core/export 2>/dev/null || true
find /var/www/html/assets -type d -name cache -exec chmod -R 775 {} + 2>/dev/null || true

# 4. Запуск: php-fpm в фоне, nginx на переднем плане
php-fpm -D
exec nginx -g 'daemon off;'
