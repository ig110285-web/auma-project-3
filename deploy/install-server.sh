#!/bin/bash
# Установка окружения для auma-project-3 на Ubuntu 26.04
# Идемпотентен: повторный запуск ничего не ломает.
set -e
export DEBIAN_FRONTEND=noninteractive

echo "=== 1. apt update ==="
apt-get update -qq

echo "=== 2. установка пакетов ==="
apt-get install -y -qq \
  nginx \
  mysql-server \
  php-fpm php-mysql php-curl php-mbstring php-xml php-zip php-gd \
  curl unzip git

echo "=== 3. версии ==="
nginx -v 2>&1
php -v | head -1
mysql --version
git --version

echo "=== 4. сервисы ==="
systemctl enable --now nginx
systemctl enable --now mysql
PHP_SVC=$(systemctl list-units --type=service --all --no-legend 'php*-fpm.service' | awk '{print $1}' | head -1)
echo "php service: $PHP_SVC"
systemctl enable --now "$PHP_SVC"
systemctl is-active nginx mysql "$PHP_SVC"

echo "=== 5. сокет php-fpm ==="
ls -1 /run/php/ 2>/dev/null || true

echo "=== 6. каталог проекта ==="
mkdir -p /var/www/auma/public/uploads
mkdir -p /var/www/auma/app

echo "=== 7. база данных ==="
DBPASS_FILE=/root/.auma-db-pass
if [ ! -f "$DBPASS_FILE" ]; then
  DBPASS=$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 24)
  echo -n "$DBPASS" > "$DBPASS_FILE"
  chmod 600 "$DBPASS_FILE"
fi
DBPASS=$(cat "$DBPASS_FILE")

mysql <<SQL
CREATE DATABASE IF NOT EXISTS auma CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'auma'@'localhost' IDENTIFIED BY '${DBPASS}';
ALTER USER 'auma'@'localhost' IDENTIFIED BY '${DBPASS}';
GRANT ALL PRIVILEGES ON auma.* TO 'auma'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "database ready: auma / auma / $(echo -n "$DBPASS" | wc -c) chars"
mysql -e "SHOW DATABASES;" | grep -x auma && echo "DB OK"
