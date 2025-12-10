<?php

namespace App\Middleware;

use App\Utils\JWTUtil;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class OptionalAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler)
    {
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (!empty($authHeader)) {
            $parts = explode(' ', $authHeader);
            if (count($parts) === 2 && $parts[0] === 'Bearer') {
                $claims = JWTUtil::validateToken($parts[1]);
                if ($claims !== null) {
                    $request = $request->withAttribute('userID', $claims['userId']);
                    $request = $request->withAttribute('email', $claims['email']);
                    $request = $request->withAttribute('role', $claims['role']);
                }
            }
        }

        return $handler->handle($request);
    }
}


