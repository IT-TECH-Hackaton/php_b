<?php

namespace App\Handlers;

use App\Database\Database;
use App\Models\Event;
use App\Utils\Validation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Ramsey\Uuid\Uuid;

class ReviewHandler
{
    public function getEventReviews(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $eventId = $route->getArgument('id');
        
        if (!Validation::validateUUID($eventId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID события']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM event_reviews WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $total = (int)$stmt->fetchColumn();
        
        $stmt = $db->prepare("SELECT er.*, u.id as user_id, u.full_name, u.email 
                              FROM event_reviews er 
                              LEFT JOIN users u ON er.user_id = u.id 
                              WHERE er.event_id = ? 
                              ORDER BY er.created_at DESC 
                              LIMIT ? OFFSET ?");
        $stmt->execute([$eventId, $limit, $offset]);
        $reviews = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $stmt = $db->prepare("SELECT AVG(rating) as avg_rating FROM event_reviews WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $avgResult = $stmt->fetch(\PDO::FETCH_ASSOC);
        $avgRating = $avgResult['avg_rating'] ? (float)$avgResult['avg_rating'] : 0;
        
        $result = array_map(function($review) {
            return [
                'id' => $review['id'],
                'eventID' => $review['event_id'],
                'userID' => $review['user_id'],
                'rating' => (int)$review['rating'],
                'comment' => $review['comment'],
                'user' => [
                    'id' => $review['user_id'],
                    'fullName' => $review['full_name'],
                    'email' => $review['email']
                ],
                'createdAt' => $review['created_at'],
                'updatedAt' => $review['updated_at']
            ];
        }, $reviews);
        
        $response->getBody()->write(json_encode([
            'data' => $result,
            'averageRating' => $avgRating,
            'totalReviews' => $total,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int)ceil($total / $limit)
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createReview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
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
        
        $stmt = $db->prepare("SELECT status FROM events WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$event) {
            $response->getBody()->write(json_encode(['error' => 'Событие не найдено']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        if ($event['status'] !== Event::STATUS_PAST) {
            $response->getBody()->write(json_encode(['error' => 'Отзыв можно оставить только для прошедших событий']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND user_id = ?");
        $stmt->execute([$eventId, $userId]);
        if ($stmt->fetchColumn() == 0) {
            $response->getBody()->write(json_encode(['error' => 'Вы не участвовали в этом событии']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM event_reviews WHERE event_id = ? AND user_id = ?");
        $stmt->execute([$eventId, $userId]);
        if ($stmt->fetchColumn() > 0) {
            $response->getBody()->write(json_encode(['error' => 'Вы уже оставили отзыв на это событие']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['rating']) || $data['rating'] < 1 || $data['rating'] > 5) {
            $response->getBody()->write(json_encode(['error' => 'Рейтинг должен быть от 1 до 5']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if (isset($data['comment']) && !Validation::validateStringLength($data['comment'], 0, 2000)) {
            $response->getBody()->write(json_encode(['error' => 'Комментарий должен быть до 2000 символов']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $reviewId = Uuid::uuid4()->toString();
        $stmt = $db->prepare("INSERT INTO event_reviews (id, event_id, user_id, rating, comment, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $reviewId,
            $eventId,
            $userId,
            $data['rating'],
            $data['comment'] ?? null,
            (new \DateTime())->format('Y-m-d H:i:s'),
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);
        
        $stmt = $db->prepare("SELECT er.*, u.id as user_id, u.full_name FROM event_reviews er LEFT JOIN users u ON er.user_id = u.id WHERE er.id = ?");
        $stmt->execute([$reviewId]);
        $review = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $response->getBody()->write(json_encode([
            'id' => $review['id'],
            'rating' => (int)$review['rating'],
            'comment' => $review['comment'],
            'user' => [
                'id' => $review['user_id'],
                'fullName' => $review['full_name']
            ],
            'createdAt' => $review['created_at']
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function updateReview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $reviewId = $route->getArgument('reviewId');
        
        if (!Validation::validateUUID($reviewId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID отзыва']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM event_reviews WHERE id = ? AND user_id = ?");
        $stmt->execute([$reviewId, $userId]);
        $review = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$review) {
            $response->getBody()->write(json_encode(['error' => 'Отзыв не найден']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['rating']) || $data['rating'] < 1 || $data['rating'] > 5) {
            $response->getBody()->write(json_encode(['error' => 'Рейтинг должен быть от 1 до 5']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if (isset($data['comment']) && !Validation::validateStringLength($data['comment'], 0, 2000)) {
            $response->getBody()->write(json_encode(['error' => 'Комментарий должен быть до 2000 символов']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("UPDATE event_reviews SET rating = ?, comment = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([
            $data['rating'],
            $data['comment'] ?? null,
            (new \DateTime())->format('Y-m-d H:i:s'),
            $reviewId
        ]);
        
        $response->getBody()->write(json_encode(['message' => 'Отзыв обновлен']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function deleteReview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $reviewId = $route->getArgument('reviewId');
        
        if (!Validation::validateUUID($reviewId)) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат ID отзыва']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM event_reviews WHERE id = ? AND user_id = ?");
        $stmt->execute([$reviewId, $userId]);
        if (!$stmt->fetch()) {
            $response->getBody()->write(json_encode(['error' => 'Отзыв не найден']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("DELETE FROM event_reviews WHERE id = ?");
        $stmt->execute([$reviewId]);
        
        $response->getBody()->write(json_encode(['message' => 'Отзыв удален']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
