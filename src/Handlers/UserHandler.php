<?php

namespace App\Handlers;

use App\Database\Database;
use App\Utils\Password;
use App\Utils\Validation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class UserHandler
{
    public function getProfile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, full_name, email, telegram, avatar_url, role, status, email_verified, auth_provider, created_at, updated_at FROM users WHERE id = ?");
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

    public function updateProfile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('userID');
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'Требуется авторизация']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        $data = json_decode($request->getBody()->getContents(), true);
        $db = Database::getConnection();
        
        $updates = [];
        $bindings = [];
        
        if (isset($data['fullName'])) {
            if (!Validation::validateFullName($data['fullName'])) {
                $response->getBody()->write(json_encode(['error' => 'ФИО должно содержать только русские буквы']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $updates[] = "full_name = ?";
            $bindings[] = $data['fullName'];
        }
        
        if (isset($data['telegram'])) {
            $updates[] = "telegram = ?";
            $bindings[] = $data['telegram'];
        }
        
        if (isset($data['avatarURL'])) {
            $updates[] = "avatar_url = ?";
            $bindings[] = $data['avatarURL'];
        }
        
        if (empty($updates)) {
            $response->getBody()->write(json_encode(['error' => 'Нет данных для обновления']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $updates[] = "updated_at = ?";
        $bindings[] = (new \DateTime())->format('Y-m-d H:i:s');
        $bindings[] = $userId;
        
        $query = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute($bindings);
        
        $response->getBody()->write(json_encode(['message' => 'Профиль обновлен']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}


