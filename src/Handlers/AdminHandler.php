<?php

namespace App\Handlers;

use App\Database\Database;
use App\Models\User;
use App\Services\EmailService;
use App\Utils\Password;
use App\Utils\Validation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Ramsey\Uuid\Uuid;

class AdminHandler
{
    private EmailService $emailService;

    public function __construct()
    {
        $this->emailService = new EmailService();
    }
    public function getUsers(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = Database::getConnection();
        $params = $request->getQueryParams();
        
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $query = "SELECT id, full_name, email, telegram, avatar_url, role, status, email_verified, auth_provider, created_at, updated_at FROM users WHERE deleted_at IS NULL";
        $bindings = [];
        
        if (isset($params['status'])) {
            $query .= " AND status = ?";
            $bindings[] = $params['status'];
        }
        
        $countQuery = "SELECT COUNT(*) FROM users WHERE deleted_at IS NULL" . 
                     (isset($params['status']) ? " AND status = ?" : "");
        
        $stmt = $db->prepare($countQuery);
        $stmt->execute($bindings);
        $total = (int)$stmt->fetchColumn();
        
        $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $bindings[] = $limit;
        $bindings[] = $offset;
        
        $stmt = $db->prepare($query);
        $stmt->execute($bindings);
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $users = array_map(function($user) {
            return [
                'id' => $user['id'],
                'fullName' => $user['full_name'],
                'email' => $user['email'],
                'telegram' => $user['telegram'],
                'avatarURL' => $user['avatar_url'],
                'role' => $user['role'],
                'status' => $user['status'],
                'emailVerified' => (bool)$user['email_verified'],
                'authProvider' => $user['auth_provider'],
                'createdAt' => $user['created_at'],
                'updatedAt' => $user['updated_at']
            ];
        }, $users);
        
        $response->getBody()->write(json_encode([
            'data' => $users,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int)ceil($total / $limit)
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['email']) || !isset($data['fullName'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if (!Validation::validateEmail($data['email'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат email']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if (isset($data['password']) && !Validation::validatePassword($data['password'])) {
            $response->getBody()->write(json_encode(['error' => 'Пароль должен содержать минимум 8 символов, латинские буквы, цифры и специальные символы']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND deleted_at IS NULL");
        $stmt->execute([$data['email']]);
        if ($stmt->fetchColumn() > 0) {
            $response->getBody()->write(json_encode(['error' => 'Пользователь с таким email уже существует']));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
        
        $userId = Uuid::uuid4()->toString();
        
        $password = isset($data['password']) ? Password::hash($data['password']) : null;
        
        $stmt = $db->prepare("INSERT INTO users (id, full_name, email, password, role, status, email_verified, auth_provider, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId,
            $data['fullName'],
            $data['email'],
            $password,
            $data['role'] ?? User::ROLE_USER,
            $data['status'] ?? User::STATUS_ACTIVE,
            $data['emailVerified'] ?? true,
            $data['authProvider'] ?? 'email',
            (new \DateTime())->format('Y-m-d H:i:s'),
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);
        
        if (isset($data['password'])) {
            $this->emailService->sendPasswordToUser($data['email'], $data['fullName'], $data['password']);
        }
        
        $response->getBody()->write(json_encode(['id' => $userId, 'message' => 'Пользователь создан']));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function getUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $userId = $route->getArgument('id');
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, full_name, email, telegram, avatar_url, role, status, email_verified, auth_provider, created_at, updated_at FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$user) {
            $response->getBody()->write(json_encode(['error' => 'Пользователь не найден']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write(json_encode([
            'id' => $user['id'],
            'fullName' => $user['full_name'],
            'email' => $user['email'],
            'telegram' => $user['telegram'],
            'avatarURL' => $user['avatar_url'],
            'role' => $user['role'],
            'status' => $user['status'],
            'emailVerified' => (bool)$user['email_verified'],
            'authProvider' => $user['auth_provider'],
            'createdAt' => $user['created_at'],
            'updatedAt' => $user['updated_at']
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function updateUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        $db = Database::getConnection();
        
        $updates = [];
        $bindings = [];
        
        $allowedFields = ['full_name', 'email', 'telegram', 'avatar_url', 'role', 'status', 'email_verified', 'auth_provider'];
        
        foreach ($allowedFields as $field) {
            $camelField = lcfirst(str_replace('_', '', ucwords($field, '_')));
            if (isset($data[$camelField])) {
                $updates[] = "$field = ?";
                $bindings[] = $data[$camelField];
            }
        }
        
        if (empty($updates)) {
            $response->getBody()->write(json_encode(['error' => 'Нет данных для обновления']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $userId = $route->getArgument('id');
        $updates[] = "updated_at = ?";
        $bindings[] = (new \DateTime())->format('Y-m-d H:i:s');
        $bindings[] = $userId;
        
        $query = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute($bindings);
        
        $response->getBody()->write(json_encode(['message' => 'Пользователь обновлен']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function deleteUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $userId = $route->getArgument('id');
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE users SET status = ?, deleted_at = ? WHERE id = ?");
        $stmt->execute([User::STATUS_DELETED, (new \DateTime())->format('Y-m-d H:i:s'), $userId]);
        
        $response->getBody()->write(json_encode(['message' => 'Пользователь удален']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function resetUserPassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['password'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if (!Validation::validatePassword($data['password'])) {
            $response->getBody()->write(json_encode(['error' => 'Пароль должен содержать минимум 8 символов, латинские буквы, цифры и специальные символы']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $routeContext = \Slim\Routing\RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $userId = $route->getArgument('id');
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$user) {
            $response->getBody()->write(json_encode(['error' => 'Пользователь не найден']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $hashedPassword = Password::hash($data['password']);
        
        $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, (new \DateTime())->format('Y-m-d H:i:s'), $userId]);
        
        $this->emailService->sendPasswordToUser($user['email'], $user['full_name'], $data['password']);
        
        $response->getBody()->write(json_encode(['message' => 'Пароль успешно изменен и отправлен на почту пользователя']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function exportUsers(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT full_name, email, telegram, role, status, email_verified, auth_provider, created_at FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC");
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $csv = "ФИО,Email,Telegram,Роль,Статус,Email подтвержден,Провайдер,Дата регистрации\n";
        foreach ($users as $user) {
            $csv .= sprintf('"%s","%s","%s","%s","%s","%s","%s","%s"' . "\n",
                str_replace('"', '""', $user['full_name']),
                str_replace('"', '""', $user['email']),
                str_replace('"', '""', $user['telegram'] ?? ''),
                str_replace('"', '""', $user['role']),
                str_replace('"', '""', $user['status']),
                $user['email_verified'] ? 'Да' : 'Нет',
                str_replace('"', '""', $user['auth_provider']),
                $user['created_at']
            );
        }
        
        $response = $response->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="users_export_' . date('Y-m-d') . '.csv"');
        $response->getBody()->write($csv);
        return $response;
    }

    public function getAdminEvents(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = Database::getConnection();
        $params = $request->getQueryParams();
        
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $query = "SELECT e.*, u.full_name as organizer_name FROM events e LEFT JOIN users u ON e.organizer_id = u.id WHERE e.deleted_at IS NULL";
        $bindings = [];
        
        if (isset($params['status'])) {
            $query .= " AND e.status = ?";
            $bindings[] = $params['status'];
        }
        
        $countQuery = "SELECT COUNT(*) FROM events WHERE deleted_at IS NULL" . 
                     (isset($params['status']) ? " AND status = ?" : "");
        
        $stmt = $db->prepare($countQuery);
        $stmt->execute($bindings);
        $total = (int)$stmt->fetchColumn();
        
        $query .= " ORDER BY e.created_at DESC LIMIT ? OFFSET ?";
        $bindings[] = $limit;
        $bindings[] = $offset;
        
        $stmt = $db->prepare($query);
        $stmt->execute($bindings);
        $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
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
}

