#!/bin/sh
set -e

echo "Ожидание готовности PostgreSQL..."
until pg_isready -h postgres -U postgres; do
  sleep 1
done

echo "Выполнение миграций..."
psql -h postgres -U postgres -d bekend -f /var/www/html/migrations/001_create_tables.sql

echo "База данных инициализирована!"

