<?php

namespace App\Middleware;

use App\Config\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $origin = $request->getHeaderLine('Origin');
        $allowedOrigins = explode(',', Config::$corsAllowOrigins);
        $allowedOrigins = array_map('trim', $allowedOrigins);

        if (in_array($origin, $allowedOrigins) || in_array('*', $allowedOrigins)) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $origin ?: '*');
        }

        $response = $response->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Headers', 'Origin, Content-Type, Accept, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH');

        if ($request->getMethod() === 'OPTIONS') {
            return $response->withStatus(204);
        }

        return $response;
    }
}


