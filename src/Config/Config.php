<?php

namespace App\Config;

class Config
{
    public static string $appPort;
    public static string $appEnv;
    public static string $dbHost;
    public static string $dbPort;
    public static string $dbUser;
    public static string $dbPassword;
    public static string $dbName;
    public static string $jwtSecret;
    public static int $jwtExpiration;
    public static string $emailHost;
    public static int $emailPort;
    public static string $emailUser;
    public static string $emailPassword;
    public static string $emailFrom;
    public static string $frontendUrl;
    public static string $yandexClientId;
    public static string $yandexClientSecret;
    public static string $yandexRedirectUri;
    public static bool $fakeYandexAuth;
    public static string $yandexGeocoderApiKey;
    public static string $corsAllowOrigins;

    public static function load(): void
    {
        $envPath = __DIR__ . '/../../.env';
        if (file_exists($envPath)) {
            $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
            $dotenv->load();
        }

        self::$appPort = $_ENV['APP_PORT'] ?? '8080';
        self::$appEnv = $_ENV['APP_ENV'] ?? 'development';
        self::$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
        self::$dbPort = $_ENV['DB_PORT'] ?? '5432';
        self::$dbUser = $_ENV['DB_USER'] ?? 'postgres';
        self::$dbPassword = $_ENV['DB_PASSWORD'] ?? 'postgres';
        self::$dbName = $_ENV['DB_NAME'] ?? 'bekend';
        self::$jwtSecret = $_ENV['JWT_SECRET'] ?? 'change-me-in-production';
        self::$jwtExpiration = (int)($_ENV['JWT_EXPIRATION'] ?? 86400);
        self::$emailHost = $_ENV['EMAIL_HOST'] ?? 'smtp.gmail.com';
        self::$emailPort = (int)($_ENV['EMAIL_PORT'] ?? 587);
        self::$emailUser = $_ENV['EMAIL_USER'] ?? '';
        self::$emailPassword = $_ENV['EMAIL_PASSWORD'] ?? '';
        self::$emailFrom = $_ENV['EMAIL_FROM'] ?? '';
        self::$frontendUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:5173';
        self::$yandexClientId = $_ENV['YANDEX_CLIENT_ID'] ?? '';
        self::$yandexClientSecret = $_ENV['YANDEX_CLIENT_SECRET'] ?? '';
        self::$yandexRedirectUri = $_ENV['YANDEX_REDIRECT_URI'] ?? 'http://localhost:8081/api/auth/yandex/callback';
        self::$fakeYandexAuth = ($_ENV['FAKE_YANDEX_AUTH'] ?? 'false') === 'true';
        self::$yandexGeocoderApiKey = $_ENV['YANDEX_GEOCODER_API_KEY'] ?? '';
        self::$corsAllowOrigins = $_ENV['CORS_ALLOW_ORIGINS'] ?? 'http://localhost:5173';
    }
}


