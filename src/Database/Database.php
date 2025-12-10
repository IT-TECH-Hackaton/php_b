<?php

namespace App\Database;

use App\Config\Config;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;

class Database
{
    private static ?PDO $connection = null;
    private static LoggerInterface $logger;

    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    public static function connect(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        try {
            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s",
                Config::$dbHost,
                Config::$dbPort,
                Config::$dbName
            );

            self::$connection = new PDO(
                $dsn,
                Config::$dbUser,
                Config::$dbPassword,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            if (isset(self::$logger)) {
                self::$logger->info('Подключение к базе данных установлено');
            }

            return self::$connection;
        } catch (PDOException $e) {
            if (isset(self::$logger)) {
                self::$logger->error('Ошибка подключения к базе данных', ['error' => $e->getMessage()]);
            }
            throw $e;
        }
    }

    public static function getConnection(): ?PDO
    {
        return self::$connection;
    }

    public static function createDatabaseIfNotExists(): void
    {
        try {
            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=postgres",
                Config::$dbHost,
                Config::$dbPort
            );

            $pdo = new PDO($dsn, Config::$dbUser, Config::$dbPassword);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM pg_database WHERE datname = ?");
            $stmt->execute([Config::$dbName]);
            $exists = $stmt->fetchColumn() > 0;

            if (!$exists) {
                $pdo->exec(sprintf('CREATE DATABASE "%s"', Config::$dbName));
                if (isset(self::$logger)) {
                    self::$logger->info('База данных успешно создана', ['database' => Config::$dbName]);
                }
            }
        } catch (PDOException $e) {
            if (isset(self::$logger)) {
                self::$logger->warning('Не удалось создать базу данных автоматически', ['error' => $e->getMessage()]);
            }
        }
    }
}


