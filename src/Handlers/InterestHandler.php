<?php

namespace App\Handlers;

use App\Database\Database;
use App\Utils\Validation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Ramsey\Uuid\Uuid;

class InterestHandler
{
    public function getInterests(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $category = $params['category'] ?? null;
        $search = $params['search'] ?? null;
        
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $db = Database::getConnection();
        $query = "SELECT * FROM interests WHERE 1=1";
        $bindings = [];
        
        if ($category) {
            if (!Validation::validateStringLength($category, 1, 50)) {
                $response->getBody()->write(json_encode(['error' => 'Категория должна быть от 1 до 50 символов']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $query .= " AND category = ?";
            $bindings[] = $category;
        }
        
        if ($search) {
            if (!Validation::validateStringLength($search, 1, 100)) {
                $response->getBody()->write(json_encode(['error' => 'Поисковый запрос должен быть от 1 до 100 символов']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $query .= " AND (name ILIKE ? OR description ILIKE ?)";
            $searchTerm = '%' . $search . '%';
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
        }
        
        $countQuery = "SELECT COUNT(*) FROM interests WHERE 1=1" . 
                     ($category ? " AND category = ?" : "") .
                     ($search ? " AND (name ILIKE ? OR description ILIKE ?)" : "");
        $countBindings = array_filter($bindings, fn($b) => $b !== null);
        
        $stmt = $db->prepare($countQuery);
        $stmt->execute($countBindings);
        $total = (int)$stmt->fetchColumn();
        
        $query .= " ORDER BY name ASC LIMIT ? OFFSET ?";
        $bindings[] = $limit;
        $bindings[] = $offset;
        
        $stmt = $db->prepare($query);
        $stmt->execute($bindings);
        $interests = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $result = array_map(function($interest) {
            return [
                'id' => $interest['id'],
                'name' => $interest['name'],
                'category' => $interest['category'],
                'description' => $interest['description']
            ];
        }, $interests);
        
        $response->getBody()->write(json_encode([
            'data' => $result,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int)ceil($total / $limit)
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getCategories(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT DISTINCT category FROM interests WHERE category IS NOT NULL ORDER BY category");
        $categories = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        $response->getBody()->write(json_encode($categories));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createInterest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['name']) || !isset($data['category'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if (!Validation::validateStringLength($data['name'], 1, 100)) {
            $response->getBody()->write(json_encode(['error' => 'Название должно быть от 1 до 100 символов']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if (!Validation::validateStringLength($data['category'], 1, 50)) {
            $response->getBody()->write(json_encode(['error' => 'Категория должна быть от 1 до 50 символов']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $name = trim($data['name']);
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM interests WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetchColumn() > 0) {
            $response->getBody()->write(json_encode(['error' => 'Интерес с таким названием уже существует']));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
        
        $interestId = Uuid::uuid4()->toString();
        $stmt = $db->prepare("INSERT INTO interests (id, name, category, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $interestId,
            $name,
            trim($data['category']),
            $data['description'] ?? null,
            (new \DateTime())->format('Y-m-d H:i:s'),
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);
        
        $response->getBody()->write(json_encode([
            'id' => $interestId,
            'name' => $name,
            'category' => trim($data['category']),
            'description' => $data['description'] ?? null
        ]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function getUserInterests(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT ui.*, i.name, i.category, i.description 
                              FROM user_interests ui 
                              LEFT JOIN interests i ON ui.interest_id = i.id 
                              WHERE ui.user_id = ? 
                              ORDER BY ui.created_at DESC");
        $stmt->execute([$userId]);
        $userInterests = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $result = array_map(function($ui) {
            return [
                'id' => $ui['id'],
                'interest' => [
                    'id' => $ui['interest_id'],
                    'name' => $ui['name'],
                    'category' => $ui['category'],
                    'description' => $ui['description']
                ],
                'weight' => (int)$ui['weight'],
                'createdAt' => $ui['created_at']
            ];
        }, $userInterests);
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function addUserInterest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['interestID'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $interestId = $data['interestID'];
        if (!Validation::validateUUID($interestId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID интереса']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM interests WHERE id = ?");
        $stmt->execute([$interestId]);
        if ($stmt->fetchColumn() == 0) {
            $response->getBody()->write(json_encode(['error' => 'Интерес не найден']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM user_interests WHERE user_id = ? AND interest_id = ?");
        $stmt->execute([$userId, $interestId]);
        if ($stmt->fetchColumn() > 0) {
            $response->getBody()->write(json_encode(['error' => 'Интерес уже добавлен']));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
        
        $weight = isset($data['weight']) ? max(1, min(10, (int)$data['weight'])) : 5;
        
        $userInterestId = Uuid::uuid4()->toString();
        $stmt = $db->prepare("INSERT INTO user_interests (id, user_id, interest_id, weight, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userInterestId,
            $userId,
            $interestId,
            $weight,
            (new \DateTime())->format('Y-m-d H:i:s'),
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);
        
        $response->getBody()->write(json_encode(['message' => 'Интерес добавлен']));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function removeUserInterest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $interestId = $route->getArgument('id');
        
        if (!Validation::validateUUID($interestId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM user_interests WHERE user_id = ? AND interest_id = ?");
        $stmt->execute([$userId, $interestId]);
        
        $response->getBody()->write(json_encode(['message' => 'Интерес удален']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function updateUserInterestWeight(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $interestId = $route->getArgument('id');
        
        if (!Validation::validateUUID($interestId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['weight']) || $data['weight'] < 1 || $data['weight'] > 10) {
            $response->getBody()->write(json_encode(['error' => 'Вес должен быть от 1 до 10']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM user_interests WHERE user_id = ? AND interest_id = ?");
        $stmt->execute([$userId, $interestId]);
        if ($stmt->fetchColumn() == 0) {
            $response->getBody()->write(json_encode(['error' => 'Интерес не найден']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("UPDATE user_interests SET weight = ?, updated_at = ? WHERE user_id = ? AND interest_id = ?");
        $stmt->execute([
            $data['weight'],
            (new \DateTime())->format('Y-m-d H:i:s'),
            $userId,
            $interestId
        ]);
        
        $response->getBody()->write(json_encode(['message' => 'Вес интереса обновлен']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
