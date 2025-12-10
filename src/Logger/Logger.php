<?php

namespace App\Logger;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LoggerInterface;

class Logger
{
    private static ?MonologLogger $instance = null;

    public static function init(string $env): void
    {
        $logger = new MonologLogger('bekend');
        
        $handler = new StreamHandler('php://stdout', $env === 'production' ? MonologLogger::WARNING : MonologLogger::DEBUG);
        $formatter = new LineFormatter("[%datetime%] %level_name%: %message% %context% %extra%\n", 'Y-m-d H:i:s');
        $handler->setFormatter($formatter);
        
        $logger->pushHandler($handler);
        
        self::$instance = $logger;
    }

    public static function getLogger(): ?MonologLogger
    {
        return self::$instance;
    }
}


