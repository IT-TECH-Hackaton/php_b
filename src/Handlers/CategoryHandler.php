<?php

namespace App\Handlers;

use App\Database\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Ramsey\Uuid\Uuid;

class CategoryHandler
{
    public function getCategories(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT id, name, description, created_at, updated_at FROM categories ORDER BY name");
        $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $categories = array_map(function($cat) {
            return [
                'id' => $cat['id'],
                'name' => $cat['name'],
                'description' => $cat['description'],
                'createdAt' => $cat['created_at'],
                'updatedAt' => $cat['updated_at']
            ];
        }, $categories);
        
        $response->getBody()->write(json_encode($categories));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createCategory(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['name'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $categoryId = Uuid::uuid4()->toString();
        
        $stmt = $db->prepare("INSERT INTO categories (id, name, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $categoryId,
            $data['name'],
            $data['description'] ?? null,
            (new \DateTime())->format('Y-m-d H:i:s'),
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);
        
        $response->getBody()->write(json_encode(['id' => $categoryId, 'message' => 'Категория создана']));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function updateCategory(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        $db = Database::getConnection();
        
        $updates = [];
        $bindings = [];
        
        if (isset($data['name'])) {
            $updates[] = "name = ?";
            $bindings[] = $data['name'];
        }
        
        if (isset($data['description'])) {
            $updates[] = "description = ?";
            $bindings[] = $data['description'];
        }
        
        if (empty($updates)) {
            $response->getBody()->write(json_encode(['error' => 'Нет данных для обновления']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $categoryId = $route->getArgument('id');
        $updates[] = "updated_at = ?";
        $bindings[] = (new \DateTime())->format('Y-m-d H:i:s');
        $bindings[] = $categoryId;
        
        $query = "UPDATE categories SET " . implode(", ", $updates) . " WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute($bindings);
        
        $response->getBody()->write(json_encode(['message' => 'Категория обновлена']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function deleteCategory(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $categoryId = $route->getArgument('id');
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$categoryId]);
        
        $response->getBody()->write(json_encode(['message' => 'Категория удалена']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

