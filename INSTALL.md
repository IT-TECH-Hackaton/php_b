# Инструкция по установке

## Требования

- PHP 8.1 или выше
- PostgreSQL 12 или выше
- Composer
- Расширения PHP: pdo_pgsql, curl, mbstring, fileinfo

## Установка

1. Установите зависимости:
```bash
composer install
```

2. Скопируйте файл `.env.example` в `.env`:
```bash
cp .env.example .env
```

3. Настройте переменные окружения в файле `.env`:
   - Настройте подключение к базе данных
   - Укажите JWT_SECRET (минимум 32 символа)
   - Настройте email для отправки писем
   - При необходимости настройте Яндекс OAuth и Геокодер

4. Создайте базу данных и выполните миграции:
```bash
psql -U postgres -f migrations/001_create_tables.sql
```

Или вручную:
```bash
psql -U postgres
CREATE DATABASE bekend;
\c bekend
\i migrations/001_create_tables.sql
```

5. Создайте директорию для загрузок:
```bash
mkdir -p uploads
chmod 755 uploads
```

6. Запустите сервер:

Вариант 1 - встроенный PHP сервер:
```bash
php -S localhost:8080 -t public public/index.php
```

Вариант 2 - через composer:
```bash
composer start
```

Вариант 3 - через веб-сервер (Apache/Nginx):
Настройте виртуальный хост, указывающий на директорию `public/`

## Создание администратора

После запуска сервера создайте администратора:
```bash
curl -X POST http://localhost:8080/api/auth/init-admin
```

Или используйте админ-панель после входа с учетными данными:
- Email: admin@system.local
- Password: Admin123!

**Важно:** Измените пароль администратора после первого входа!

## Проверка работы

Проверьте health endpoint:
```bash
curl http://localhost:8080/health
```

Должен вернуться ответ: `{"status":"ok"}`

## Структура проекта

```
back_xaxaton2025_php/
├── src/
│   ├── Config/          # Конфигурация
│   ├── Database/        # Подключение к БД
│   ├── Handlers/        # Обработчики запросов
│   ├── Middleware/      # Middleware
│   ├── Models/          # Модели данных
│   ├── Routes/          # Роутинг
│   ├── Services/        # Сервисы
│   └── Utils/           # Утилиты
├── public/              # Публичная директория
├── migrations/          # SQL миграции
└── uploads/             # Загруженные файлы
```

## API Документация

Все эндпоинты соответствуют Go-версии бэкенда. См. оригинальную документацию Swagger или README.md Go-версии.

## Примечания

- Для работы с PostgreSQL требуется расширение `uuid-ossp`
- JWT токены используют секретный ключ из конфигурации
- Загруженные файлы сохраняются в директории `uploads/`
- Логи выводятся в stdout


