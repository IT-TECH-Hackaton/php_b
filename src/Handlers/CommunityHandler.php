<?php

namespace App\Handlers;

use App\Database\Database;
use App\Utils\Validation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Ramsey\Uuid\Uuid;

class CommunityHandler
{
    public function getCommunities(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $search = $params['search'] ?? null;
        $category = $params['category'] ?? null;
        $interestId = $params['interestID'] ?? null;
        
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $db = Database::getConnection();
        $query = "SELECT DISTINCT mc.*, u.id as admin_id, u.email as admin_email 
                  FROM micro_communities mc 
                  LEFT JOIN users u ON mc.admin_id = u.id 
                  WHERE 1=1";
        $bindings = [];
        
        if ($search) {
            if (!Validation::validateStringLength($search, 1, 100)) {
                $response->getBody()->write(json_encode(['error' => 'Поисковый запрос должен быть от 1 до 100 символов']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $query .= " AND (mc.name ILIKE ? OR mc.description ILIKE ?)";
            $searchTerm = '%' . $search . '%';
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
        }
        
        if ($category) {
            $query .= " AND EXISTS (SELECT 1 FROM community_interests ci JOIN interests i ON ci.interest_id = i.id WHERE ci.community_id = mc.id AND i.category = ?)";
            $bindings[] = $category;
        }
        
        if ($interestId) {
            if (!Validation::validateUUID($interestId)) {
                $response->getBody()->write(json_encode(['error' => 'Неверный формат ID интереса']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $query .= " AND EXISTS (SELECT 1 FROM community_interests WHERE community_id = mc.id AND interest_id = ?)";
            $bindings[] = $interestId;
        }
        
        $countQuery = str_replace("SELECT DISTINCT mc.*, u.id as admin_id, u.email as admin_email", "SELECT COUNT(DISTINCT mc.id)", $query);
        $stmt = $db->prepare($countQuery);
        $stmt->execute($bindings);
        $total = (int)$stmt->fetchColumn();
        
        $query .= " ORDER BY mc.members_count DESC, mc.created_at DESC LIMIT ? OFFSET ?";
        $bindings[] = $limit;
        $bindings[] = $offset;
        
        $stmt = $db->prepare($query);
        $stmt->execute($bindings);
        $communities = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($communities as $comm) {
            $stmt = $db->prepare("SELECT i.* FROM interests i JOIN community_interests ci ON i.id = ci.interest_id WHERE ci.community_id = ?");
            $stmt->execute([$comm['id']]);
            $interests = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $result[] = [
                'id' => $comm['id'],
                'name' => $comm['name'],
                'description' => $comm['description'],
                'interests' => array_map(fn($i) => [
                    'id' => $i['id'],
                    'name' => $i['name'],
                    'category' => $i['category'],
                    'description' => $i['description']
                ], $interests),
                'admin' => [
                    'id' => $comm['admin_id'],
                    'email' => $comm['admin_email']
                ],
                'autoNotify' => (bool)$comm['auto_notify'],
                'membersCount' => (int)$comm['members_count'],
                'createdAt' => $comm['created_at']
            ];
        }
        
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

    public function getCommunity(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $communityId = $route->getArgument('id');
        
        if (!Validation::validateUUID($communityId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT mc.*, u.id as admin_id, u.email as admin_email 
                              FROM micro_communities mc 
                              LEFT JOIN users u ON mc.admin_id = u.id 
                              WHERE mc.id = ?");
        $stmt->execute([$communityId]);
        $community = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$community) {
            $response->getBody()->write(json_encode(['error' => 'Сообщество не найдено']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("SELECT i.* FROM interests i JOIN community_interests ci ON i.id = ci.interest_id WHERE ci.community_id = ?");
        $stmt->execute([$communityId]);
        $interests = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $response->getBody()->write(json_encode([
            'id' => $community['id'],
            'name' => $community['name'],
            'description' => $community['description'],
            'interests' => array_map(fn($i) => [
                'id' => $i['id'],
                'name' => $i['name'],
                'category' => $i['category'],
                'description' => $i['description']
            ], $interests),
            'admin' => [
                'id' => $community['admin_id'],
                'email' => $community['admin_email']
            ],
            'autoNotify' => (bool)$community['auto_notify'],
            'membersCount' => (int)$community['members_count'],
            'createdAt' => $community['created_at']
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getCommunityMembers(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $communityId = $route->getArgument('id');
        
        if (!Validation::validateUUID($communityId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT cm.*, u.id as user_id, u.email as user_email, u.full_name 
                              FROM community_members cm 
                              LEFT JOIN users u ON cm.user_id = u.id 
                              WHERE cm.community_id = ? 
                              ORDER BY cm.joined_at ASC");
        $stmt->execute([$communityId]);
        $members = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $result = array_map(function($mem) {
            return [
                'id' => $mem['id'],
                'user' => [
                    'id' => $mem['user_id'],
                    'email' => $mem['user_email']
                ],
                'joinedAt' => $mem['joined_at']
            ];
        }, $members);
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createCommunity(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['name'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if (!Validation::validateStringLength($data['name'], 1, 100)) {
            $response->getBody()->write(json_encode(['error' => 'Название должно быть от 1 до 100 символов']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $communityId = Uuid::uuid4()->toString();
        
        $stmt = $db->prepare("INSERT INTO micro_communities (id, name, description, admin_id, auto_notify, members_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $communityId,
            trim($data['name']),
            $data['description'] ?? null,
            $userId,
            $data['autoNotify'] ?? true,
            1,
            (new \DateTime())->format('Y-m-d H:i:s'),
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);
        
        if (isset($data['interestIDs']) && is_array($data['interestIDs'])) {
            foreach ($data['interestIDs'] as $interestId) {
                if (Validation::validateUUID($interestId)) {
                    $stmt = $db->prepare("INSERT INTO community_interests (community_id, interest_id, created_at) VALUES (?, ?, ?)");
                    $stmt->execute([$communityId, $interestId, (new \DateTime())->format('Y-m-d H:i:s')]);
                }
            }
        }
        
        $memberId = Uuid::uuid4()->toString();
        $stmt = $db->prepare("INSERT INTO community_members (id, user_id, community_id, joined_at, updated_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $memberId,
            $userId,
            $communityId,
            (new \DateTime())->format('Y-m-d H:i:s'),
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);
        
        $response->getBody()->write(json_encode(['id' => $communityId, 'message' => 'Сообщество создано']));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function joinCommunity(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $communityId = $route->getArgument('id');
        
        if (!Validation::validateUUID($communityId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM micro_communities WHERE id = ?");
        $stmt->execute([$communityId]);
        if ($stmt->fetchColumn() == 0) {
            $response->getBody()->write(json_encode(['error' => 'Сообщество не найдено']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM community_members WHERE user_id = ? AND community_id = ?");
        $stmt->execute([$userId, $communityId]);
        if ($stmt->fetchColumn() > 0) {
            $response->getBody()->write(json_encode(['error' => 'Вы уже состоите в этом сообществе']));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
        
        $memberId = Uuid::uuid4()->toString();
        $stmt = $db->prepare("INSERT INTO community_members (id, user_id, community_id, joined_at, updated_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $memberId,
            $userId,
            $communityId,
            (new \DateTime())->format('Y-m-d H:i:s'),
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);
        
        $stmt = $db->prepare("UPDATE micro_communities SET members_count = members_count + 1 WHERE id = ?");
        $stmt->execute([$communityId]);
        
        $response->getBody()->write(json_encode(['message' => 'Вы присоединились к сообществу']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function leaveCommunity(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $communityId = $route->getArgument('id');
        
        if (!Validation::validateUUID($communityId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT admin_id FROM micro_communities WHERE id = ?");
        $stmt->execute([$communityId]);
        $community = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$community) {
            $response->getBody()->write(json_encode(['error' => 'Сообщество не найдено']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        if ($community['admin_id'] === $userId) {
            $response->getBody()->write(json_encode(['error' => 'Администратор не может покинуть сообщество']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("DELETE FROM community_members WHERE user_id = ? AND community_id = ?");
        $stmt->execute([$userId, $communityId]);
        
        $stmt = $db->prepare("UPDATE micro_communities SET members_count = GREATEST(members_count - 1, 0) WHERE id = ?");
        $stmt->execute([$communityId]);
        
        $response->getBody()->write(json_encode(['message' => 'Вы покинули сообщество']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getMyCommunities(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT mc.*, u.id as admin_id, u.email as admin_email 
                              FROM community_members cm 
                              JOIN micro_communities mc ON cm.community_id = mc.id 
                              LEFT JOIN users u ON mc.admin_id = u.id 
                              WHERE cm.user_id = ?");
        $stmt->execute([$userId]);
        $communities = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($communities as $comm) {
            $stmt = $db->prepare("SELECT i.* FROM interests i JOIN community_interests ci ON i.id = ci.interest_id WHERE ci.community_id = ?");
            $stmt->execute([$comm['id']]);
            $interests = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $result[] = [
                'id' => $comm['id'],
                'name' => $comm['name'],
                'description' => $comm['description'],
                'interests' => array_map(fn($i) => [
                    'id' => $i['id'],
                    'name' => $i['name'],
                    'category' => $i['category'],
                    'description' => $i['description']
                ], $interests),
                'admin' => [
                    'id' => $comm['admin_id'],
                    'email' => $comm['admin_email']
                ],
                'autoNotify' => (bool)$comm['auto_notify'],
                'membersCount' => (int)$comm['members_count'],
                'createdAt' => $comm['created_at']
            ];
        }
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
