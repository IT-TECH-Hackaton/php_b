# Быстрый старт

## ⚠️ ВАЖНО: Запустите Docker Desktop!

Docker Desktop должен быть запущен и не приостановлен. Проверьте иконку Docker в трее Windows.

## Шаг 1: Запуск бэкенда

Откройте терминал в папке `back_xaxaton2025_php` и выполните:

```bash
docker-compose up -d
```

Это запустит:
- PostgreSQL базу данных на порту 5432
- PHP бэкенд на порту 8080
- Автоматически выполнит миграции базы данных

Проверьте что все запустилось:
```bash
docker-compose ps
```

Должны быть запущены контейнеры:
- `bekend-postgres-php` (postgres)
- `bekend-db-init-php` (инициализация БД)
- `bekend-app-php` (PHP приложение)

## Шаг 2: Проверка бэкенда

Откройте браузер и перейдите на:
```
http://localhost:8080/health
```

Должен вернуться ответ: `{"status":"ok"}`

## Шаг 3: Запуск фронтенда

Откройте новый терминал в папке `frontend-MyAfisha` и выполните:

```bash
npm run dev
```

Фронтенд будет доступен по адресу:
```
http://localhost:5173
```

## Шаг 4: Создание администратора

После запуска бэкенда создайте администратора:

```bash
curl -X POST http://localhost:8080/api/auth/init-admin
```

Или используйте учетные данные по умолчанию:
- **Email:** admin@system.local
- **Password:** Admin123!

⚠️ **Важно:** Измените пароль после первого входа!

## Остановка

Для остановки бэкенда:
```bash
cd back_xaxaton2025_php
docker-compose down
```

Для остановки фронтенда нажмите `Ctrl+C` в терминале.

## Проблемы?

### Docker Desktop приостановлен
- Откройте Docker Desktop
- Нажмите кнопку "Resume" или "Unpause"

### Порт 8080 занят
- Измените порт в `docker-compose.yml` (строка `"8080:8080"`)

### Порт 5432 занят
- Измените порт PostgreSQL в `docker-compose.yml` (строка `"5432:5432"`)

### Ошибки базы данных
- Проверьте логи: `docker-compose logs db-init`
- Убедитесь что миграции выполнены

### Ошибки PHP приложения
- Проверьте логи: `docker-compose logs app`
- Убедитесь что зависимости установлены: `docker-compose exec app composer install`

