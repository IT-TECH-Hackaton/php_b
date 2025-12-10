<?php

namespace App\Handlers;

use App\Database\Database;
use App\Models\EventMatching;
use App\Models\MatchRequest;
use App\Utils\Validation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Ramsey\Uuid\Uuid;

class MatchingHandler
{
    public function createEventMatching(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $eventId = $route->getArgument('id');
        
        if (!Validation::validateUUID($eventId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID события']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM events WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$eventId]);
        if ($stmt->fetchColumn() == 0) {
            $response->getBody()->write(json_encode(['error' => 'Событие не найдено']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $data = json_decode($request->getBody()->getContents(), true);
        $status = $data['status'] ?? EventMatching::STATUS_LOOKING;
        $preferences = $data['preferences'] ?? null;
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM event_matching WHERE user_id = ? AND event_id = ?");
        $stmt->execute([$userId, $eventId]);
        
        if ($stmt->fetchColumn() > 0) {
            $stmt = $db->prepare("UPDATE event_matching SET status = ?, preferences = ?, updated_at = ? WHERE user_id = ? AND event_id = ?");
            $stmt->execute([
                $status,
                $preferences,
                (new \DateTime())->format('Y-m-d H:i:s'),
                $userId,
                $eventId
            ]);
            $response->getBody()->write(json_encode(['message' => 'Матчинг обновлен']));
        } else {
            $matchingId = Uuid::uuid4()->toString();
            $stmt = $db->prepare("INSERT INTO event_matching (id, user_id, event_id, status, preferences, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $matchingId,
                $userId,
                $eventId,
                $status,
                $preferences,
                (new \DateTime())->format('Y-m-d H:i:s'),
                (new \DateTime())->format('Y-m-d H:i:s')
            ]);
            $response->getBody()->write(json_encode(['message' => 'Матчинг создан']));
        }
        
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function getMatches(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $eventId = $route->getArgument('id');
        
        if (!Validation::validateUUID($eventId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID события']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM event_matching WHERE user_id = ? AND event_id = ?");
        $stmt->execute([$userId, $eventId]);
        $userMatching = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$userMatching) {
            $response->getBody()->write(json_encode(['error' => 'Вы не отметили, что ищете компанию для этого события']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        if ($userMatching['status'] !== EventMatching::STATUS_LOOKING) {
            $response->getBody()->write(json_encode(['error' => 'Вы больше не ищете компанию для этого события']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $matches = $this->findMatches($userId, $eventId, $db);
        
        $response->getBody()->write(json_encode($matches));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function findMatches(string $userId, string $eventId, $db): array
    {
        $stmt = $db->prepare("SELECT ui.*, i.name FROM user_interests ui LEFT JOIN interests i ON ui.interest_id = i.id WHERE ui.user_id = ?");
        $stmt->execute([$userId]);
        $userInterests = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $userInterestMap = [];
        foreach ($userInterests as $ui) {
            $userInterestMap[$ui['interest_id']] = (int)$ui['weight'];
        }
        
        $stmt = $db->prepare("SELECT em.*, u.id as user_id, u.full_name, u.email 
                              FROM event_matching em 
                              LEFT JOIN users u ON em.user_id = u.id 
                              WHERE em.event_id = ? AND em.user_id != ? AND em.status = ?");
        $stmt->execute([$eventId, $userId, EventMatching::STATUS_LOOKING]);
        $otherMatchings = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $matches = [];
        foreach ($otherMatchings as $matching) {
            $otherUserId = $matching['user_id'];
            $stmt = $db->prepare("SELECT ui.*, i.name FROM user_interests ui LEFT JOIN interests i ON ui.interest_id = i.id WHERE ui.user_id = ?");
            $stmt->execute([$otherUserId]);
            $otherInterests = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $commonInterests = [];
            $totalScore = 0.0;
            $commonCount = 0;
            
            foreach ($otherInterests as $oi) {
                if (isset($userInterestMap[$oi['interest_id']])) {
                    $commonInterests[] = $oi['name'];
                    $totalScore += ($userInterestMap[$oi['interest_id']] + (int)$oi['weight']) / 2.0;
                    $commonCount++;
                }
            }
            
            if ($commonCount > 0) {
                $score = $totalScore / $commonCount;
                $matches[] = [
                    'user' => [
                        'id' => $matching['user_id'],
                        'fullName' => $matching['full_name'],
                        'email' => $matching['email']
                    ],
                    'score' => $score,
                    'commonInterests' => $commonInterests
                ];
            }
        }
        
        return $matches;
    }

    public function createMatchRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $eventId = $route->getArgument('id');
        
        if (!Validation::validateUUID($eventId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID события']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['toUserID'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $toUserId = $data['toUserID'];
        if ($userId === $toUserId) {
            $response->getBody()->write(json_encode(['error' => 'Нельзя отправить запрос самому себе']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM match_requests WHERE from_user_id = ? AND to_user_id = ? AND event_id = ?");
        $stmt->execute([$userId, $toUserId, $eventId]);
        if ($stmt->fetchColumn() > 0) {
            $response->getBody()->write(json_encode(['error' => 'Запрос уже отправлен']));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
        
        $requestId = Uuid::uuid4()->toString();
        $stmt = $db->prepare("INSERT INTO match_requests (id, from_user_id, to_user_id, event_id, status, message, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $requestId,
            $userId,
            $toUserId,
            $eventId,
            MatchRequest::STATUS_PENDING,
            $data['message'] ?? null,
            (new \DateTime())->format('Y-m-d H:i:s'),
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);
        
        $response->getBody()->write(json_encode(['message' => 'Запрос отправлен']));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function getMyMatchRequests(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $params = $request->getQueryParams();
        $status = $params['status'] ?? null;
        
        $db = Database::getConnection();
        $query = "SELECT mr.*, 
                  fu.id as from_user_id, fu.email as from_user_email, fu.full_name as from_user_name,
                  tu.id as to_user_id, tu.email as to_user_email, tu.full_name as to_user_name,
                  e.id as event_id, e.title as event_title
                  FROM match_requests mr
                  LEFT JOIN users fu ON mr.from_user_id = fu.id
                  LEFT JOIN users tu ON mr.to_user_id = tu.id
                  LEFT JOIN events e ON mr.event_id = e.id
                  WHERE (mr.to_user_id = ? OR mr.from_user_id = ?)";
        $bindings = [$userId, $userId];
        
        if ($status) {
            $query .= " AND mr.status = ?";
            $bindings[] = $status;
        }
        
        $query .= " ORDER BY mr.created_at DESC";
        
        $stmt = $db->prepare($query);
        $stmt->execute($bindings);
        $requests = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $result = array_map(function($req) {
            return [
                'id' => $req['id'],
                'fromUser' => [
                    'id' => $req['from_user_id'],
                    'email' => $req['from_user_email']
                ],
                'toUser' => [
                    'id' => $req['to_user_id'],
                    'email' => $req['to_user_email']
                ],
                'event' => [
                    'id' => $req['event_id'],
                    'title' => $req['event_title']
                ],
                'status' => $req['status'],
                'message' => $req['message'],
                'createdAt' => $req['created_at']
            ];
        }, $requests);
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function acceptMatchRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $requestId = $route->getArgument('requestId');
        
        if (!Validation::validateUUID($requestId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM match_requests WHERE id = ? AND to_user_id = ?");
        $stmt->execute([$requestId, $userId]);
        $matchRequest = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$matchRequest) {
            $response->getBody()->write(json_encode(['error' => 'Запрос не найден']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        if ($matchRequest['status'] !== MatchRequest::STATUS_PENDING) {
            $response->getBody()->write(json_encode(['error' => 'Запрос уже обработан']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("UPDATE match_requests SET status = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([
            MatchRequest::STATUS_ACCEPTED,
            (new \DateTime())->format('Y-m-d H:i:s'),
            $requestId
        ]);
        
        $stmt = $db->prepare("UPDATE event_matching SET status = ?, updated_at = ? WHERE user_id = ? AND event_id = ?");
        $stmt->execute([
            EventMatching::STATUS_FOUND,
            (new \DateTime())->format('Y-m-d H:i:s'),
            $matchRequest['from_user_id'],
            $matchRequest['event_id']
        ]);
        
        $stmt = $db->prepare("UPDATE event_matching SET status = ?, updated_at = ? WHERE user_id = ? AND event_id = ?");
        $stmt->execute([
            EventMatching::STATUS_FOUND,
            (new \DateTime())->format('Y-m-d H:i:s'),
            $matchRequest['to_user_id'],
            $matchRequest['event_id']
        ]);
        
        $response->getBody()->write(json_encode(['message' => 'Запрос принят']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function rejectMatchRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $requestId = $route->getArgument('requestId');
        
        if (!Validation::validateUUID($requestId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM match_requests WHERE id = ? AND to_user_id = ?");
        $stmt->execute([$requestId, $userId]);
        $matchRequest = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$matchRequest) {
            $response->getBody()->write(json_encode(['error' => 'Запрос не найден']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        if ($matchRequest['status'] !== MatchRequest::STATUS_PENDING) {
            $response->getBody()->write(json_encode(['error' => 'Запрос уже обработан']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("UPDATE match_requests SET status = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([
            MatchRequest::STATUS_REJECTED,
            (new \DateTime())->format('Y-m-d H:i:s'),
            $requestId
        ]);
        
        $response->getBody()->write(json_encode(['message' => 'Запрос отклонен']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function removeEventMatching(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $eventId = $route->getArgument('id');
        
        if (!Validation::validateUUID($eventId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID события']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM event_matching WHERE user_id = ? AND event_id = ?");
        $stmt->execute([$userId, $eventId]);
        
        $response->getBody()->write(json_encode(['message' => 'Матчинг удален']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
