<?php

namespace App\Handlers;

use App\Config\Config;
use App\Database\Database;
use App\Models\User;
use App\Models\RegistrationPending;
use App\Models\EmailVerification;
use App\Models\PasswordReset;
use App\Services\EmailService;
use App\Utils\JWTUtil;
use App\Utils\Password;
use App\Utils\Validation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Ramsey\Uuid\Uuid;
use Psr\Log\LoggerInterface;

class AuthHandler
{
    private EmailService $emailService;
    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        $this->emailService = new EmailService($logger);
    }

    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['fullName']) || !isset($data['email']) || !isset($data['password']) || !isset($data['passwordConfirm'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if (!Validation::validateFullName($data['fullName'])) {
            $response->getBody()->write(json_encode(['error' => 'ФИО должно содержать только русские буквы']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if (!Validation::validateStringLength($data['fullName'], 2, 100)) {
            $response->getBody()->write(json_encode(['error' => 'ФИО должно быть от 2 до 100 символов']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if (!Validation::validateEmail($data['email'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат электронной почты']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if (!Validation::validatePassword($data['password'])) {
            $response->getBody()->write(json_encode(['error' => 'Пароль должен содержать минимум 8 символов, латинские буквы, цифры и специальные символы']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if ($data['password'] !== $data['passwordConfirm']) {
            $response->getBody()->write(json_encode(['error' => 'Пароли не совпадают']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status != ?");
        $stmt->execute([$data['email'], User::STATUS_DELETED]);
        if ($stmt->fetch()) {
            $response->getBody()->write(json_encode(['error' => 'Пользователь с такой почтой уже существует']));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }

        $hashedPassword = Password::hash($data['password']);
        $code = Validation::generateVerificationCode();
        $expiresAt = (new \DateTime())->modify('+10 minutes');

        $stmt = $db->prepare("DELETE FROM registration_pending WHERE email = ?");
        $stmt->execute([$data['email']]);

        $stmt = $db->prepare("INSERT INTO registration_pending (id, email, full_name, password_hash, code, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            Uuid::uuid4()->toString(),
            $data['email'],
            $data['fullName'],
            $hashedPassword,
            $code,
            $expiresAt->format('Y-m-d H:i:s'),
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);

        $this->emailService->sendVerificationCode($data['email'], $code);

        $response->getBody()->write(json_encode([
            'message' => 'Код подтверждения отправлен на вашу электронную почту. Пожалуйста, проверьте почту для завершения регистрации.',
            'email' => $data['email']
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function verifyEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['email']) || !isset($data['code'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if (!Validation::validateVerificationCode($data['code'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверный код подтверждения']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $db = Database::getConnection();
        $now = new \DateTime();
        
        $stmt = $db->prepare("SELECT * FROM registration_pending WHERE email = ? AND code = ? AND expires_at > ?");
        $stmt->execute([$data['email'], $data['code'], $now->format('Y-m-d H:i:s')]);
        $pending = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$pending) {
            $response->getBody()->write(json_encode(['error' => 'Неверный или истекший код подтверждения']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status != ?");
        $stmt->execute([$data['email'], User::STATUS_DELETED]);
        if ($stmt->fetch()) {
            $stmt = $db->prepare("DELETE FROM registration_pending WHERE email = ?");
            $stmt->execute([$data['email']]);
            $response->getBody()->write(json_encode(['error' => 'Пользователь с такой почтой уже существует']));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }

        $userId = Uuid::uuid4()->toString();
        $stmt = $db->prepare("INSERT INTO users (id, full_name, email, password, role, status, email_verified, auth_provider, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId,
            $pending['full_name'],
            $pending['email'],
            $pending['password_hash'],
            User::ROLE_USER,
            User::STATUS_ACTIVE,
            true,
            'email',
            (new \DateTime())->format('Y-m-d H:i:s'),
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);

        $stmt = $db->prepare("DELETE FROM registration_pending WHERE email = ?");
        $stmt->execute([$data['email']]);

        $this->emailService->sendWelcomeEmail($pending['email'], $pending['full_name']);

        $token = JWTUtil::generateToken($userId, $pending['email'], User::ROLE_USER);

        $response->getBody()->write(json_encode([
            'token' => $token,
            'user' => [
                'id' => $userId,
                'fullName' => $pending['full_name'],
                'email' => $pending['email'],
                'role' => User::ROLE_USER
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['email']) || !isset($data['password'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = ?");
        $stmt->execute([$data['email'], User::STATUS_ACTIVE]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$user || !Password::verify($data['password'], $user['password'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверный email или пароль']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $token = JWTUtil::generateToken($user['id'], $user['email'], $user['role']);

        $response->getBody()->write(json_encode([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'fullName' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode(['message' => 'Выход выполнен успешно']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function resendCode(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['email'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM registration_pending WHERE email = ?");
        $stmt->execute([$data['email']]);
        $pending = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$pending) {
            $response->getBody()->write(json_encode(['error' => 'Запрос на регистрацию не найден']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $code = Validation::generateVerificationCode();
        $expiresAt = (new \DateTime())->modify('+10 minutes');

        $stmt = $db->prepare("UPDATE registration_pending SET code = ?, expires_at = ? WHERE email = ?");
        $stmt->execute([$code, $expiresAt->format('Y-m-d H:i:s'), $data['email']]);

        $this->emailService->sendVerificationCode($data['email'], $code);

        $response->getBody()->write(json_encode(['message' => 'Новый код подтверждения отправлен']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function forgotPassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['email'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if (!Validation::validateEmail($data['email'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверный формат электронной почты']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$user) {
            $response->getBody()->write(json_encode(['message' => 'Если пользователь с такой почтой существует, письмо со ссылкой для сброса пароля отправлено на указанную почту']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $token = Validation::generateRandomToken();
        $expiresAt = (new \DateTime())->modify('+24 hours');

        $stmt = $db->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt->execute([$data['email']]);

        $stmt = $db->prepare("INSERT INTO password_resets (id, email, token, expires_at, used, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            Uuid::uuid4()->toString(),
            $data['email'],
            $token,
            $expiresAt->format('Y-m-d H:i:s'),
            false,
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);

        if (!$this->emailService->sendPasswordResetLink($data['email'], $token)) {
            $response->getBody()->write(json_encode(['error' => 'Ошибка отправки письма']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['message' => 'Если пользователь с такой почтой существует, письмо со ссылкой для сброса пароля отправлено на указанную почту']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function resetPassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['token']) || !isset($data['password']) || !isset($data['passwordConfirm'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if (!Validation::validatePassword($data['password'])) {
            $response->getBody()->write(json_encode(['error' => 'Пароль должен содержать минимум 8 символов, латинские буквы, цифры и специальные символы']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if ($data['password'] !== $data['passwordConfirm']) {
            $response->getBody()->write(json_encode(['error' => 'Пароли не совпадают']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $db = Database::getConnection();
        $now = new \DateTime();
        
        $stmt = $db->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > ? AND used = false");
        $stmt->execute([$data['token'], $now->format('Y-m-d H:i:s')]);
        $reset = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$reset) {
            $response->getBody()->write(json_encode(['error' => 'Неверный или истекший токен']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $hashedPassword = Password::hash($data['password']);
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->execute([$hashedPassword, $reset['email']]);

        $stmt = $db->prepare("UPDATE password_resets SET used = true WHERE token = ?");
        $stmt->execute([$data['token']]);

        $this->emailService->sendPasswordChangedNotification($reset['email']);

        $response->getBody()->write(json_encode(['message' => 'Пароль успешно изменен. Теперь вы можете войти в систему, используя новый пароль.']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function yandexAuth(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (Config::$fakeYandexAuth) {
            $response->getBody()->write(json_encode([
                'fake' => true,
                'message' => 'Используйте POST /api/auth/yandex/fake для фейковой авторизации'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        
        if (empty(Config::$yandexClientId)) {
            $response->getBody()->write(json_encode(['error' => 'Яндекс OAuth не настроен']));
            return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
        }
        
        $state = Validation::generateRandomToken();
        $authURL = sprintf(
            'https://oauth.yandex.ru/authorize?response_type=code&client_id=%s&redirect_uri=%s&state=%s',
            Config::$yandexClientId,
            urlencode(Config::$yandexRedirectUri),
            $state
        );
        
        $response->getBody()->write(json_encode([
            'authUrl' => $authURL,
            'state' => $state
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function yandexCallback(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $code = $params['code'] ?? null;
        
        if (!$code) {
            $response->getBody()->write(json_encode(['error' => 'Код авторизации не получен']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $token = $this->exchangeCodeForToken($code);
        if (!$token) {
            $response->getBody()->write(json_encode(['error' => 'Ошибка получения токена']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        $userInfo = $this->getYandexUserInfo($token);
        if (!$userInfo) {
            $response->getBody()->write(json_encode(['error' => 'Ошибка получения информации о пользователе']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        $email = $userInfo['default_email'] ?? ($userInfo['emails'][0] ?? ($userInfo['login'] . '@yandex.ru'));
        $fullName = $userInfo['display_name'] ?? ($userInfo['first_name'] . ' ' . $userInfo['last_name']) ?? $userInfo['login'];
        
        if (!Validation::validateFullName($fullName)) {
            $fullName = $userInfo['login'];
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE yandex_id = ? OR email = ?");
        $stmt->execute([$userInfo['id'], $email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$user) {
            $userId = Uuid::uuid4()->toString();
            $stmt = $db->prepare("INSERT INTO users (id, full_name, email, password, yandex_id, role, status, email_verified, auth_provider, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId,
                $fullName,
                $email,
                null,
                $userInfo['id'],
                User::ROLE_USER,
                User::STATUS_ACTIVE,
                true,
                'yandex',
                (new \DateTime())->format('Y-m-d H:i:s'),
                (new \DateTime())->format('Y-m-d H:i:s')
            ]);
            
            $this->emailService->sendWelcomeEmail($email, $fullName);
            
            $token = JWTUtil::generateToken($userId, $email, User::ROLE_USER);
            
            $response->getBody()->write(json_encode([
                'token' => $token,
                'user' => [
                    'id' => $userId,
                    'fullName' => $fullName,
                    'email' => $email,
                    'role' => User::ROLE_USER
                ]
            ]));
        } else {
            if (!$user['yandex_id']) {
                $stmt = $db->prepare("UPDATE users SET yandex_id = ?, auth_provider = ? WHERE id = ?");
                $stmt->execute([$userInfo['id'], 'yandex', $user['id']]);
            }
            
            $token = JWTUtil::generateToken($user['id'], $user['email'], $user['role']);
            
            $response->getBody()->write(json_encode([
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'fullName' => $user['full_name'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ]
            ]));
        }
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function exchangeCodeForToken(string $code): ?string
    {
        $data = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => Config::$yandexClientId,
            'client_secret' => Config::$yandexClientSecret
        ];
        
        $ch = curl_init('https://oauth.yandex.ru/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        $tokenData = json_decode($result, true);
        return $tokenData['access_token'] ?? null;
    }

    private function getYandexUserInfo(string $token): ?array
    {
        $ch = curl_init('https://login.yandex.ru/info');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: OAuth ' . $token]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        return json_decode($result, true);
    }

    public function fakeYandexAuth(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!Config::$fakeYandexAuth) {
            $response->getBody()->write(json_encode(['error' => 'Фейковая авторизация отключена']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
        
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['email']) || !isset($data['fullName'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $email = $data['email'];
        $fullName = $data['fullName'];
        $yandexId = $data['yandexId'] ?? 'fake_' . md5($email);
        
        if (!Validation::validateFullName($fullName)) {
            $fullName = explode('@', $email)[0];
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE yandex_id = ? OR email = ?");
        $stmt->execute([$yandexId, $email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$user) {
            $userId = Uuid::uuid4()->toString();
            $stmt = $db->prepare("INSERT INTO users (id, full_name, email, password, yandex_id, role, status, email_verified, auth_provider, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId,
                $fullName,
                $email,
                null,
                $yandexId,
                User::ROLE_USER,
                User::STATUS_ACTIVE,
                true,
                'yandex',
                (new \DateTime())->format('Y-m-d H:i:s'),
                (new \DateTime())->format('Y-m-d H:i:s')
            ]);
            
            $token = JWTUtil::generateToken($userId, $email, User::ROLE_USER);
            
            $response->getBody()->write(json_encode([
                'token' => $token,
                'user' => [
                    'id' => $userId,
                    'fullName' => $fullName,
                    'email' => $email,
                    'role' => User::ROLE_USER
                ]
            ]));
        } else {
            if (!$user['yandex_id']) {
                $stmt = $db->prepare("UPDATE users SET yandex_id = ?, auth_provider = ? WHERE id = ?");
                $stmt->execute([$yandexId, 'yandex', $user['id']]);
            }
            
            $token = JWTUtil::generateToken($user['id'], $user['email'], $user['role']);
            
            $response->getBody()->write(json_encode([
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'fullName' => $user['full_name'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ]
            ]));
        }
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function initDefaultAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
        $stmt->execute([User::ROLE_ADMIN]);
        $adminCount = (int)$stmt->fetchColumn();
        
        if ($adminCount > 0) {
            $response->getBody()->write(json_encode(['error' => 'Администраторы уже существуют. Используйте обычную авторизацию.']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
        
        $email = 'admin@system.local';
        $password = 'Admin123!';
        
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $existingUser = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($existingUser) {
            if ($existingUser['status'] === User::STATUS_DELETED) {
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$existingUser['id']]);
            } else {
                $response->getBody()->write(json_encode(['error' => 'Администратор с таким email уже существует']));
                return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
            }
        }

        $hashedPassword = Password::hash($password);
        $userId = Uuid::uuid4()->toString();
        
        $stmt = $db->prepare("INSERT INTO users (id, full_name, email, password, role, status, email_verified, auth_provider, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId,
            'Администратор',
            $email,
            $hashedPassword,
            User::ROLE_ADMIN,
            User::STATUS_ACTIVE,
            true,
            'email',
            (new \DateTime())->format('Y-m-d H:i:s'),
            (new \DateTime())->format('Y-m-d H:i:s')
        ]);

        $response->getBody()->write(json_encode([
            'message' => 'Администратор по умолчанию успешно создан',
            'email' => $email,
            'password' => $password,
            'warning' => 'Не забудьте изменить пароль по умолчанию!'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

