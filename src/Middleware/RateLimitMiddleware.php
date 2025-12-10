<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class RateLimitMiddleware implements MiddlewareInterface
{
    private static array $requests = [];
    private string $rate;

    public function __construct(string $rate = '10-M')
    {
        $this->rate = $rate;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->cleanup();
        
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $key = $ip . '_' . $this->rate;
        
        [$limit, $period] = $this->parseRate($this->rate);
        
        if (!isset(self::$requests[$key])) {
            self::$requests[$key] = ['count' => 0, 'reset' => time() + $period];
        }
        
        if (time() > self::$requests[$key]['reset']) {
            self::$requests[$key] = ['count' => 0, 'reset' => time() + $period];
        }
        
        self::$requests[$key]['count']++;
        
        if (self::$requests[$key]['count'] > $limit) {
            $response = new Response();
            $response->getBody()->write(json_encode(['error' => 'Превышен лимит запросов. Попробуйте позже.']));
            return $response->withStatus(429)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-RateLimit-Limit', (string)$limit)
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withHeader('X-RateLimit-Reset', (string)self::$requests[$key]['reset']);
        }
        
        $response = $handler->handle($request);
        return $response->withHeader('X-RateLimit-Limit', (string)$limit)
            ->withHeader('X-RateLimit-Remaining', (string)max(0, $limit - self::$requests[$key]['count']))
            ->withHeader('X-RateLimit-Reset', (string)self::$requests[$key]['reset']);
    }

    private function parseRate(string $rate): array
    {
        if (preg_match('/^(\d+)-([HM])$/', $rate, $matches)) {
            $limit = (int)$matches[1];
            $period = $matches[2] === 'H' ? 3600 : 60;
            return [$limit, $period];
        }
        return [10, 60];
    }

    private function cleanup(): void
    {
        $now = time();
        foreach (self::$requests as $key => $data) {
            if ($now > $data['reset']) {
                unset(self::$requests[$key]);
            }
        }
    }
}


