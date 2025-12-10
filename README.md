# Bekend Backend API (PHP)

Бекенд для системы электронной афиши на PHP.

## Установка

1. Установите зависимости:
```bash
composer install
```

2. Скопируйте `.env.example` в `.env` и настройте переменные окружения:
```bash
cp .env.example .env
```

3. Создайте базу данных и выполните миграции:
```bash
psql -U postgres -f migrations/001_create_tables.sql
```

4. Запустите сервер:
```bash
composer start
```

Или используйте встроенный PHP сервер:
```bash
php -S localhost:8080 -t public public/index.php
```

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
│   ├── Services/        # Сервисы (email, cron)
│   └── Utils/           # Утилиты (JWT, валидация)
├── public/              # Публичная директория
├── migrations/          # SQL миграции
└── composer.json        # Зависимости
```

## API Endpoints

### Авторизация
- `POST /api/auth/register` - Регистрация
- `POST /api/auth/verify-email` - Подтверждение email
- `POST /api/auth/login` - Вход
- `POST /api/auth/logout` - Выход
- `POST /api/auth/forgot-password` - Восстановление пароля
- `POST /api/auth/reset-password` - Сброс пароля

### События
- `GET /api/events` - Список событий
- `GET /api/events/{id}` - Получить событие
- `POST /api/events` - Создать событие
- `PUT /api/events/{id}` - Обновить событие
- `DELETE /api/events/{id}` - Удалить событие
- `POST /api/events/{id}/join` - Присоединиться к событию
- `DELETE /api/events/{id}/leave` - Покинуть событие

### Пользователи
- `GET /api/user/profile` - Получить профиль
- `PUT /api/user/profile` - Обновить профиль

### Админка
- `GET /api/admin/users` - Список пользователей
- `POST /api/admin/users` - Создать пользователя
- `GET /api/admin/users/{id}` - Получить пользователя
- `PUT /api/admin/users/{id}` - Обновить пользователя
- `DELETE /api/admin/users/{id}` - Удалить пользователя
- `GET /api/admin/events` - Список событий (админ)
- `GET /api/admin/categories` - Список категорий
- `POST /api/admin/categories` - Создать категорию
- `PUT /api/admin/categories/{id}` - Обновить категорию
- `DELETE /api/admin/categories/{id}` - Удалить категорию

## Переменные окружения

См. `.env.example` для полного списка переменных.

## Примечания

- Некоторые эндпоинты помечены как "Not implemented" и требуют доработки
- Для работы с PostgreSQL требуется расширение `uuid-ossp`
- JWT токены используют секретный ключ из конфигурации


