<?php

namespace App\Handlers;

use App\Database\Database;
use App\Models\Event;
use App\Utils\Validation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;
use Ramsey\Uuid\Uuid;

class EventHandler
{
    public function getEvents(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = Database::getConnection();
        $params = $request->getQueryParams();
        $userId = $request->getAttribute('userID');
        
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $tab = $params['tab'] ?? null;
        $statusFilter = $params['status'] ?? null;
        $search = $params['search'] ?? null;
        $categoryIDs = $params['categoryIDs'] ?? [];
        if (is_string($categoryIDs)) {
            $categoryIDs = [$categoryIDs];
        }
        $tags = $params['tags'] ?? [];
        if (is_string($tags)) {
            $tags = [$tags];
        }
        $dateFrom = $params['dateFrom'] ?? null;
        $dateTo = $params['dateTo'] ?? null;
        $sortBy = $params['sortBy'] ?? 'startDate';
        $sortOrder = strtoupper($params['sortOrder'] ?? 'ASC');
        if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
            $sortOrder = 'ASC';
        }
        
        $query = "SELECT DISTINCT e.*, u.full_name as organizer_name, u.email as organizer_email, u.id as organizer_user_id,
                  (SELECT COUNT(*) FROM event_participants ep WHERE ep.event_id = e.id) as participants_count
                  FROM events e
                  LEFT JOIN users u ON e.organizer_id = u.id
                  WHERE e.deleted_at IS NULL";
        
        $conditions = [];
        $bindings = [];
        
        switch ($tab) {
            case 'active':
                $conditions[] = "e.status = ?";
                $bindings[] = Event::STATUS_ACTIVE;
                break;
            case 'my':
                if ($userId) {
                    $conditions[] = "(e.organizer_id = ? OR e.id IN (SELECT event_id FROM event_participants WHERE user_id = ?))";
                    $bindings[] = $userId;
                    $bindings[] = $userId;
                    $conditions[] = "e.status IN (?, ?)";
                    $bindings[] = Event::STATUS_ACTIVE;
                    $bindings[] = Event::STATUS_PAST;
                } else {
                    $response->getBody()->write(json_encode(['error' => 'Для просмотра своих событий требуется авторизация']));
                    return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
                }
                break;
            case 'past':
                $conditions[] = "e.status = ?";
                $bindings[] = Event::STATUS_PAST;
                break;
            default:
                if (!$statusFilter) {
                    $conditions[] = "e.status != ?";
                    $bindings[] = Event::STATUS_REJECTED;
                }
        }
        
        if ($statusFilter) {
            $validStatuses = [Event::STATUS_ACTIVE, Event::STATUS_PAST, Event::STATUS_REJECTED];
            if (in_array($statusFilter, $validStatuses)) {
                $conditions[] = "e.status = ?";
                $bindings[] = $statusFilter;
            } else {
                $response->getBody()->write(json_encode(['error' => 'Неверный статус. Допустимые значения: Активное, Прошедшее, Отклоненное']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }
        
        if ($search) {
            if (!Validation::validateStringLength($search, 1, 200)) {
                $response->getBody()->write(json_encode(['error' => 'Поисковый запрос должен быть от 1 до 200 символов']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $conditions[] = "(e.title ILIKE ? OR e.short_description ILIKE ? OR e.full_description ILIKE ?)";
            $searchTerm = '%' . $search . '%';
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
        }
        
        if (!empty($categoryIDs)) {
            $validCategoryIDs = [];
            foreach ($categoryIDs as $catId) {
                if (Validation::validateUUID($catId)) {
                    $validCategoryIDs[] = $catId;
                }
            }
            if (!empty($validCategoryIDs)) {
                $placeholders = implode(',', array_fill(0, count($validCategoryIDs), '?'));
                $conditions[] = "e.id IN (SELECT event_id FROM event_categories WHERE category_id IN ($placeholders))";
                $bindings = array_merge($bindings, $validCategoryIDs);
            }
        }
        
        if (!empty($tags)) {
            $tagConditions = [];
            foreach ($tags as $tag) {
                $tagConditions[] = "? = ANY(e.tags)";
                $bindings[] = $tag;
            }
            if (!empty($tagConditions)) {
                $conditions[] = "(" . implode(" OR ", $tagConditions) . ")";
            }
        }
        
        if ($dateFrom) {
            $conditions[] = "DATE(e.start_date) >= ?";
            $bindings[] = $dateFrom;
        }
        
        if ($dateTo) {
            $conditions[] = "DATE(e.end_date) <= ?";
            $bindings[] = $dateTo;
        }
        
        if (!empty($conditions)) {
            $query .= " AND " . implode(" AND ", $conditions);
        }
        
        $countQuery = "SELECT COUNT(DISTINCT e.id) FROM events e WHERE e.deleted_at IS NULL" . 
                     (!empty($conditions) ? " AND " . implode(" AND ", $conditions) : "");
        
        $stmt = $db->prepare($countQuery);
        $stmt->execute($bindings);
        $total = (int)$stmt->fetchColumn();
        
        $orderBy = "e.start_date";
        switch ($sortBy) {
            case 'createdAt':
                $orderBy = "e.created_at";
                break;
            case 'participantsCount':
                $orderBy = "participants_count";
                break;
            default:
                $orderBy = "e.start_date";
        }
        
        $query .= " ORDER BY $orderBy $sortOrder LIMIT ? OFFSET ?";
        $bindings[] = $limit;
        $bindings[] = $offset;
        
        $stmt = $db->prepare($query);
        $stmt->execute($bindings);
        $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $db = Database::getConnection();
        $events = array_map(function($event) use ($db) {
            $stmt = $db->prepare("SELECT c.id, c.name, c.description FROM categories c JOIN event_categories ec ON c.id = ec.category_id WHERE ec.event_id = ?");
            $stmt->execute([$event['id']]);
            $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            return [
                'id' => $event['id'],
                'title' => $event['title'],
                'shortDescription' => $event['short_description'],
                'fullDescription' => $event['full_description'],
                'startDate' => $event['start_date'],
                'endDate' => $event['end_date'],
                'imageURL' => $event['image_url'],
                'paymentInfo' => $event['payment_info'],
                'maxParticipants' => $event['max_participants'] ? (int)$event['max_participants'] : null,
                'status' => $event['status'],
                'participantsCount' => (int)$event['participants_count'],
                'organizer' => [
                    'id' => $event['organizer_user_id'],
                    'fullName' => $event['organizer_name'],
                    'email' => $event['organizer_email']
                ],
                'categories' => array_map(fn($c) => [
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'description' => $c['description']
                ], $categories),
                'tags' => $event['tags'] ? json_decode($event['tags'], true) : [],
                'address' => $event['address'],
                'latitude' => $event['latitude'] ? (float)$event['latitude'] : null,
                'longitude' => $event['longitude'] ? (float)$event['longitude'] : null,
                'yandexMapLink' => $event['yandex_map_link'],
                'createdAt' => $event['created_at'],
                'updatedAt' => $event['updated_at']
            ];
        }, $events);
        
        $response->getBody()->write(json_encode([
            'data' => $events,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int)ceil($total / $limit)
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getEvent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = Database::getConnection();
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $eventId = $route->getArgument('id');
        
        $stmt = $db->prepare("SELECT e.*, u.full_name as organizer_name, u.email as organizer_email, u.id as organizer_user_id,
                             (SELECT COUNT(*) FROM event_participants ep WHERE ep.event_id = e.id) as participants_count
                             FROM events e
                             LEFT JOIN users u ON e.organizer_id = u.id
                             WHERE e.id = ? AND e.deleted_at IS NULL");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$event) {
            $response->getBody()->write(json_encode(['error' => 'Событие не найдено']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("SELECT c.id, c.name, c.description FROM categories c JOIN event_categories ec ON c.id = ec.category_id WHERE ec.event_id = ?");
        $stmt->execute([$eventId]);
        $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $userId = $request->getAttribute('userID');
        $isParticipant = false;
        if ($userId) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND user_id = ?");
            $stmt->execute([$eventId, $userId]);
            $isParticipant = $stmt->fetchColumn() > 0;
        }
        
        $stmt = $db->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM event_reviews WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $reviewStats = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $response->getBody()->write(json_encode([
            'id' => $event['id'],
            'title' => $event['title'],
            'shortDescription' => $event['short_description'],
            'fullDescription' => $event['full_description'],
            'startDate' => $event['start_date'],
            'endDate' => $event['end_date'],
            'imageURL' => $event['image_url'],
            'paymentInfo' => $event['payment_info'],
            'maxParticipants' => $event['max_participants'] ? (int)$event['max_participants'] : null,
            'status' => $event['status'],
            'participantsCount' => (int)$event['participants_count'],
            'isParticipant' => $isParticipant,
            'averageRating' => $reviewStats['avg_rating'] ? (float)$reviewStats['avg_rating'] : 0,
            'totalReviews' => (int)$reviewStats['total_reviews'],
            'organizer' => [
                'id' => $event['organizer_user_id'],
                'fullName' => $event['organizer_name'],
                'email' => $event['organizer_email']
            ],
            'categories' => array_map(fn($c) => [
                'id' => $c['id'],
                'name' => $c['name'],
                'description' => $c['description']
            ], $categories),
            'tags' => $event['tags'] ? json_decode($event['tags'], true) : [],
            'address' => $event['address'],
            'latitude' => $event['latitude'] ? (float)$event['latitude'] : null,
            'longitude' => $event['longitude'] ? (float)$event['longitude'] : null,
            'yandexMapLink' => $event['yandex_map_link'],
            'createdAt' => $event['created_at'],
            'updatedAt' => $event['updated_at']
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createEvent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $contentType = $request->getHeaderLine('Content-Type');
        $data = [];
        
        if (strpos($contentType, 'application/json') !== false) {
            $data = json_decode($request->getBody()->getContents(), true);
        } else {
            $parsedBody = $request->getParsedBody();
            if ($parsedBody) {
                $data = $parsedBody;
                if (isset($data['maxParticipants']) && is_string($data['maxParticipants'])) {
                    $data['maxParticipants'] = (int)$data['maxParticipants'];
                }
                if (isset($data['latitude']) && is_string($data['latitude'])) {
                    $data['latitude'] = (float)$data['latitude'];
                }
                if (isset($data['longitude']) && is_string($data['longitude'])) {
                    $data['longitude'] = (float)$data['longitude'];
                }
            } else {
                $body = $request->getBody()->getContents();
                if (!empty($body)) {
                    parse_str($body, $data);
                }
            }
        }
        
        if (!isset($data['title']) || !isset($data['fullDescription']) || !isset($data['startDate']) || !isset($data['endDate'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if (!Validation::validateStringLength($data['title'], 1, 200)) {
            $response->getBody()->write(json_encode(['error' => 'Название события должно быть от 1 до 200 символов']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $imageUrl = $data['imageURL'] ?? null;
        $uploadedFiles = $request->getUploadedFiles();
        if (isset($uploadedFiles['image'])) {
            $file = $uploadedFiles['image'];
            if ($file->getError() === UPLOAD_ERR_OK && $file->getSize() > 0 && $file->getSize() <= 10 * 1024 * 1024) {
                $filename = $file->getClientFilename();
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                if (in_array($ext, $allowedExts)) {
                    $uploadDir = __DIR__ . '/../../uploads';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $newFilename = Uuid::uuid4()->toString() . '_' . time() . '.' . $ext;
                    $filePath = $uploadDir . '/' . $newFilename;
                    $file->moveTo($filePath);
                    $imageUrl = '/uploads/' . $newFilename;
                }
            }
        }
        
        $db = Database::getConnection();
        $eventId = Uuid::uuid4()->toString();
        
        $tags = isset($data['tags']) && is_array($data['tags']) ? json_encode($data['tags']) : '[]';
        
        $stmt = $db->prepare("INSERT INTO events (id, title, short_description, full_description, start_date, end_date, image_url, payment_info, max_participants, status, organizer_id, tags, address, latitude, longitude, yandex_map_link, created_at, updated_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::text[], ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $eventId,
            $data['title'],
            $data['shortDescription'] ?? null,
            $data['fullDescription'],
            $data['startDate'],
            $data['endDate'],
            $imageUrl,
            $data['paymentInfo'] ?? null,
            $data['maxParticipants'] ?? null,
            Event::STATUS_ACTIVE,
            $userId,
            $tags,
            $data['address'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['yandexMapLink'] ?? null,
            (new \DateTime())->format('Y-m-d H:i:s'),
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);
        
        if (isset($data['categoryIDs']) && is_array($data['categoryIDs'])) {
            foreach ($data['categoryIDs'] as $categoryId) {
                if (Validation::validateUUID($categoryId)) {
                    $stmt = $db->prepare("INSERT INTO event_categories (event_id, category_id, created_at) VALUES (?, ?, ?)");
                    $stmt->execute([$eventId, $categoryId, (new \DateTime())->format('Y-m-d H:i:s')]);
                }
            }
        }
        
        $response->getBody()->write(json_encode(['id' => $eventId, 'message' => 'Событие создано']));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function updateEvent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $eventId = $route->getArgument('id');
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT organizer_id FROM events WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$event) {
            $response->getBody()->write(json_encode(['error' => 'Событие не найдено']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        if ($event['organizer_id'] !== $userId && $request->getAttribute('role') !== 'Администратор') {
            $response->getBody()->write(json_encode(['error' => 'Нет доступа']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
        
        $data = json_decode($request->getBody()->getContents(), true);
        $updates = [];
        $bindings = [];
        
        if (isset($data['title'])) {
            if (!Validation::validateStringLength($data['title'], 1, 200)) {
                $response->getBody()->write(json_encode(['error' => 'Название события должно быть от 1 до 200 символов']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $updates[] = "title = ?";
            $bindings[] = $data['title'];
        }
        
        if (isset($data['shortDescription'])) {
            if (!Validation::validateStringLength($data['shortDescription'], 1, 500)) {
                $response->getBody()->write(json_encode(['error' => 'Краткое описание должно быть от 1 до 500 символов']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $updates[] = "short_description = ?";
            $bindings[] = $data['shortDescription'];
        }
        
        if (isset($data['fullDescription'])) {
            if (!Validation::validateStringLength($data['fullDescription'], 1, 5000)) {
                $response->getBody()->write(json_encode(['error' => 'Полное описание должно быть от 1 до 5000 символов']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $updates[] = "full_description = ?";
            $bindings[] = $data['fullDescription'];
        }
        
        if (isset($data['startDate'])) {
            $startDate = new \DateTime($data['startDate']);
            if ($startDate < new \DateTime()) {
                $response->getBody()->write(json_encode(['error' => 'Дата начала должна быть в будущем']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $updates[] = "start_date = ?";
            $bindings[] = $startDate->format('Y-m-d H:i:s');
        }
        
        if (isset($data['endDate'])) {
            $updates[] = "end_date = ?";
            $bindings[] = (new \DateTime($data['endDate']))->format('Y-m-d H:i:s');
        }
        
        if (isset($data['imageURL'])) {
            $updates[] = "image_url = ?";
            $bindings[] = $data['imageURL'];
        }
        
        if (isset($data['paymentInfo'])) {
            if (!Validation::validateStringLength($data['paymentInfo'], 0, 2000)) {
                $response->getBody()->write(json_encode(['error' => 'Информация об оплате должна быть до 2000 символов']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $updates[] = "payment_info = ?";
            $bindings[] = $data['paymentInfo'];
        }
        
        if (isset($data['maxParticipants'])) {
            if ($data['maxParticipants'] < 1) {
                $response->getBody()->write(json_encode(['error' => 'Максимальное количество участников должно быть больше 0']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $updates[] = "max_participants = ?";
            $bindings[] = $data['maxParticipants'];
        }
        
        if (isset($data['status'])) {
            $validStatuses = [Event::STATUS_ACTIVE, Event::STATUS_PAST, Event::STATUS_REJECTED];
            if (!in_array($data['status'], $validStatuses)) {
                $response->getBody()->write(json_encode(['error' => 'Неверный статус. Допустимые значения: Активное, Прошедшее, Отклоненное']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $updates[] = "status = ?";
            $bindings[] = $data['status'];
        }
        
        if (isset($data['address'])) {
            if (!Validation::validateStringLength($data['address'], 0, 500)) {
                $response->getBody()->write(json_encode(['error' => 'Адрес должен быть до 500 символов']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $updates[] = "address = ?";
            $bindings[] = $data['address'];
        }
        
        if (isset($data['latitude'])) {
            if ($data['latitude'] < -90 || $data['latitude'] > 90) {
                $response->getBody()->write(json_encode(['error' => 'Широта должна быть от -90 до 90']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $updates[] = "latitude = ?";
            $bindings[] = $data['latitude'];
        }
        
        if (isset($data['longitude'])) {
            if ($data['longitude'] < -180 || $data['longitude'] > 180) {
                $response->getBody()->write(json_encode(['error' => 'Долгота должна быть от -180 до 180']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $updates[] = "longitude = ?";
            $bindings[] = $data['longitude'];
        }
        
        if (isset($data['yandexMapLink'])) {
            if (!Validation::validateStringLength($data['yandexMapLink'], 0, 1000)) {
                $response->getBody()->write(json_encode(['error' => 'Ссылка на карту должна быть до 1000 символов']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $updates[] = "yandex_map_link = ?";
            $bindings[] = $data['yandexMapLink'];
        }
        
        if (isset($data['tags']) && is_array($data['tags'])) {
            $updates[] = "tags = ?::text[]";
            $bindings[] = json_encode($data['tags']);
        }
        
        if (empty($updates)) {
            $response->getBody()->write(json_encode(['error' => 'Нет данных для обновления']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $updates[] = "updated_at = ?";
        $bindings[] = (new \DateTime())->format('Y-m-d H:i:s');
        $bindings[] = $eventId;
        
        $query = "UPDATE events SET " . implode(", ", $updates) . " WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute($bindings);
        
        if (isset($data['categoryIDs'])) {
            $stmt = $db->prepare("DELETE FROM event_categories WHERE event_id = ?");
            $stmt->execute([$eventId]);
            
            foreach ($data['categoryIDs'] as $categoryId) {
                $stmt = $db->prepare("INSERT INTO event_categories (event_id, category_id, created_at) VALUES (?, ?, ?)");
                $stmt->execute([$eventId, $categoryId, (new \DateTime())->format('Y-m-d H:i:s')]);
            }
        }
        
        $response->getBody()->write(json_encode(['message' => 'Событие обновлено']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function deleteEvent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $eventId = $route->getArgument('id');
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT organizer_id FROM events WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$event) {
            $response->getBody()->write(json_encode(['error' => 'Событие не найдено']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        if ($event['organizer_id'] !== $userId && $request->getAttribute('role') !== 'Администратор') {
            $response->getBody()->write(json_encode(['error' => 'Нет доступа']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("UPDATE events SET deleted_at = ? WHERE id = ?");
        $stmt->execute([(new \DateTime())->format('Y-m-d H:i:s'), $eventId]);
        
        $response->getBody()->write(json_encode(['message' => 'Событие удалено']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function joinEvent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $eventId = $route->getArgument('id');
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT * FROM events WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$event) {
            $response->getBody()->write(json_encode(['error' => 'Событие не найдено']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND user_id = ?");
        $stmt->execute([$eventId, $userId]);
        if ($stmt->fetchColumn() > 0) {
            $response->getBody()->write(json_encode(['error' => 'Вы уже участвуете в этом событии']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("INSERT INTO event_participants (id, event_id, user_id, created_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([Uuid::uuid4()->toString(), $eventId, $userId, (new \DateTime())->format('Y-m-d H:i:s')]);
        
        $response->getBody()->write(json_encode(['message' => 'Вы присоединились к событию']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function leaveEvent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $eventId = $route->getArgument('id');
        $db = Database::getConnection();
        
        $stmt = $db->prepare("DELETE FROM event_participants WHERE event_id = ? AND user_id = ?");
        $stmt->execute([$eventId, $userId]);
        
        $response->getBody()->write(json_encode(['message' => 'Вы покинули событие']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function exportParticipants(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
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
        $stmt = $db->prepare("SELECT organizer_id FROM events WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$event) {
            $response->getBody()->write(json_encode(['error' => 'Событие не найдено']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        if ($event['organizer_id'] !== $userId && $request->getAttribute('role') !== 'Администратор') {
            $response->getBody()->write(json_encode(['error' => 'Нет доступа']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $db->prepare("SELECT u.full_name, u.email, u.telegram, ep.created_at 
                              FROM event_participants ep 
                              LEFT JOIN users u ON ep.user_id = u.id 
                              WHERE ep.event_id = ? 
                              ORDER BY ep.created_at ASC");
        $stmt->execute([$eventId]);
        $participants = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $csv = "ФИО,Email,Telegram,Дата регистрации\n";
        foreach ($participants as $p) {
            $csv .= sprintf('"%s","%s","%s","%s"' . "\n",
                str_replace('"', '""', $p['full_name']),
                str_replace('"', '""', $p['email']),
                str_replace('"', '""', $p['telegram'] ?? ''),
                $p['created_at']
            );
        }
        
        $response = $response->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="participants_' . $eventId . '.csv"');
        $response->getBody()->write($csv);
        return $response;
    }
}

