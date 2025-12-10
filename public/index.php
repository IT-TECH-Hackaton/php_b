<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Config;
use App\Database\Database;
use App\Logger\Logger;
use App\Routes\Routes;
use Slim\Factory\AppFactory;

Config::load();
Logger::init(Config::$appEnv);
Database::setLogger(Logger::getLogger());
Database::createDatabaseIfNotExists();
Database::connect();

$app = AppFactory::create();

$app->add(\App\Middleware\CorsMiddleware::class);

$app->get('/uploads/{filename}', function ($request, $response, $args) {
    $file = __DIR__ . '/../uploads/' . basename($args['filename']);
    if (!file_exists($file)) {
        return $response->withStatus(404);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file);
    finfo_close($finfo);
    $response->getBody()->write(file_get_contents($file));
    return $response->withHeader('Content-Type', $mimeType);
});

Routes::setup($app);

$app->run();

