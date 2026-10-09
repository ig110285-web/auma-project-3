#!/bin/bash
# Настройка сайта после загрузки файлов. Запускать на сервере от root.
set -e

APP_DIR=/var/www/auma
DBPASS_FILE=/root/.auma-db-pass

echo "=== 1. пароль базы ==="
if [ ! -f "$DBPASS_FILE" ]; then
  echo "Нет $DBPASS_FILE — сначала запустите install-server.sh"
  exit 1
fi
DBPASS=$(cat "$DBPASS_FILE")

echo "=== 2. config.php ==="
cat > "$APP_DIR/app/config.php" <<PHP
<?php
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'auma',
        'user' => 'auma',
        'pass' => '${DBPASS}',
    ],
    'app' => [
        'name'       => 'AUMA Documentation',
        'upload_dir' => dirname(__DIR__) . '/public/uploads',
        'base_path'  => '',
    ],
    'mail' => [
        'enabled'   => false,
        'host'      => '',
        'port'      => 587,
        'user'      => '',
        'pass'      => '',
        'from'      => 'noreply@localhost',
        'from_name' => 'AUMA Documentation',
    ],
];
PHP
chmod 640 "$APP_DIR/app/config.php"

echo "=== 3. схема базы ==="
mysql auma < "$APP_DIR/sql/schema.sql"
mysql auma -e "SHOW TABLES;"

echo "=== 4. права ==="
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;
chmod 640 "$APP_DIR/app/config.php"
mkdir -p "$APP_DIR/public/uploads"
chown -R www-data:www-data "$APP_DIR/public/uploads"
chmod 775 "$APP_DIR/public/uploads"

echo "=== 5. nginx ==="
cp "$APP_DIR/deploy/nginx.conf" /etc/nginx/sites-available/auma
ln -sf /etc/nginx/sites-available/auma /etc/nginx/sites-enabled/auma
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

echo "=== 6. php-fpm ==="
systemctl restart php8.5-fpm
systemctl is-active nginx php8.5-fpm mysql

echo "=== 7. проверка снаружи ==="
sleep 1
curl -sS -o /dev/null -w "http://127.0.0.1/ -> %{http_code}\n" -L http://127.0.0.1/
curl -sS -o /dev/null -w "http://127.0.0.1/login -> %{http_code}\n" -L http://127.0.0.1/login
curl -sS -o /dev/null -w "http://127.0.0.1/api/me -> %{http_code}\n" http://127.0.0.1/api/me

echo "=== 8. php-модули ==="
php -m | grep -iE "^(curl|pdo_mysql|mbstring|openssl)$"
