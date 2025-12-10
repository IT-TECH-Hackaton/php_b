<?php

namespace App\Middleware;

use App\Utils\JWTUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (empty($authHeader)) {
            $response = new Response();
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $parts = explode(' ', $authHeader);
        if (count($parts) !== 2 || $parts[0] !== 'Bearer') {
            $response = new Response();
            $response->getBody()->write(json_encode(['error' => 'Неверный формат токена']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $claims = JWTUtil::validateToken($parts[1]);
        if ($claims === null) {
            $response = new Response();
            $response->getBody()->write(json_encode(['error' => 'Недействительный токен']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $request = $request->withAttribute('userID', $claims['userId']);
        $request = $request->withAttribute('email', $claims['email']);
        $request = $request->withAttribute('role', $claims['role']);

        return $handler->handle($request);
    }
}


